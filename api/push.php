<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/webpush.php';

setCorsHeaders();

// ═══════════════════════════════════════════════════════════════════════════
//  PUSH — device registration and alert preferences for towing companies
//
//    GET  /api/push/key          public VAPID key the browser needs to subscribe
//    POST /api/push/subscribe    register this device
//    POST /api/push/unsubscribe  drop it
//    GET  /api/push/devices      what is registered, and whether it is working
//    POST /api/push/test         buzz my own phone right now
//    GET  /api/push/prefs        radius, floor, quiet hours
//    POST /api/push/prefs        change them
// ═══════════════════════════════════════════════════════════════════════════

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$in     = jsonInput();

// ─── The public half of the signing key ─────────────────────────────────────
// Not a secret — it is published to every subscribing browser by design, and
// the browser will not subscribe without it. Unauthenticated because the
// service worker asks for it before the page has finished restoring a session.
if ($action === 'key') {
    try {
        successResponse(['public_key' => vapidKeys()['public']]);
    } catch (Throwable $e) {
        errorResponse('Push is not configured on this server', 500);
    }
}

$user = requireAuth();
requireAccountType($user, 'tower');
$accountId = (int)$user['account_id'];

// ─── Register a device ──────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'subscribe') {
    $endpoint = trim((string)($in['endpoint'] ?? ''));
    $p256dh   = trim((string)($in['keys']['p256dh'] ?? $in['p256dh'] ?? ''));
    $auth     = trim((string)($in['keys']['auth']   ?? $in['auth']   ?? ''));

    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        errorResponse('Incomplete subscription', 422);
    }
    if (!preg_match('#^https://#', $endpoint)) {
        errorResponse('Invalid push endpoint', 422);
    }
    // Validate the key material now rather than discovering it is malformed at
    // 3am inside a send loop, where the only symptom is a job nobody heard about.
    if (strlen(base64url_decode($p256dh)) !== 65 || strlen(base64url_decode($auth)) !== 16) {
        errorResponse('Malformed device keys', 422);
    }

    // Default first, then whitelist — reading the key inside the ternary would
    // make an omitted field NULL instead of the default.
    $platform = strtolower((string)($in['platform'] ?? 'unknown'));
    if (!in_array($platform, ['ios', 'android', 'desktop', 'unknown'], true)) $platform = 'unknown';

    getDB()->prepare(
        "INSERT INTO push_subscriptions
            (account_id, user_id, endpoint, endpoint_hash, p256dh, auth_secret,
             platform, is_standalone, user_agent, label, last_seen_at)
         VALUES (:a, :u, :e, :h, :p, :s, :pl, :st, :ua, :lb, NOW())
         ON DUPLICATE KEY UPDATE
             account_id = VALUES(account_id),
             user_id    = VALUES(user_id),
             p256dh     = VALUES(p256dh),
             auth_secret= VALUES(auth_secret),
             platform   = VALUES(platform),
             is_standalone = VALUES(is_standalone),
             user_agent = VALUES(user_agent),
             -- Re-subscribing is how a device recovers. Clearing the failure
             -- state here is the whole point: a phone that was off for a week
             -- must come back to life on its own, without an admin touching it.
             is_active  = 1,
             fail_count = 0,
             last_error = NULL,
             last_seen_at = NOW()"
    )->execute([
        ':a'  => $accountId,
        ':u'  => (int)$user['id'],
        ':e'  => $endpoint,
        ':h'  => hash('sha256', $endpoint),
        ':p'  => $p256dh,
        ':s'  => $auth,
        ':pl' => $platform,
        ':st' => !empty($in['standalone']) ? 1 : 0,
        ':ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ':lb' => isset($in['label']) ? substr(trim((string)$in['label']), 0, 60) : null,
    ]);

    successResponse(['registered' => true], t('ok.push_on'));
}

// ─── Drop a device ──────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'unsubscribe') {
    $endpoint = trim((string)($in['endpoint'] ?? ''));
    if ($endpoint === '') errorResponse('Missing endpoint', 422);

    // Scoped to the caller's own account — an endpoint string is not a secret
    // worth trusting, and without this anyone holding one could silence a
    // competitor's phone.
    getDB()->prepare(
        "DELETE FROM push_subscriptions WHERE endpoint_hash = :h AND account_id = :a"
    )->execute([':h' => hash('sha256', $endpoint), ':a' => $accountId]);

    successResponse(['registered' => false], t('ok.push_off'));
}

