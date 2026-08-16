<?php
require_once __DIR__ . '/helpers.php';
// towerIsCapable() lives in matching.php, consumerFee() in pricing.php. The
// rule for who may see a job has exactly one definition and this reuses it
// rather than growing a second, quietly diverging copy.
require_once __DIR__ . '/matching.php';

// ═══════════════════════════════════════════════════════════════════════════
//  WEB PUSH — RFC 8030 (delivery), RFC 8291 (payload encryption), RFC 8292 (VAPID)
//
//  Written against the RFCs with no Composer dependency, because the rest of
//  this codebase has none and adding a package manager to a DreamHost shared
//  account for one feature is a support burden later.
//
//  Correctness here is not a matter of opinion: the payload encryption has a
//  published test vector in RFC 8291 §5, and encryptPayload() reproduces it
//  byte for byte in the test suite. If a change breaks it, the test fails
//  rather than the push silently arriving empty on a driver's phone.
//
//  What actually happens when a job is posted:
//    1. Sign a short-lived ES256 JWT proving we are the same sender that
//       registered this subscription       (VAPID)
//    2. Encrypt the job summary to the device's own public key, so Apple and
//       Google forward bytes they cannot read   (aes128gcm)
//    3. POST it to the endpoint the device gave us; APNs or FCM wakes the
//       phone even with the app closed
// ═══════════════════════════════════════════════════════════════════════════

const P256_OID_DER   = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
const SPKI_P256_HEAD = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
                     . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00";

// ─── Key plumbing ───────────────────────────────────────────────────────────

/**
 * Wrap a raw 65-byte uncompressed P-256 point (0x04 || X || Y) — which is what
 * a browser hands us as `p256dh` — in the SubjectPublicKeyInfo DER that
 * OpenSSL will accept.
 */
function p256PublicPem(string $raw65): string {
    if (strlen($raw65) !== 65 || $raw65[0] !== "\x04") {
        throw new RuntimeException('Expected a 65-byte uncompressed P-256 point');
    }
    return "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode(SPKI_P256_HEAD . $raw65), 64, "\n")
         . "-----END PUBLIC KEY-----\n";
}

/**
 * Build an EC private key PEM from a raw 32-byte scalar. Needed to pin the
 * ephemeral key to the RFC's value when checking against the test vector —
 * without it the encryption is unverifiable, since every real send is
 * randomised by design.
 */
function p256PrivatePem(string $d32, string $pub65): string {
    if (strlen($d32) !== 32) throw new RuntimeException('P-256 scalar must be 32 bytes');
    $der = "\x02\x01\x01"                        // version 1
         . "\x04\x20" . $d32                     // privateKey OCTET STRING
         . "\xa0\x0a" . P256_OID_DER              // [0] namedCurve
         . "\xa1\x44\x03\x42\x00" . $pub65;       // [1] publicKey BIT STRING
    $der = "\x30" . chr(strlen($der)) . $der;
    return "-----BEGIN EC PRIVATE KEY-----\n"
         . chunk_split(base64_encode($der), 64, "\n")
         . "-----END EC PRIVATE KEY-----\n";
}

/** Raw 65-byte public point out of any OpenSSL EC key. */
function p256RawPublic($key): string {
    $d = openssl_pkey_get_details($key);
    if (empty($d['ec']['x']) || empty($d['ec']['y'])) {
        throw new RuntimeException('Not an EC key');
    }
    return "\x04" . str_pad($d['ec']['x'], 32, "\x00", STR_PAD_LEFT)
                  . str_pad($d['ec']['y'], 32, "\x00", STR_PAD_LEFT);
}

/**
 * OpenSSL signs ECDSA as a DER SEQUENCE of two INTEGERs. JWS ES256 wants the
 * bare r||s, 32 bytes each, left-padded. Getting this wrong produces a JWT that
 * looks perfectly well-formed and is rejected with a bare 401 by every push
 * service, which is a miserable thing to debug.
 */
