<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/webpush.php';   // derToJoseSignature()
require_once __DIR__ . '/notify.php';    // settingWrite()

// ═══════════════════════════════════════════════════════════════════════════
//  APPLE PUSH, FOR THE NATIVE OPERATOR APP
//
//  A second TRANSPORT, not a second push system. Everything that decides WHO
//  gets alerted — radius, payout floor, quiet hours, equipment matching, the
//  fan-out cap — already lives in webpush.php and is untouched by this file.
//  All that changes is how the bytes reach one device.
//
//  That split matters more than it looks. The targeting rules are the part with
//  business meaning ("do not wake a wheel-lift operator at 3am for a heavy
//  wreck 40 miles away"), and having two copies of them that drift apart is how
//  the app and the browser start disagreeing about who should have been called.
// ═══════════════════════════════════════════════════════════════════════════

const APNS_HOST_PROD    = 'https://api.push.apple.com';
const APNS_HOST_SANDBOX = 'https://api.sandbox.push.apple.com';

function apnsConfigured(): bool {
    return trim((string)setting('apns_private_key', '')) !== ''
        && trim((string)setting('apns_key_id', '')) !== ''
        && trim((string)setting('apns_team_id', '')) !== '';
}

/**
 * The provider token.
 *
 * Apple rejects a token younger than 20 minutes if you keep minting new ones,
 * and refuses anything older than 60 — so it is generated once and reused for
 * most of an hour. Cached in platform_settings rather than in memory because
 * PHP here is one process per request; an in-memory cache would mean a fresh
 * JWT on every single notification, which is exactly the behaviour Apple
 * answers with TooManyProviderTokenUpdates.
 */
function apnsProviderToken(): string {
    $cached = json_decode((string)setting('apns_token_cache', ''), true);
    if (is_array($cached) && ($cached['exp'] ?? 0) > time() + 300
        && ($cached['kid'] ?? '') === (string)setting('apns_key_id', '')) {
        return (string)$cached['jwt'];
    }

    $keyId  = trim((string)setting('apns_key_id', ''));
    $teamId = trim((string)setting('apns_team_id', ''));
    $pem    = trim((string)setting('apns_private_key', ''));
    if ($keyId === '' || $teamId === '' || $pem === '') {
        throw new RuntimeException('APNs is not configured');
    }

    $priv = openssl_pkey_get_private($pem);
    if (!$priv) throw new RuntimeException('APNs key rejected: ' . openssl_error_string());

    $now    = time();
    $header = base64url_encode(json_encode(['alg' => 'ES256', 'kid' => $keyId]));
    $claims = base64url_encode(json_encode(['iss' => $teamId, 'iat' => $now]));

    if (!openssl_sign("$header.$claims", $der, $priv, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('APNs token could not be signed');
    }
    // OpenSSL gives DER; JWS ES256 wants bare r||s. Same conversion VAPID needs,
    // and the same miserable failure if skipped: a well-formed-looking token
    // rejected with a bare 403.
    $jwt = "$header.$claims." . base64url_encode(derToJoseSignature($der));

    settingWrite('apns_token_cache', json_encode([
        'jwt' => $jwt, 'exp' => $now + 2400, 'kid' => $keyId,
    ]));
    return $jwt;
}

/**
 * Which Apple host this ONE device belongs to.
 *
 * Not a global setting, because both kinds exist at the same time. A build run
 * from Xcode onto a phone gets a SANDBOX token; TestFlight and the App Store
 * get production ones. Send a sandbox token to the production host and Apple
 * answers BadDeviceToken — which looks exactly like "push is broken" and is the
 * commonest reason a working implementation appears to do nothing on the one
 * device the developer is actually holding.
 *
 * The app reports which it has at registration; this just honours it.
 */
function apnsHostFor(array $sub): string {
    $env = $sub['apns_env'] ?? setting('apns_environment', 'production');
    return $env === 'sandbox' ? APNS_HOST_SANDBOX : APNS_HOST_PROD;
}

/**
 * One curl handle for one device, shaped so webPushSendMany() can put it in the
 * same curl_multi pool as the browser sends.
 *
 * $sub['endpoint'] holds the hex device token for an APNs row.
 */
function apnsHandle(array $sub, array $payload, ?string &$error = null) {
    try {
        $jwt = apnsProviderToken();
    } catch (Throwable $e) {
        $error = $e->getMessage();
        return null;
    }

    $token = preg_replace('/[^0-9a-fA-F]/', '', (string)$sub['endpoint']);
    if ($token === '') { $error = 'empty device token'; return null; }

    $title = (string)($payload['title'] ?? 'New job');
    $body  = (string)($payload['body'] ?? '');

    // The alarm tone, not the default two-note blip.
    //
    // A tow request is worth about twenty minutes and a driver may be under a
    // truck when it lands, so the sound has to last long enough to be noticed.
    // alarm_tone.wav is 27.7 seconds — Apple's hard ceiling for a notification
    // sound is 30, and a file over that is silently ignored in favour of the
    // default, so this is as long as iOS will allow.
    //
    // The file must be in the app bundle under exactly this name. If it is
    // missing iOS falls back to the default sound rather than going silent, so
    // an older build still alerts, just briefly.
    $soundName = (string)setting('apns_sound', 'alarm_tone.wav');
    $sound = ($soundName === '' || $soundName === 'default')
        ? 'default'
        // The dictionary form rather than a bare string, so the volume is ours
        // to set. 'critical' stays 0 — a critical alert overrides the ringer
        // switch and Do Not Disturb, and Apple only grants that entitlement on
        // application.
        : ['critical' => 0, 'name' => $soundName, 'volume' => 1.0];

    $aps = [
        'aps' => [
            'alert' => ['title' => $title, 'body' => $body],
            'sound' => $sound,
            // The badge is the count of jobs waiting, when the caller knows it.
            // Left off entirely rather than sent as 0, which would CLEAR a badge
            // the driver has not dealt with yet.
            'interruption-level' => 'time-sensitive',
        ],
    ];
    if (isset($payload['badge'])) $aps['aps']['badge'] = (int)$payload['badge'];
    // Everything the app needs to open straight to the job.
    foreach (['call_id', 'url', 'kind'] as $k) {
        if (isset($payload[$k])) $aps[$k] = $payload[$k];
    }

    $json = json_encode($aps);

    $ch = curl_init(apnsHostFor($sub) . '/3/device/' . $token);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        // HTTP/2 is not optional here — APNs speaks nothing else.
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER     => [
            'authorization: bearer ' . $jwt,
            // The topic is the BUNDLE ID, not the app name. One APNs key serves
            // every app on the team, so this is the only thing aiming it.
            'apns-topic: ' . (string)setting('apns_bundle_id', 'com.towsling.operator'),
            'apns-push-type: alert',
            // 10 = deliver now. A tow request is worth about twenty minutes;
            // a notification batched for a convenient moment is a missed job.
            'apns-priority: 10',
            'apns-expiration: ' . (time() + (int)setting('push_ttl_seconds', 900)),
            'content-type: application/json',
        ],
    ]);
    return $ch;
}

/**
 * Did Apple say this device is finished with?
 *
 * 410 Unregistered is the app being deleted. 400 BadDeviceToken is a token from
 * the wrong environment — a sandbox (development-build) token sent to the
 * production host, which is the single commonest way this goes wrong and looks
 * identical to "push is broken" from the outside.
 */
function apnsIsGone(int $code, string $body): bool {
    if ($code === 410) return true;
    $reason = json_decode($body, true)['reason'] ?? '';
    return in_array($reason, ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'], true);
}
