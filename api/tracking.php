<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/tracking.php';
require_once __DIR__ . '/../includes/realtime.php';

setCorsHeaders();

// ═══════════════════════════════════════════════════════════════════════════
//  TRACKING
//
//    POST /api/tracking/ping    driver's phone reports its position
//    GET  /api/tracking/feed    customer's map, authenticated by the tracking link
//    GET  /api/tracking/trail   the breadcrumb trail — operator or admin only
//
//  The ping response always carries `keep_tracking`. That is the signal the
//  driver's app uses to switch location services off, and it is the mechanism
//  behind the promise that nobody is tracked outside a live job: the moment the
//  job closes, the server says stop, and the app stops. The app should also
//  stop on its own when the job ends — but the server saying so is what makes
//  it true even for a client that forgot to.
// ═══════════════════════════════════════════════════════════════════════════

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ─── The customer's moving map ──────────────────────────────────────────────
// No login. A stranded person has no account and will not make one; the
// unguessable tracking token in their link is the credential.
if ($action === 'feed') {
    $token = $_GET['token'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) errorResponse(t('err.bad_tracking'), 404);

    $stmt = getDB()->prepare("SELECT * FROM calls WHERE tracking_token = :t");
    $stmt->execute([':t' => $token]);
    $call = $stmt->fetch();
    if (!$call) errorResponse(t('err.job_not_found'), 404);

    $truck = customerTrackingView($call);

    successResponse([
        'status'  => $call['status'],
        // Only ever the pickup point. The truck's own depot, its other jobs and
        // where it goes afterwards are none of this screen's business.
        'pickup'  => ($call['pickup_lat'] !== null) ? [
            'lat' => (float)$call['pickup_lat'],
            'lng' => (float)$call['pickup_lng'],
        ] : null,
        'dropoff' => ($call['dropoff_lat'] !== null) ? [
            'lat' => (float)$call['dropoff_lat'],
            'lng' => (float)$call['dropoff_lng'],
        ] : null,
        'truck'   => $truck,
        // What the driver promised at accept time, kept separate from the live
        // number so the screen can fall back to it before the first fix lands.
        'promised_eta_minutes' => $call['awarded_eta_minutes'] !== null
                                    ? (int)$call['awarded_eta_minutes'] : null,
        'poll_seconds' => max(4, (int)setting('tracking_ping_seconds', 10)),
        'tracking_enabled' => trackingEnabled(),
    ]);
}

// Everything past here needs a login.
$user = requireAuth();

// ─── A position from the driver's phone ─────────────────────────────────────
if ($method === 'POST' && $action === 'ping') {
    requireAccountType($user, 'tower');
    $in = jsonInput();

    $callId = (int)($in['call_id'] ?? 0);
    if (!$callId) errorResponse(t('err.job_not_found'), 404);

    $stmt = getDB()->prepare("SELECT * FROM calls WHERE id = :id");
    $stmt->execute([':id' => $callId]);
    $call = $stmt->fetch();
    if (!$call) errorResponse(t('err.job_not_found'), 404);

    $r = recordTruckLocation($call, (int)$user['account_id'], (int)$user['id'], $in);

    if (!$r['ok']) {
        // A closed job is not an error the driver did anything about — it is an
        // instruction to stop. Answering 200 with keep_tracking=false means the
        // app shuts location down cleanly instead of retrying a 4xx in a loop
        // with the GPS still running.
        if (!empty($r['stop'])) {
            successResponse(['keep_tracking' => false, 'reason' => $r['error']]);
        }
        errorResponse($r['error'], 422);
    }

    // The customer's map moves the moment the truck does, instead of on its
    // next poll. Only the position and ETA their own feed would have handed
    // them a few seconds later — nothing new is disclosed by being early.
    if (!empty($r['moved']) && !empty($call['tracking_token'])) {
        rtTruckMoved($call['tracking_token'], (float)$in['lat'], (float)$in['lng'],
                     $r['eta']['minutes'] ?? null);
    }

    // Housekeeping rides along on roughly one ping in two hundred. No cron on
    // this host, and a scheduled task nobody notices has stopped is worse than
    // a small tax on a request that was going to happen anyway.
    if (random_int(1, 200) === 1) purgeOldLocations();

    successResponse([
        'keep_tracking' => true,
        'moved'         => (bool)$r['moved'],
        'ignored'       => $r['ignored'] ?? null,
        'eta_minutes'   => $r['eta']['minutes'] ?? null,
        'next_ping_seconds' => max(4, (int)setting('tracking_ping_seconds', 10)),
    ]);
}

// ─── The breadcrumb trail ───────────────────────────────────────────────────
// The dispute record. The operator who drove it may see his own; an admin may
// see any. The customer never needs it and does not get it.
if ($action === 'trail') {
    $callId = (int)($_GET['call_id'] ?? 0);
    if (!$callId) errorResponse(t('err.job_not_found'), 404);

    $stmt = getDB()->prepare("SELECT * FROM calls WHERE id = :id");
    $stmt->execute([':id' => $callId]);
    $call = $stmt->fetch();
    if (!$call) errorResponse(t('err.job_not_found'), 404);

    if ((int)$call['awarded_tower_account_id'] !== (int)$user['account_id']) {
        errorResponse(t('err.no_permission'), 403);
    }

    successResponse([
        'call_number' => $call['call_number'],
        'points'      => callTrail($callId),
    ]);
}

errorResponse('Unknown action', 404);