function derToJoseSignature(string $der): string {
    $off = 0;
    if (($der[$off++] ?? '') !== "\x30") throw new RuntimeException('Bad DER signature');
    $len = ord($der[$off++]);
    if ($len & 0x80) $off += ($len & 0x7f);      // long form length, skip it

    $read = function () use ($der, &$off): string {
        if (($der[$off++] ?? '') !== "\x02") throw new RuntimeException('Bad DER integer');
        $l = ord($der[$off++]);
        $v = substr($der, $off, $l);
        $off += $l;
        return str_pad(ltrim($v, "\x00"), 32, "\x00", STR_PAD_LEFT);
    };
    return $read() . $read();
}

// ─── VAPID identity ─────────────────────────────────────────────────────────

/**
 * The platform's signing identity, generated once and then stable forever.
 *
 * It has to be stable: a browser subscription is bound to the public key that
 * created it. Rotating this key silently invalidates every device already
 * registered — they stop receiving alerts and nothing anywhere reports an
 * error. So it is generated on first use and left alone.
 */
function vapidKeys(): array {
    $pdo = getDB();
    $row = $pdo->query(
        "SELECT setting_key, setting_value FROM platform_settings
          WHERE setting_key IN ('vapid_public_key','vapid_private_key')"
    )->fetchAll();

    $vals = [];
    foreach ($row as $r) $vals[$r['setting_key']] = $r['setting_value'];

    if (!empty($vals['vapid_public_key']) && !empty($vals['vapid_private_key'])) {
        return ['public' => $vals['vapid_public_key'], 'private_pem' => $vals['vapid_private_key']];
    }

    $key = openssl_pkey_new([
        'curve_name'       => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);
    if (!$key) throw new RuntimeException('Could not generate a VAPID keypair: ' . openssl_error_string());

    openssl_pkey_export($key, $pem);
    $public = base64url_encode(p256RawPublic($key));

    $save = $pdo->prepare(
        "INSERT INTO platform_settings (setting_key, setting_value)
              VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $save->execute([':k' => 'vapid_public_key',  ':v' => $public]);
    $save->execute([':k' => 'vapid_private_key', ':v' => $pem]);

    return ['public' => $public, 'private_pem' => $pem];
}

/**
 * Authorization header for one endpoint. The audience is the push service's
 * origin — not the endpoint path — and a JWT minted for Apple will be refused
 * by Google, so this is computed per host rather than once per send batch.
 */
function vapidAuthHeader(string $endpoint): string {
    $keys = vapidKeys();
    $parts = parse_url($endpoint);

    // The audience is the ORIGIN, which includes a non-default port. Real push
    // services never use one, so dropping it looks harmless — right up until a
    // staging or self-hosted relay is pointed at a port and every send comes
    // back 401 with nothing to explain it.
    $aud = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')
         . (isset($parts['port']) ? ':' . $parts['port'] : '');

    $header = base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = base64url_encode(json_encode([
        'aud' => $aud,
        // 12h. The spec caps it at 24h; short enough that a leaked header is
        // worth little, long enough to survive a slow retry queue.
        'exp' => time() + 43200,
        'sub' => (string)setting('vapid_subject', APP_URL),
    ]));

    $priv = openssl_pkey_get_private($keys['private_pem']);
    if (!$priv) throw new RuntimeException('VAPID private key will not load');

    $der = '';
    if (!openssl_sign("$header.$claims", $der, $priv, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('VAPID signing failed: ' . openssl_error_string());
    }

    $jwt = "$header.$claims." . base64url_encode(derToJoseSignature($der));
    return 'vapid t=' . $jwt . ', k=' . $keys['public'];
}

// ─── Payload encryption (RFC 8291, aes128gcm) ───────────────────────────────

/**
 * Encrypt to a single device. The push service stores and forwards the result
 * without being able to read it, which matters because these payloads carry a
 * pickup city and a dollar amount.
 *
 * $fixedEphemeral is only ever supplied by the test suite, to reproduce the
 * RFC's published example. Real sends generate a fresh keypair every time.
 */
function encryptPayload(string $plaintext, string $p256dhB64, string $authB64,
                        ?array $fixedEphemeral = null): string {
    $uaPublic = base64url_decode($p256dhB64);
    $authSecret = base64url_decode($authB64);

    if (strlen($uaPublic) !== 65)   throw new RuntimeException('Device key is not 65 bytes');
    if (strlen($authSecret) !== 16) throw new RuntimeException('Device auth secret is not 16 bytes');

    if ($fixedEphemeral) {
        $salt  = $fixedEphemeral['salt'];
        $asPub = $fixedEphemeral['public'];
        $as    = openssl_pkey_get_private(p256PrivatePem($fixedEphemeral['private'], $asPub));
    } else {
        $salt = random_bytes(16);
        $as = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (!$as) throw new RuntimeException('Ephemeral keygen failed: ' . openssl_error_string());
        $asPub = p256RawPublic($as);
    }

    $shared = openssl_pkey_derive(openssl_pkey_get_public(p256PublicPem($uaPublic)), $as, 32);
    if ($shared === false) throw new RuntimeException('ECDH failed: ' . openssl_error_string());

    // IKM binds the shared secret to *both* public keys, so a captured
    // ciphertext cannot be replayed at a different device.
    $keyInfo = "WebPush: info\x00" . $uaPublic . $asPub;
    $ikm = hash_hkdf('sha256', $shared, 32, $keyInfo, $authSecret);

    $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00",     $salt);

    // 0x02 is the last-record delimiter. A single record is always the last one.
    $tag = '';
    $ct = openssl_encrypt($plaintext . "\x02", 'aes-128-gcm', $cek,
                          OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ct === false) throw new RuntimeException('AES-GCM failed: ' . openssl_error_string());

    // salt(16) | record size(4) | key id length(1) | server public key(65) | ciphertext
    return $salt . pack('N', 4096) . chr(strlen($asPub)) . $asPub . $ct . $tag;
}

// ─── Delivery ───────────────────────────────────────────────────────────────

/** Build the curl handle for one send. Returns null if the device's key
 *  material is unusable, which is a permanent condition, not a retry. */
function pushHandle(array $sub, array $payload, ?string &$error = null) {
    try {
        $body = encryptPayload(json_encode($payload), $sub['p256dh'], $sub['auth_secret']);
        $auth = vapidAuthHeader($sub['endpoint']);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        return null;
    }

    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        // Short on purpose. Even run after the response is flushed, a hung push
        // service must not hold a PHP worker open.
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . $auth,
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: ' . (int)setting('push_ttl_seconds', 900),
            // "high" tells iOS to wake the device rather than batch the alert
            // for a convenient moment. For a 20-minute job, convenient is too late.
            'Urgency: high',
            'Content-Length: ' . strlen($body),
        ],
    ]);
    return $ch;
}