// ─── What is registered, and is it actually working ─────────────────────────
if ($action === 'devices') {
    $stmt = getDB()->prepare(
        "SELECT id, platform, is_standalone, label, is_active, fail_count,
                last_success_at, last_failure_at, last_error, created_at, last_seen_at
           FROM push_subscriptions
          WHERE account_id = :a
          ORDER BY is_active DESC, COALESCE(last_success_at, created_at) DESC"
    );
    $stmt->execute([':a' => $accountId]);

    $devices = [];
    foreach ($stmt as $d) {
        $devices[] = [
            'id'            => (int)$d['id'],
            'platform'      => $d['platform'],
            'installed'     => (bool)$d['is_standalone'],
            'label'         => $d['label'],
            'active'        => (bool)$d['is_active'],
            'fail_count'    => (int)$d['fail_count'],
            'last_success'  => $d['last_success_at'],
            'last_failure'  => $d['last_failure_at'],
            'last_error'    => $d['last_error'],
            'registered_at' => $d['created_at'],
            // The single most useful field on this whole endpoint. On iPhone a
            // subscription made from Safari rather than the home-screen install
            // will register happily and then never deliver anything.
            'health'        => !$d['is_active'] ? 'stopped'
                             : ($d['platform'] === 'ios' && !$d['is_standalone'] ? 'not_installed'
                             : ($d['last_success_at'] ? 'ok' : 'untested')),
        ];
    }
    successResponse(['devices' => $devices]);
}

// ─── Buzz my own phone ──────────────────────────────────────────────────────
// The only honest way to answer "will I actually get the alerts". Costs
// nothing, and an operator who has seen it work once stops worrying.
if ($method === 'POST' && $action === 'test') {
    $stmt = getDB()->prepare(
        "SELECT id, account_id, endpoint, p256dh, auth_secret
           FROM push_subscriptions WHERE account_id = :a AND is_active = 1"
    );
    $stmt->execute([':a' => $accountId]);
    $subs = $stmt->fetchAll();

    if (!$subs) errorResponse(t('err.no_devices'), 404);

    $r = webPushSendMany($subs, [
        'kind'  => 'test',
        'title' => t('push.test_title'),
        'body'  => t('push.test_body'),
        // Relative to the service worker's scope, so it follows the app
        // wherever it is deployed. An absolute path baked in here pointed at
        // the old subfolder and would open a 404 from the notification.
        'url'   => 'tow',
        'tag'   => 'test',
    ], 'test');

    successResponse([
        'sent'    => $r['sent'],
        'failed'  => $r['failed'],
        'devices' => count($subs),
    ], $r['sent'] > 0 ? t('ok.test_sent') : t('err.test_failed'));
}

// ─── Alert preferences ──────────────────────────────────────────────────────
if ($action === 'prefs' && $method === 'GET') {
    $stmt = getDB()->prepare(
        "SELECT push_enabled, push_radius_miles, service_radius_miles, push_min_payout,
                push_quiet_start, push_quiet_end, push_timezone, is_24_7,
                base_lat, base_lng,
                -- Whether a yard point exists at all. Signup no longer asks for
                -- one, so an operator who never sets it is matched against his
                -- state centroid and quietly sees the wrong jobs.
                (base_lat IS NOT NULL AND base_lng IS NOT NULL) AS base_set
           FROM tower_profiles WHERE account_id = :a"
    );
    $stmt->execute([':a' => $accountId]);
    $p = $stmt->fetch() ?: [];

    // Where the native apps live, if they exist yet. The dashboard asks the
    // server rather than hardcoding a link, so publishing the app is a setting
    // Ricardo pastes in, not a deploy.
    successResponse(['app' => [
        'ios'     => trim((string)setting('ios_app_url', '')),
        'android' => trim((string)setting('android_app_url', '')),
    ], 'prefs' => [
        'enabled'        => (bool)($p['push_enabled'] ?? 1),
        'radius_miles'   => $p['push_radius_miles'] !== null ? (int)$p['push_radius_miles'] : null,
        'service_radius' => (int)($p['service_radius_miles'] ?? 25),
        'min_payout'     => (float)($p['push_min_payout'] ?? 0),
        'quiet_start'    => $p['push_quiet_start'] ? substr($p['push_quiet_start'], 0, 5) : null,
        'quiet_end'      => $p['push_quiet_end']   ? substr($p['push_quiet_end'], 0, 5)   : null,
        'timezone'       => $p['push_timezone'] ?? 'America/New_York',
        'is_24_7'        => (bool)($p['is_24_7'] ?? 0),
        'base_lat'       => isset($p['base_lat']) ? (float)$p['base_lat'] : null,
        'base_lng'       => isset($p['base_lng']) ? (float)$p['base_lng'] : null,
        'base_set'       => !empty($p['base_set']),
    ]]);
}

