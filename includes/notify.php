<?php
require_once __DIR__ . '/helpers.php';

// ═══════════════════════════════════════════════════════════════════════════
//  OUTBOUND SMS + EMAIL
//
//  Two senders, one shape. Both return ['ok' => bool, 'error' => string|null]
//  and neither ever throws — a verification screen that dies with a 500 because
//  a carrier was slow tells the operator nothing and loses the attempt.
//
//  Everything here is configured from platform_settings rather than a config
//  file, so credentials can be filled in from the admin panel without a deploy.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Is SMS actually configured? The verification screens ask before offering the
 * phone step, because presenting a "Send code" button that cannot possibly
 * send is worse than saying the channel is not ready yet.
 */
function smsConfigured(): bool {
    $from = trim((string)setting('rc_from_number', ''));
    $auth = trim((string)setting('rc_jwt', '')) !== ''
            || (trim((string)setting('rc_client_id', '')) !== ''
                && trim((string)setting('rc_client_secret', '')) !== '');
    return $from !== '' && $auth;
}

/**
 * A RingCentral access token, cached in platform_settings until shortly before
 * it expires.
 *
 * Tokens last an hour. Fetching a new one on every text would work, but
 * RingCentral rate-limits the auth endpoint far more tightly than the send
 * endpoint, so a busy hour would start failing on login rather than on sending
 * — and the error it returns for that looks nothing like "too many texts".
 */
function rcAccessToken(): ?string {
    $cached = (string)setting('rc_token_cache', '');
    $expiry = (int)setting('rc_token_expires', 0);
    // 120s of headroom so a token cannot expire between here and the send.
    if ($cached !== '' && $expiry > time() + 120) return $cached;

    $server = rtrim((string)setting('rc_server_url', 'https://platform.ringcentral.com'), '/');
    $id     = trim((string)setting('rc_client_id', ''));
    $secret = trim((string)setting('rc_client_secret', ''));
    $jwt    = trim((string)setting('rc_jwt', ''));

    if ($jwt === '' || $id === '' || $secret === '') return null;

    $ch = curl_init($server . '/restapi/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERPWD        => $id . ':' . $secret,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        error_log('[notify] RingCentral auth failed HTTP ' . $code . ' ' . substr((string)$body, 0, 400));
        return null;
    }
    $j = json_decode((string)$body, true);
    if (empty($j['access_token'])) return null;

    $ttl = (int)($j['expires_in'] ?? 3600);
    settingWrite('rc_token_cache', $j['access_token']);
    settingWrite('rc_token_expires', (string)(time() + $ttl));
    return $j['access_token'];
}

/** Write a setting and keep setting()'s in-request cache honest. */
function settingWrite(string $key, string $value): void {
    getDB()->prepare(
        "INSERT INTO platform_settings (setting_key, setting_value)
         VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    )->execute([':k' => $key, ':v' => $value]);
}

/**
 * Send one SMS. `$to` must already be E.164 (normalizePhone gives that).
 */
function sendSms(string $to, string $text): array {
    if (!smsConfigured()) {
        return ['ok' => false, 'error' => 'sms_not_configured'];
    }
    $token = rcAccessToken();
    if ($token === null) {
        return ['ok' => false, 'error' => 'sms_auth_failed'];
    }

    $server = rtrim((string)setting('rc_server_url', 'https://platform.ringcentral.com'), '/');
    $from   = trim((string)setting('rc_from_number', ''));

    $ch = curl_init($server . '/restapi/v1.0/account/~/extension/~/sms');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'from' => ['phoneNumber' => $from],
            'to'   => [['phoneNumber' => $to]],
            'text' => $text,
        ]),
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 || $code === 201) return ['ok' => true, 'error' => null];

    // A 401 here means the cached token was rejected even though it had not
    // expired — revoked, or the credentials were changed underneath us. Drop it
    // so the next attempt re-authenticates instead of replaying a dead token
    // for the rest of the hour.
    if ($code === 401) settingWrite('rc_token_expires', '0');

    error_log('[notify] RingCentral send failed HTTP ' . $code . ' ' . substr((string)$body, 0, 400));
    return ['ok' => false, 'error' => 'sms_send_failed'];
}

/**
 * Send one email.
 *
 * PHP's mail() hands off to the local MTA. On this host that is a real mail
 * server with the domain's own DNS behind it, which is the difference between
 * a code arriving and a code landing in spam — but only if SPF and DKIM are
 * published for towsling.com. If verification emails go missing, check those
 * before looking at anything in this file.
 */
function sendMail(string $to, string $subject, string $textBody): array {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'bad_address'];
    }

    $fromAddr = (string)setting('mail_from', 'no-reply@towsling.com');
    $fromName = (string)setting('mail_from_name', 'TowSling');

    // Encoded because the display name is operator-editable and a raw newline
    // in a header is a header-injection hole, not a formatting glitch.
    $fromHeader = '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromAddr . '>';

    $headers = [
        'From: ' . $fromHeader,
        'Reply-To: ' . $fromAddr,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: TowSling',
        // Without an explicit Auto-Submitted an out-of-office reply can bounce
        // straight back into the no-reply mailbox forever.
        'Auto-Submitted: auto-generated',
    ];

    $subjectHeader = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $ok = @mail(
        $to,
        $subjectHeader,
        // RFC 5322 wants CRLF, and some MTAs silently truncate on bare LF.
        str_replace(["\r\n", "\n"], "\r\n", $textBody),
        implode("\r\n", $headers),
        '-f' . $fromAddr
    );

    if (!$ok) {
        error_log('[notify] mail() returned false for ' . $to);
        return ['ok' => false, 'error' => 'mail_send_failed'];
    }
    return ['ok' => true, 'error' => null];
}