function pushResult($ch, $resp): array {
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($resp === false || $resp === null) {
        return ['ok' => false, 'code' => $code, 'error' => curl_error($ch) ?: 'no response', 'gone' => false];
    }
    if ($code >= 200 && $code < 300) {
        return ['ok' => true, 'code' => $code, 'error' => null, 'gone' => false];
    }
    return [
        'ok'    => false,
        'code'  => $code,
        // 404/410 mean the subscription no longer exists — the app was deleted,
        // or iOS evicted it. Not something to retry; a row to switch off.
        'gone'  => in_array($code, [404, 410], true),
        'error' => substr(trim((string)$resp) ?: 'HTTP ' . $code, 0, 250),
    ];
}

/**
 * Send to many devices AT ONCE and record every outcome.
 *
 * Sequential sending was the first version and it is quietly wrong: twenty
 * trucks in a dense market at 8 seconds of worst-case timeout each is over two
 * minutes, during which the operator nearest the breakdown is waiting on
 * nineteen strangers' phones. curl_multi makes the whole fan-out cost about one
 * round trip regardless of how many trucks are in range.
 *
 * $payload may be an array (same alert to everyone) or a callable taking the
 * subscription row and returning the payload for that one device — which is how
 * each operator gets told his own distance.
 *
 * Never throws. A failing phone must not take down the request that created a
 * paid job.
 */