if ($action === 'prefs' && $method === 'POST') {
    $set = [];
    $bind = [':a' => $accountId];

    if (array_key_exists('enabled', $in)) {
        $set[] = 'push_enabled = :en';
        $bind[':en'] = !empty($in['enabled']) ? 1 : 0;
    }

    if (array_key_exists('radius_miles', $in)) {
        // null means "follow my service radius" — one number to keep right
        // instead of two that drift apart.
        if ($in['radius_miles'] === null || $in['radius_miles'] === '') {
            $set[] = 'push_radius_miles = NULL';
        } else {
            $r = (int)$in['radius_miles'];
            if ($r < 1 || $r > MAX_SEARCH_RADIUS_MILES) {
                errorResponse(t('err.radius_range', ['max' => MAX_SEARCH_RADIUS_MILES]), 422);
            }
            $set[] = 'push_radius_miles = :rad';
            $bind[':rad'] = $r;
        }
    }

    if (array_key_exists('min_payout', $in)) {
        $m = (float)$in['min_payout'];
        // A floor above a few hundred dollars silences the account by accident,
        // and the operator blames the platform rather than the setting.
        if ($m < 0 || $m > 1000) errorResponse(t('err.min_payout_range'), 422);
        $set[] = 'push_min_payout = :mp';
        $bind[':mp'] = money($m);
    }

    // Quiet hours are set as a pair or cleared as a pair. Half a window is not
    // a window, and storing one half would silently mean "never quiet".
    if (array_key_exists('quiet_start', $in) || array_key_exists('quiet_end', $in)) {
        $s = trim((string)($in['quiet_start'] ?? ''));
        $e = trim((string)($in['quiet_end'] ?? ''));
        if ($s === '' || $e === '') {
            $set[] = 'push_quiet_start = NULL';
            $set[] = 'push_quiet_end = NULL';
        } else {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s) ||
                !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $e)) {
                errorResponse(t('err.bad_time'), 422);
            }
            if ($s === $e) errorResponse(t('err.quiet_all_day'), 422);
            $set[] = 'push_quiet_start = :qs';
            $set[] = 'push_quiet_end = :qe';
            $bind[':qs'] = $s . ':00';
            $bind[':qe'] = $e . ':00';
        }
    }

    // The yard point moved off the signup form, so it has to be settable here
    // — it is what decides which jobs this company is offered at all, and a
    // company sitting on its state centroid quietly sees the wrong ones.
    if (array_key_exists('base_lat', $in) && array_key_exists('base_lng', $in)) {
        $blat = (float)$in['base_lat'];
        $blng = (float)$in['base_lng'];
        if ($blat < -90 || $blat > 90 || $blng < -180 || $blng > 180
            || ($blat == 0.0 && $blng == 0.0)) {
            errorResponse(t('err.bad_location'), 422);
        }
        $set[] = 'base_lat = :blat';
        $set[] = 'base_lng = :blng';
        $bind[':blat'] = $blat;
        $bind[':blng'] = $blng;
    }

    if (array_key_exists('timezone', $in)) {
        $tz = (string)$in['timezone'];
        // Validated against the real database, because a typo here silences a
        // company at the wrong hour and produces no error anywhere.
        if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            errorResponse(t('err.bad_timezone'), 422);
        }
        $set[] = 'push_timezone = :tz';
        $bind[':tz'] = $tz;
    }

    if (!$set) errorResponse('Nothing to update', 422);

    getDB()->prepare(
        'UPDATE tower_profiles SET ' . implode(', ', $set) . ' WHERE account_id = :a'
    )->execute($bind);

    successResponse([], t('ok.saved'));
}

errorResponse('Unknown action', 404);
