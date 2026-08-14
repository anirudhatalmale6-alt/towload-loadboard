<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/matching.php';
setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ═══ SUBMIT / UPDATE A BID (tower) ═══════════════════════════════════════════
// One bid per tower per call — re-submitting replaces the previous one rather
// than stacking, so a provider never sees the same company three times.
if ($method === 'POST' && ($action === 'create' || $action === '')) {
    $user = requireAuth();
    requireAccountType($user, 'tower');
    requireVerified($user);
    $in = jsonInput();

    $callId = (int)($in['call_id'] ?? 0);
    $amount = round((float)($in['amount'] ?? 0), 2);
    $eta    = (int)($in['eta_minutes'] ?? 0);

    if (!$callId)      errorResponse('call_id is required');
    if ($amount <= 0)  errorResponse('amount must be greater than zero');
    if ($eta <= 0)     errorResponse('eta_minutes is required');

    $eligibility = towerCanAccept((int)$user['account_id']);
    if (!$eligibility['ok']) errorResponse($eligibility['reason'], 403);

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM calls WHERE id = :id");
    $stmt->execute([':id' => $callId]);
    $call = $stmt->fetch();

    if (!$call)                          errorResponse('Call not found', 404);
    if ($call['status'] !== 'open')       errorResponse('This call is no longer open', 409);
    if (strtotime($call['expires_at']) < time()) errorResponse('This call has expired', 409);
    if ($call['pricing_mode'] !== 'bid')  errorResponse('This call is accept-only — hit Accept instead', 409);

    // The provider funded the offer amount. A bid above it can't be awarded,
    // so reject it here rather than letting them think it's in play.
    if ($amount > (float)$call['offer_amount'] + 0.001) {
        errorResponse('Your bid is above the funded amount of $' . money($call['offer_amount']) . ' for this call');
    }

    $pdo->prepare(
        "INSERT INTO bids (call_id, tower_account_id, bid_by_user_id, amount, eta_minutes, note, status)
         VALUES (:c, :t, :u, :amt, :eta, :n, 'pending')
         ON DUPLICATE KEY UPDATE amount = VALUES(amount), eta_minutes = VALUES(eta_minutes),
                                 note = VALUES(note), status = 'pending', bid_by_user_id = VALUES(bid_by_user_id)"
    )->execute([
        ':c' => $callId, ':t' => $user['account_id'], ':u' => $user['id'],
        ':amt' => money($amount), ':eta' => $eta, ':n' => $in['note'] ?? null,
    ]);

    logCallEvent($callId, 'bid_placed',
        $user['account_name'] . ' bid $' . money($amount) . ', ETA ' . $eta . ' min',
        (int)$user['account_id'], (int)$user['id']);

    notify((int)$call['provider_account_id'], 'new_bid',
        'New bid on ' . $call['call_number'],
        $user['account_name'] . ' bid $' . money($amount) . ' — ETA ' . $eta . ' minutes.', $callId);

    successResponse([
        'call_id' => $callId,
        'amount' => money($amount),
        'your_net_if_awarded' => money($amount - platformFee($amount)),
    ], 'Bid submitted');
}

// ═══ WITHDRAW A BID (tower) ══════════════════════════════════════════════════
if ($method === 'POST' && $action === 'withdraw') {
    $user = requireAuth();
    requireAccountType($user, 'tower');
    $in = jsonInput();
    $callId = (int)($in['call_id'] ?? 0);
    if (!$callId) errorResponse('call_id is required');

    $stmt = getDB()->prepare(
        "UPDATE bids SET status = 'withdrawn'
          WHERE call_id = :c AND tower_account_id = :t AND status = 'pending'"
    );
    $stmt->execute([':c' => $callId, ':t' => $user['account_id']]);

    if ($stmt->rowCount() === 0) errorResponse('No pending bid to withdraw', 404);

    logCallEvent($callId, 'bid_withdrawn', $user['account_name'] . ' withdrew their bid',
        (int)$user['account_id'], (int)$user['id']);

    successResponse([], 'Bid withdrawn');
}

// ═══ BIDS ON A CALL (provider) ═══════════════════════════════════════════════
if ($method === 'GET' && $action === 'for-call') {
    $user = requireAuth();
    requireAccountType($user, 'provider');
    $callId = (int)($_GET['call_id'] ?? 0);
    if (!$callId) errorResponse('call_id is required');

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, pickup_lat, pickup_lng FROM calls WHERE id = :id AND provider_account_id = :p");
    $stmt->execute([':id' => $callId, ':p' => $user['account_id']]);
    $call = $stmt->fetch();
    if (!$call) errorResponse('Call not found', 404);

    $stmt = $pdo->prepare(
        "SELECT b.id, b.amount, b.eta_minutes, b.note, b.created_at,
                a.id AS tower_id, a.name AS tower_name, a.rating_avg, a.rating_count,
                a.jobs_completed, a.jobs_goa, a.city, a.state,
                tp.base_lat, tp.base_lng, tp.trucks_count, tp.is_24_7
           FROM bids b
           JOIN accounts a ON b.tower_account_id = a.id
      LEFT JOIN tower_profiles tp ON tp.account_id = a.id
          WHERE b.call_id = :c AND b.status = 'pending'
          ORDER BY b.amount ASC, b.eta_minutes ASC"
    );
    $stmt->execute([':c' => $callId]);

    $bids = [];
    foreach ($stmt->fetchAll() as $b) {
        $b['distance_miles'] = ($b['base_lat'] && $b['base_lng'])
            ? round(haversineMiles((float)$call['pickup_lat'], (float)$call['pickup_lng'],
                                   (float)$b['base_lat'], (float)$b['base_lng']), 1)
            : null;
        // A cheap bid from someone with 40% GOA history isn't actually cheap.
        $b['goa_rate'] = (int)$b['jobs_completed'] > 0
            ? round((int)$b['jobs_goa'] / max(1, (int)$b['jobs_completed'] + (int)$b['jobs_goa']) * 100, 1)
            : null;
        unset($b['base_lat'], $b['base_lng']);
        $bids[] = $b;
    }

    successResponse(['bids' => $bids, 'count' => count($bids)]);
}

// ═══ MY BIDS (tower) ═════════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'mine') {
    $user = requireAuth();
    requireAccountType($user, 'tower');

    $stmt = getDB()->prepare(
        "SELECT b.*, c.call_number, c.service_type, c.pickup_city, c.pickup_state,
                c.offer_amount, c.status AS call_status, c.expires_at,
                a.name AS provider_name
           FROM bids b
           JOIN calls c ON b.call_id = c.id
           JOIN accounts a ON c.provider_account_id = a.id
          WHERE b.tower_account_id = :t
          ORDER BY b.created_at DESC LIMIT 100"
    );
    $stmt->execute([':t' => $user['account_id']]);
    successResponse(['bids' => $stmt->fetchAll()]);
}

errorResponse('Unknown action', 404);