function webPushSendMany(array $subs, $payload, string $kind = 'new_job',
                         ?int $callId = null): array {
    if (!$subs) return ['sent' => 0, 'failed' => 0];

    $pdo = getDB();
    $log = $pdo->prepare(
        "INSERT INTO push_deliveries (subscription_id, account_id, call_id, kind, http_code, ok, error)
         VALUES (:s, :a, :c, :k, :h, :o, :e)"
    );
    $win = $pdo->prepare(
        "UPDATE push_subscriptions
            SET fail_count = 0, last_success_at = NOW(), last_error = NULL
          WHERE id = :id"
    );
    // is_active is assigned FIRST, deliberately. MySQL evaluates a multi-column
    // SET left to right and later expressions see the NEW value of columns
    // already assigned — so with fail_count incremented first, `fail_count + 1`
    // reads as old+2 and every device is retired one failure early. Ordering it
    // this way makes the comparison read the original count, and keeps the
    // statement correct without depending on that rule at all.
    $lose = $pdo->prepare(
        "UPDATE push_subscriptions
            SET is_active = IF(:gone = 1 OR fail_count + 1 >= :max, 0, is_active),
                fail_count = fail_count + 1,
                last_failure_at = NOW(),
                last_error = :e
          WHERE id = :id"
    );
    $maxFail = (int)setting('push_max_failures', 5);

    $ok = 0; $failed = 0;
    $record = function (array $sub, array $r) use ($log, $win, $lose, $maxFail, $callId, $kind, &$ok, &$failed) {
        $log->execute([
            ':s' => $sub['id'], ':a' => $sub['account_id'], ':c' => $callId,
            ':k' => $kind, ':h' => $r['code'], ':o' => $r['ok'] ? 1 : 0, ':e' => $r['error'],
        ]);
        if ($r['ok']) { $ok++; $win->execute([':id' => $sub['id']]); }
        else {
            $failed++;
            $lose->execute([':id' => $sub['id'], ':e' => $r['error'],
                            ':gone' => $r['gone'] ? 1 : 0, ':max' => $maxFail]);
        }
    };

    $multi = curl_multi_init();
    $handles = [];

    foreach ($subs as $sub) {
        $body = is_callable($payload) ? $payload($sub) : $payload;
        $err = null;
        $ch = pushHandle($sub, $body, $err);
        if (!$ch) {
            $record($sub, ['ok' => false, 'code' => 0, 'error' => $err, 'gone' => false]);
            continue;
        }
        $handles[(int)$ch] = ['ch' => $ch, 'sub' => $sub];
        curl_multi_add_handle($multi, $ch);
    }

    if ($handles) {
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) curl_multi_select($multi, 1.0);
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $h) {
            $resp = curl_multi_getcontent($h['ch']);
            $record($h['sub'], pushResult($h['ch'], $resp));
            curl_multi_remove_handle($multi, $h['ch']);
            curl_close($h['ch']);
        }
    }
    curl_multi_close($multi);

    return ['sent' => $ok, 'failed' => $failed];
}

/**
 * Queue the alert to go out AFTER the response has been sent.
 *
 * A stranded customer is watching a spinner while this runs. Even a fast
 * fan-out is work they should not be waiting on, and the job is already saved
 * and paid for by this point — the push is a side effect, not part of the
 * transaction.
 */
function pushNewJobAfterResponse(int $callId): void {
    register_shutdown_function(function () use ($callId) {
        // Hand the response to the browser first where the SAPI supports it.
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        @ignore_user_abort(true);
        try {
            pushNewJob($callId);
        } catch (Throwable $e) {
            error_log('push: new job ' . $callId . ' failed: ' . $e->getMessage());
        }
    });
}

// ─── Who gets told about a new job ──────────────────────────────────────────

/**
 * Devices belonging to towers who should be woken for this specific call.
 *
 * Deliberately stricter than the board. The board is something an operator
 * chooses to look at; this is a buzz in his pocket at 3am. Anything that
 * doesn't clearly pass every filter is left off, because the cost of a wrong
 * alert is not one ignored notification — it is the operator disabling
 * notifications at the OS level, which we can neither see nor undo.
 */
function towersToAlert(array $call): array {
    $lat = isset($call['pickup_lat']) ? (float)$call['pickup_lat'] : null;
    $lng = isset($call['pickup_lng']) ? (float)$call['pickup_lng'] : null;
    if ($lat === null || $lng === null || (!$lat && !$lng)) return [];

    // Cheap index-friendly prefilter, then exact distance below.
    $box = boundingBox($lat, $lng, MAX_SEARCH_RADIUS_MILES);

    $sql = "SELECT s.id, s.account_id, s.endpoint, s.p256dh, s.auth_secret,
                   p.service_radius_miles, p.push_radius_miles, p.push_min_payout,
                   p.push_quiet_start, p.push_quiet_end, p.push_timezone,
                   p.base_lat, p.base_lng, p.is_24_7,
                   p.has_light_duty, p.has_medium_duty, p.has_heavy_duty,
                   p.has_flatbed, p.has_wheel_lift, p.has_winch_recovery,
                   p.has_lockout, p.has_jumpstart, p.has_tire_change,
                   p.has_fuel_delivery, p.has_motorcycle, p.has_ev_certified,
                   p.has_lowclearance
              FROM push_subscriptions s
              JOIN tower_profiles p ON p.account_id = s.account_id
              JOIN accounts a       ON a.id = s.account_id
             WHERE s.is_active = 1
               AND p.push_enabled = 1
               AND a.is_active = 1
               AND a.account_type = 'tower'
               -- Only approved companies. An unverified tower can browse, but
               -- being pushed a job you are not allowed to accept is worse
               -- than silence.
               AND a.verification_status = 'approved'
               -- Same reasoning for the contact details. A company that has not
               -- confirmed its phone number cannot be handed a customer, so it
               -- must not be woken at 3am for a job the board will then refuse.
               -- Compared against the CURRENT value: verifying a number and
               -- then editing it puts the company back behind this line.
               AND (:need_contact = 0 OR (
                     a.email_verified_at IS NOT NULL
                     AND a.email_verified_value = a.email
                     AND a.phone_verified_at IS NOT NULL
                     AND a.phone_verified_value = a.phone))
               AND p.base_lat BETWEEN :minlat AND :maxlat
               AND p.base_lng BETWEEN :minlng AND :maxlng";

    $stmt = getDB()->prepare($sql);
    $stmt->execute([
        ':minlat' => $box['min_lat'], ':maxlat' => $box['max_lat'],
        ':minlng' => $box['min_lng'], ':maxlng' => $box['max_lng'],
        ':need_contact' => (string)setting('require_verification_to_accept', '1') === '1' ? 1 : 0,
    ]);

    $net = isset($call['tower_net_estimate'])
             ? (float)$call['tower_net_estimate']
             : (float)($call['offer_amount'] ?? 0) - consumerFee((float)($call['offer_amount'] ?? 0));

    $limit = (int)setting('push_fanout_limit', 60);
    $out = [];

    foreach ($stmt as $row) {
        $radius = $row['push_radius_miles'] !== null
                    ? (int)$row['push_radius_miles']
                    : (int)$row['service_radius_miles'];
        if ($radius <= 0) continue;

        $miles = haversineMiles($lat, $lng, (float)$row['base_lat'], (float)$row['base_lng']);
        if ($miles > $radius) continue;

        if ($net < (float)$row['push_min_payout']) continue;
        if (!towerIsCapable($row, $call)) continue;
        if (inQuietHours($row)) continue;

        $row['distance_miles'] = round($miles, 1);
        $out[] = $row;
    }

    // Nearest first. If the fan-out cap bites, it should bite the operator an
    // hour away, not the one round the corner.
    usort($out, fn($a, $b) => $a['distance_miles'] <=> $b['distance_miles']);

    // Never truncate silently. A market that is regularly hitting this cap is a
    // market where the furthest operators quietly stop seeing jobs, and without
    // this line the first sign of it is a company asking why it went quiet.
    if (count($out) > $limit) {
        error_log('push: fan-out capped at ' . $limit . ' of ' . count($out)
                  . ' eligible devices for call ' . ($call['id'] ?? '?'));
    }

    return array_slice($out, 0, max(1, $limit));
}

/**
 * Quiet hours, evaluated in the tower's OWN timezone.
 *
 * This is the detail that nationwide breaks. Server time is one clock; a
 * company in Los Angeles setting "no calls after 10pm" means 10pm Pacific, and
 * comparing that against a server running Eastern silences them three hours
 * early every single night — a bug that shows up as "we stopped getting jobs"
 * weeks later and never as an error.
 *
 * A 24/7 operator is never quiet; that is the whole business they are in.
 */
function inQuietHours(array $profile): bool {
    if (!empty($profile['is_24_7']))       return false;
    if (empty($profile['push_quiet_start']) || empty($profile['push_quiet_end'])) return false;

    try {
        $tz = new DateTimeZone($profile['push_timezone'] ?: 'America/New_York');
    } catch (Throwable $e) {
        return false;   // a bad timezone must not silence a company
    }

    $now   = (int)(new DateTime('now', $tz))->format('Hi');
    $start = (int)str_replace(':', '', substr($profile['push_quiet_start'], 0, 5));
    $end   = (int)str_replace(':', '', substr($profile['push_quiet_end'], 0, 5));

    // Quiet hours almost always cross midnight (22:00 → 06:00), so the naive
    // start <= now <= end comparison is wrong in the common case.
    return $start <= $end ? ($now >= $start && $now < $end)
                          : ($now >= $start || $now < $end);
}

/**
 * The alert itself. Deliberately terse — this is read on a lock screen, by
 * someone who may be driving, in the few seconds before they decide whether to
 * pull over. Money and distance first, because that is the whole decision.
 */
function pushNewJob(int $callId): array {
    $none = ['sent' => 0, 'failed' => 0, 'devices' => 0];
    if ((string)setting('push_enabled', '1') !== '1') return $none + ['skipped' => 'disabled'];

    $stmt = getDB()->prepare("SELECT * FROM calls WHERE id = :id");
    $stmt->execute([':id' => $callId]);
    $call = $stmt->fetch();
    if (!$call || $call['status'] !== 'open') return $none + ['skipped' => 'not_open'];

    $offer = (float)$call['offer_amount'];
    $fee   = ($call['source'] ?? 'board') === 'consumer' ? consumerFee($offer) : platformFee($offer);
    $call['tower_net_estimate'] = round($offer - $fee, 2);

    $subs = towersToAlert($call);
    if (!$subs) return $none + ['skipped' => 'no_devices'];

    $service = ucwords(str_replace('_', ' ', $call['service_type']));
    $area    = trim(($call['pickup_city'] ?? '') . ', ' . ($call['pickup_state'] ?? ''), ', ');
    $miles   = $call['tow_miles'] !== null ? round((float)$call['tow_miles']) . ' mi tow' : null;

    $payload = [
        'kind'    => 'new_job',
        'call_id' => (int)$call['id'],
        'title'   => '$' . number_format($call['tower_net_estimate'], 0) . ' · ' . $service,
        'body'    => trim($area . ($miles ? ' · ' . $miles : '')),
        // Relative — see the note in api/push.php. The service worker
        // resolves it against its own scope.
        'url'     => 'tow?job=' . (int)$call['id'],
        'tag'     => 'job-' . (int)$call['id'],
        'expires' => $call['expires_at'],
    ];

    // Each device is told its own distance — "4.2 mi away" decides it for the
    // operator far more than the city name does. Passed as a closure so every
    // phone still goes out in the same parallel batch.
    $r = webPushSendMany($subs, function (array $sub) use ($payload) {
        $p = $payload;
        $p['body'] = trim($p['body'] . ' · ' . $sub['distance_miles'] . ' mi away');
        return $p;
    }, 'new_job', $callId);

    return ['sent' => $r['sent'], 'failed' => $r['failed'], 'devices' => count($subs)];
}
