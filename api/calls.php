<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/realtime.php';
require_once __DIR__ . '/../includes/escrow.php';
require_once __DIR__ . '/../includes/matching.php';
require_once __DIR__ . '/../includes/stripe_connect.php';
setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ═══ POST A CALL (provider) ══════════════════════════════════════════════════
if ($method === 'POST' && $action === 'create') {
    $user = requireAuth();
    requireAccountType($user, 'provider');
    requireRole($user, ['owner', 'dispatcher']);
    if ((string)setting('providers_enabled', '0') !== '1') {
        errorResponse(t('err.posting_closed'), 403);
    }
    $in = jsonInput();

    foreach (['pickup_address', 'pickup_lat', 'pickup_lng', 'offer_amount'] as $f) {
        if (!isset($in[$f]) || $in[$f] === '') errorResponse("$f is required");
    }
    $offer = round((float)$in['offer_amount'], 2);
    if ($offer <= 0) errorResponse('Offer amount must be greater than zero');

    // The pickup is what matters for the launch fence, not where the poster is
    // sitting — a Miami club dispatching a call in Tampa has no towers there.
    if ($msg = outsideLaunchArea((float)$in['pickup_lat'], (float)$in['pickup_lng'])) {
        errorResponse($msg, 422);
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM provider_profiles WHERE account_id = :a");
    $stmt->execute([':a' => $user['account_id']]);
    $profile = $stmt->fetch() ?: ['default_goa_amount' => 45.00, 'default_call_expiry_minutes' => 20];

    $expiryMin = (int)($in['expires_in_minutes'] ?? $profile['default_call_expiry_minutes'] ?? 20);
    $expiryMin = max(5, min($expiryMin, 24 * 60));
    $goa = isset($in['goa_amount']) ? round((float)$in['goa_amount'], 2) : (float)$profile['default_goa_amount'];
    if ($goa > $offer) $goa = $offer;

    $pricingMode = ((string)setting('bidding_enabled', '0') === '1'
                    && in_array($in['pricing_mode'] ?? 'accept', ['accept', 'bid'], true))
        ? $in['pricing_mode'] : 'accept';

    // Distance is what a tower actually prices on, so compute it rather than
    // trusting whatever the posting system claims.
    $towMiles = null;
    if (!empty($in['dropoff_lat']) && !empty($in['dropoff_lng'])) {
        $towMiles = round(haversineMiles(
            (float)$in['pickup_lat'], (float)$in['pickup_lng'],
            (float)$in['dropoff_lat'], (float)$in['dropoff_lng']
        ), 2);
    }

    $pdo->beginTransaction();
    try {
        $callNumber = generateCallNumber();
        $pdo->prepare(
            "INSERT INTO calls (
                call_number, provider_account_id, posted_by_user_id, service_type, vehicle_class,
                pickup_address, pickup_city, pickup_state, pickup_zip, pickup_lat, pickup_lng, pickup_notes,
                dropoff_address, dropoff_city, dropoff_state, dropoff_zip, dropoff_lat, dropoff_lng, tow_miles,
                vehicle_year, vehicle_make, vehicle_model, vehicle_color, vehicle_plate, vehicle_vin,
                has_keys, wheels_lock, is_accident, is_underground, needs_flatbed, is_ev,
                customer_name, customer_phone,
                pricing_mode, offer_amount, goa_amount,
                scheduled_for, expires_at, eta_required_minutes, status
             ) VALUES (
                :cn, :pa, :pu, :st, :vc,
                :addr, :city, :state, :zip, :lat, :lng, :notes,
                :daddr, :dcity, :dstate, :dzip, :dlat, :dlng, :miles,
                :vy, :vm, :vmo, :vcol, :vplate, :vvin,
                :keys, :wheels, :accident, :under, :flatbed, :ev,
                :cname, :cphone,
                :mode, :offer, :goa,
                :sched, DATE_ADD(NOW(), INTERVAL :exp MINUTE), :eta, 'open'
             )"
        )->execute([
            ':cn' => $callNumber, ':pa' => $user['account_id'], ':pu' => $user['id'],
            ':st' => $in['service_type'] ?? 'tow', ':vc' => $in['vehicle_class'] ?? 'light',
            ':addr' => $in['pickup_address'], ':city' => $in['pickup_city'] ?? null,
            ':state' => !empty($in['pickup_state']) ? strtoupper(substr($in['pickup_state'], 0, 2)) : null,
            ':zip' => $in['pickup_zip'] ?? null,
            ':lat' => $in['pickup_lat'], ':lng' => $in['pickup_lng'],
            ':notes' => $in['pickup_notes'] ?? null,
            ':daddr' => $in['dropoff_address'] ?? null, ':dcity' => $in['dropoff_city'] ?? null,
            ':dstate' => !empty($in['dropoff_state']) ? strtoupper(substr($in['dropoff_state'], 0, 2)) : null,
            ':dzip' => $in['dropoff_zip'] ?? null,
            ':dlat' => $in['dropoff_lat'] ?? null, ':dlng' => $in['dropoff_lng'] ?? null,
            ':miles' => $towMiles,
            ':vy' => $in['vehicle_year'] ?? null, ':vm' => $in['vehicle_make'] ?? null,
            ':vmo' => $in['vehicle_model'] ?? null, ':vcol' => $in['vehicle_color'] ?? null,
            ':vplate' => $in['vehicle_plate'] ?? null, ':vvin' => $in['vehicle_vin'] ?? null,
            // Absent means "normal vehicle", not "no keys" — defaulting these to
            // 0 flags every call as a problem job and towers price off the flags.
            ':keys' => isset($in['has_keys']) ? (!empty($in['has_keys']) ? 1 : 0) : 1,
            ':wheels' => isset($in['wheels_lock']) ? (!empty($in['wheels_lock']) ? 1 : 0) : 1,
            ':accident' => !empty($in['is_accident']) ? 1 : 0,
            ':under' => !empty($in['is_underground']) ? 1 : 0,
            ':flatbed' => !empty($in['needs_flatbed']) ? 1 : 0,
            ':ev' => !empty($in['is_ev']) ? 1 : 0,
            ':cname' => $in['customer_name'] ?? null,
            ':cphone' => !empty($in['customer_phone']) ? normalizePhone($in['customer_phone']) : null,
            ':mode' => $pricingMode, ':offer' => money($offer), ':goa' => money($goa),
            ':sched' => $in['scheduled_for'] ?? null, ':exp' => $expiryMin,
            ':eta' => $in['eta_required_minutes'] ?? null,
        ]);
        $callId = (int)$pdo->lastInsertId();

        // Fund it now. A call nobody can get paid for isn't worth posting, and
        // "every call on this board is funded" is the reason towers show up.
        escrowHold($callId, (int)$user['account_id'], null, $offer);

        logCallEvent($callId, 'posted', 'Posted at $' . money($offer) . ' (' . $pricingMode . ')',
            (int)$user['account_id'], (int)$user['id']);

        $pdo->commit();
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        errorResponse($e->getMessage(), 402);   // 402 = fund your balance
    } catch (Throwable $e) {
        $pdo->rollBack();
        errorResponse('Could not post call: ' . $e->getMessage(), 500);
    }

    successResponse([
        'call_id' => $callId,
        'call_number' => $callNumber,
        'expires_in_minutes' => $expiryMin,
        'held_amount' => money($offer),
    ], 'Call posted to the board');
}

// ═══ THE BOARD (tower) ═══════════════════════════════════════════════════════
// Open calls the tower can actually run, nearest first.
if ($method === 'GET' && $action === 'board') {
    $user = requireAuth();
    requireAccountType($user, 'tower');
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT * FROM tower_profiles WHERE account_id = :a");
    $stmt->execute([':a' => $user['account_id']]);
    $profile = $stmt->fetch();
    if (!$profile) errorResponse('Tower profile not found', 404);

    $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : (float)$profile['base_lat'];
    $lng = isset($_GET['lng']) ? (float)$_GET['lng'] : (float)$profile['base_lng'];
    if (!$lat || !$lng) {
        errorResponse('Set your base location in your profile so we can show you nearby calls');
    }

    $radius = isset($_GET['radius'])
        ? (float)$_GET['radius']
        : (float)$profile['service_radius_miles'];
    $radius = max(1, min($radius, MAX_SEARCH_RADIUS_MILES));

    $box = boundingBox($lat, $lng, $radius);

    // Bounding box first (uses the index), exact distance after. Doing it the
    // other way round means a full table scan on every board refresh.
    $sql = "SELECT c.*, a.name AS provider_name, a.rating_avg AS provider_rating,
                   (SELECT COUNT(*) FROM bids b WHERE b.call_id = c.id AND b.status = 'pending') AS bid_count,
                   (SELECT b2.id FROM bids b2 WHERE b2.call_id = c.id AND b2.tower_account_id = :me LIMIT 1) AS my_bid_id
              FROM calls c
              JOIN accounts a ON c.provider_account_id = a.id
             WHERE c.status = 'open'
               AND c.expires_at > NOW()
               AND c.pickup_lat BETWEEN :minlat AND :maxlat
               AND c.pickup_lng BETWEEN :minlng AND :maxlng";

    $params = [
        ':me' => $user['account_id'],
        ':minlat' => $box['min_lat'], ':maxlat' => $box['max_lat'],
        ':minlng' => $box['min_lng'], ':maxlng' => $box['max_lng'],
    ];

    if (!empty($_GET['service_type'])) {
        $sql .= " AND c.service_type = :stype";
        $params[':stype'] = $_GET['service_type'];
    }
    if (!empty($_GET['min_amount'])) {
        $sql .= " AND c.offer_amount >= :minamt";
        $params[':minamt'] = (float)$_GET['min_amount'];
    }
    $sql .= " ORDER BY c.created_at DESC LIMIT 300";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll() as $c) {
        $distance = haversineMiles($lat, $lng, (float)$c['pickup_lat'], (float)$c['pickup_lng']);
        if ($distance > $radius) continue;

        // Capability gate — don't show what they can't run.
        $capable = true;
        foreach (requiredCapabilities($c) as $cap) {
            if (empty($profile[$cap])) { $capable = false; break; }
        }
        if (!$capable && empty($_GET['show_all'])) continue;

        $row = publicCallRow($c);
        $row['distance_miles'] = round($distance, 1);
        $row['bid_count']      = (int)$c['bid_count'];
        $row['already_bid']    = !empty($c['my_bid_id']);
        $row['can_run']        = $capable;
        $row['expires_in_sec'] = max(0, strtotime($c['expires_at']) - time());
        $out[] = $row;
    }

    // Closest first — for a 20-minute call, distance beats everything.
    usort($out, function ($a, $b) { return $a['distance_miles'] <=> $b['distance_miles']; });

    // The full step list rides along, not just a yes/no. The dashboard shows
    // documents / email / phone as a checklist, and it must agree with the rule
    // that will actually refuse the job rather than guessing at it.
    $verification = towerVerificationSteps((int)$user['account_id']);
    successResponse([
        'calls' => $out,
        'count' => count($out),
        'search' => ['lat' => $lat, 'lng' => $lng, 'radius_miles' => $radius],
        'can_accept' => $verification['ok'],
        'blocked_reason' => $verification['ok'] ? null : $verification['reason'],
        'verification' => $verification,
    ]);
}

// ═══ CALL DETAIL ═════════════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'detail') {
    $user = requireAuth();
    $callId = (int)($_GET['id'] ?? 0);
    if (!$callId) errorResponse('id is required');

    $pdo = getDB();
    $stmt = $pdo->prepare(
        "SELECT c.*, a.name AS provider_name, a.rating_avg AS provider_rating, a.phone AS provider_phone,
                t.name AS tower_name, t.phone AS tower_phone, t.rating_avg AS tower_rating
           FROM calls c
           JOIN accounts a ON c.provider_account_id = a.id
      LEFT JOIN accounts t ON c.awarded_tower_account_id = t.id
          WHERE c.id = :id"
    );
    $stmt->execute([':id' => $callId]);
    $call = $stmt->fetch();
    if (!$call) errorResponse('Call not found', 404);

    $isProvider = (int)$call['provider_account_id'] === (int)$user['account_id'];
    $isAwarded  = (int)$call['awarded_tower_account_id'] === (int)$user['account_id'];
    if (!$isProvider && !$isAwarded && $user['account_type'] !== 'tower') {
        errorResponse('Not authorised to view this call', 403);
    }

    $detail = publicCallRow($call, $isProvider || $isAwarded);
    $detail['awarded_amount'] = $call['awarded_amount'] !== null ? (float)$call['awarded_amount'] : null;
    $detail['awarded_eta_minutes'] = $call['awarded_eta_minutes'];
    $detail['tower_name'] = $call['tower_name'];
    $detail['tower_phone'] = ($isProvider || $isAwarded) ? $call['tower_phone'] : null;
    $detail['provider_phone'] = ($isProvider || $isAwarded) ? $call['provider_phone'] : null;

    $stmt = $pdo->prepare(
        "SELECT event_type, detail, lat, lng, created_at FROM call_events
          WHERE call_id = :c ORDER BY created_at ASC"
    );
    $stmt->execute([':c' => $callId]);
    $detail['timeline'] = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT photo_type, file_url, taken_at FROM call_photos WHERE call_id = :c");
    $stmt->execute([':c' => $callId]);
    $detail['photos'] = $stmt->fetchAll();

    if ($isProvider) {
        $stmt = $pdo->prepare(
            "SELECT b.*, a.name AS tower_name, a.rating_avg, a.rating_count, a.jobs_completed
               FROM bids b JOIN accounts a ON b.tower_account_id = a.id
              WHERE b.call_id = :c AND b.status = 'pending'
              ORDER BY b.amount ASC, b.eta_minutes ASC"
        );
        $stmt->execute([':c' => $callId]);
        $detail['bids'] = $stmt->fetchAll();
    }

    successResponse(['call' => $detail]);
}

// ═══ ACCEPT (tower, accept-mode) ═════════════════════════════════════════════
if ($method === 'POST' && $action === 'accept') {
    $user = requireAuth();
    requireAccountType($user, 'tower');
    requireVerified($user);
    $in = jsonInput();
    $callId = (int)($in['call_id'] ?? 0);
    if (!$callId) errorResponse('call_id is required');

    $eligibility = towerCanAccept((int)$user['account_id']);
    if (!$eligibility['ok']) errorResponse($eligibility['reason'], 403);

    $etaMinutes = (int)($in['eta_minutes'] ?? 0);
    if ($etaMinutes <= 0) errorResponse(t('err.eta_required'));

    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        // Lock the row. Two towers hitting accept on the same call at the same
        // second is the single most likely race on this whole platform.
        $stmt = $pdo->prepare("SELECT * FROM calls WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $callId]);
        $call = $stmt->fetch();

        if (!$call)                            throw new RuntimeException(t('err.job_not_found'));
        if ($call['status'] !== 'open')        throw new RuntimeException(t('err.job_taken'));
        if (strtotime($call['expires_at']) < time()) throw new RuntimeException(t('err.job_expired'));
        if ($call['pricing_mode'] !== 'accept') throw new RuntimeException('This call is bid-only — submit a bid instead');

        $pdo->prepare(
            "UPDATE calls
                SET status = 'awarded', awarded_tower_account_id = :t, awarded_amount = offer_amount,
                    awarded_eta_minutes = :eta, awarded_at = NOW()
              WHERE id = :id"
        )->execute([':t' => $user['account_id'], ':eta' => $etaMinutes, ':id' => $callId]);

        escrowAssignTower($callId, (int)$user['account_id']);

        logCallEvent($callId, 'awarded',
            $user['account_name'] . ' accepted at $' . money($call['offer_amount']) . ', ETA ' . $etaMinutes . ' min',
            (int)$user['account_id'], (int)$user['id']);

        // Off the board for everyone, and onto the customer's screen at once.
        rtJobClosed($callId, 'awarded');
        rtJobChanged($callId, $call['tracking_token'] ?? null, (int)$user['account_id'], 'awarded');

        notify((int)$call['provider_account_id'], 'call_awarded',
            'Call ' . $call['call_number'] . ' accepted',
            $user['account_name'] . ' is on the way. ETA ' . $etaMinutes . ' minutes.', $callId);

        $pdo->commit();
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        errorResponse($e->getMessage(), 409);
    } catch (Throwable $e) {
        $pdo->rollBack();
        errorResponse('Could not accept call: ' . $e->getMessage(), 500);
    }

    successResponse([
        'call_id' => $callId,
        'awarded_amount' => money($call['offer_amount']),
        'your_net' => money((float)$call['offer_amount'] - feeForCall($callId, (float)$call['offer_amount'])),
        'customer_name' => $call['customer_name'],
        'customer_phone' => $call['customer_phone'],
        'pickup_address' => $call['pickup_address'],
        'dropoff_address' => $call['dropoff_address'],
    ], t('ok.job_accepted'));
}

// ═══ AWARD A BID (provider) ══════════════════════════════════════════════════
if ($method === 'POST' && $action === 'award') {
    $user = requireAuth();
    requireAccountType($user, 'provider');
    $in = jsonInput();
    $bidId = (int)($in['bid_id'] ?? 0);
    if (!$bidId) errorResponse('bid_id is required');

    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT b.*, c.id AS cid, c.status AS cstatus, c.provider_account_id, c.call_number, c.offer_amount
               FROM bids b JOIN calls c ON b.call_id = c.id
              WHERE b.id = :id FOR UPDATE"
        );
        $stmt->execute([':id' => $bidId]);
        $bid = $stmt->fetch();

        if (!$bid) throw new RuntimeException('Bid not found');
        if ((int)$bid['provider_account_id'] !== (int)$user['account_id']) {
            throw new RuntimeException('That bid is not on one of your calls');
        }
        if ($bid['cstatus'] !== 'open')   throw new RuntimeException('This call is no longer open');
        if ($bid['status'] !== 'pending') throw new RuntimeException('That bid is no longer available');

        // The hold was placed at the offer amount. A bid above it would leave
        // the job underfunded, so it can't be awarded without more money down.
        if ((float)$bid['amount'] > (float)$bid['offer_amount'] + 0.001) {
            throw new RuntimeException('That bid is above the funded amount for this call');
        }

        $pdo->prepare(
            "UPDATE calls
                SET status = 'awarded', awarded_tower_account_id = :t, awarded_amount = :amt,
                    awarded_eta_minutes = :eta, awarded_at = NOW()
              WHERE id = :id"
        )->execute([
            ':t' => $bid['tower_account_id'], ':amt' => money($bid['amount']),
            ':eta' => $bid['eta_minutes'], ':id' => $bid['cid'],
        ]);

        $pdo->prepare("UPDATE bids SET status = 'accepted' WHERE id = :id")->execute([':id' => $bidId]);
        $pdo->prepare("UPDATE bids SET status = 'rejected' WHERE call_id = :c AND id <> :id AND status = 'pending'")
            ->execute([':c' => $bid['cid'], ':id' => $bidId]);

        escrowAssignTower((int)$bid['cid'], (int)$bid['tower_account_id']);

        logCallEvent((int)$bid['cid'], 'awarded',
            'Bid awarded at $' . money($bid['amount']) . ', ETA ' . $bid['eta_minutes'] . ' min',
            (int)$user['account_id'], (int)$user['id']);

        notify((int)$bid['tower_account_id'], 'bid_won',
            'You won call ' . $bid['call_number'],
            'Awarded at $' . money($bid['amount']) . '. Customer details are now unlocked.',
            (int)$bid['cid']);

        $pdo->commit();
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        errorResponse($e->getMessage(), 409);
    } catch (Throwable $e) {
        $pdo->rollBack();
        errorResponse('Could not award bid: ' . $e->getMessage(), 500);
    }

    successResponse(['call_id' => (int)$bid['cid'], 'awarded_amount' => money($bid['amount'])], 'Bid awarded');
}

// ═══ STATUS UPDATES (tower) ══════════════════════════════════════════════════
if ($method === 'POST' && $action === 'status') {
    $user = requireAuth();
    requireAccountType($user, 'tower');
    $in = jsonInput();
    $callId = (int)($in['call_id'] ?? 0);
    $status = $in['status'] ?? '';

    $allowed = ['en_route', 'on_scene', 'in_progress'];
    if (!$callId || !in_array($status, $allowed, true)) {
        errorResponse('call_id and a status of ' . implode('/', $allowed) . ' are required');
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM calls WHERE id = :id AND awarded_tower_account_id = :t");
    $stmt->execute([':id' => $callId, ':t' => $user['account_id']]);
    $call = $stmt->fetch();
    if (!$call) errorResponse('Call not found or not assigned to you', 404);

    if (in_array($call['status'], ['completed', 'canceled', 'goa', 'expired'], true)) {
        errorResponse(t('err.job_closed'), 409);
    }

    $timestampColumn = ['en_route' => 'en_route_at', 'on_scene' => 'on_scene_at', 'in_progress' => null];
    $sql = "UPDATE calls SET status = :s";
    if ($timestampColumn[$status]) $sql .= ", {$timestampColumn[$status]} = NOW()";
    $sql .= " WHERE id = :id";
    $pdo->prepare($sql)->execute([':s' => $status, ':id' => $callId]);

    logCallEvent($callId, $status, $in['note'] ?? null, (int)$user['account_id'], (int)$user['id'],
        isset($in['lat']) ? (float)$in['lat'] : null,
        isset($in['lng']) ? (float)$in['lng'] : null);

    rtJobChanged($callId, $call['tracking_token'] ?? null,
                 (int)$user['account_id'], $status);

    $labels = ['en_route' => 'Driver en route', 'on_scene' => 'Driver on scene', 'in_progress' => 'Service in progress'];
    notify((int)$call['provider_account_id'], 'call_status',
        $call['call_number'] . ' — ' . $labels[$status], $user['account_name'], $callId);

    successResponse(['status' => $status], t('ok.status_updated'));
}

// ═══ COMPLETE (tower) ════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'complete') {
    $user = requireAuth();
    requireAccountType($user, 'tower');
    $in = jsonInput();
    $callId = (int)($in['call_id'] ?? 0);
    if (!$callId) errorResponse('call_id is required');

    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM calls WHERE id = :id AND awarded_tower_account_id = :t FOR UPDATE");
        $stmt->execute([':id' => $callId, ':t' => $user['account_id']]);
        $call = $stmt->fetch();
        if (!$call) throw new RuntimeException('Call not found or not assigned to you');
        if ($call['status'] === 'completed') throw new RuntimeException(t('err.job_done'));
        if (in_array($call['status'], ['canceled', 'goa', 'expired'], true)) {
            throw new RuntimeException(t('err.job_closed'));
        }

        $amount = (float)$call['awarded_amount'];
        $result = escrowRelease($callId, $amount);

        $pdo->prepare(
            "UPDATE calls
                SET status = 'completed', completed_at = NOW(),
                    platform_fee = :fee, tower_net = :net
              WHERE id = :id"
        )->execute([':fee' => money($result['fee']), ':net' => money($result['net']), ':id' => $callId]);

        $pdo->prepare("UPDATE accounts SET jobs_completed = jobs_completed + 1 WHERE id = :a")
            ->execute([':a' => $user['account_id']]);

        // Consumer jobs are backed by a card authorisation rather than a
        // balance, so this is the moment the customer is actually charged.
        // Nothing before this point takes their money.
        if ($call['source'] === 'consumer' && $call['stripe_payment_intent_id']) {
            $cap = stripeCapturePayment($call['stripe_payment_intent_id'], $result['gross']);
            $pdo->prepare("UPDATE calls SET payment_status = :ps WHERE id = :id")
                ->execute([':ps' => $cap['ok'] ? 'captured' : 'failed', ':id' => $callId]);
            if (!$cap['ok']) {
                // Don't fail the driver's completion over a card problem — the
                // work is done. Flag it and chase the payment separately.
                logCallEvent($callId, 'payment_failed', $cap['error'] ?? 'Card capture failed');
            }
        }

        logCallEvent($callId, 'completed',
            'Completed — $' . money($result['gross']) . ' released, $' . money($result['net']) . ' net to tower',
            (int)$user['account_id'], (int)$user['id']);

        rtJobChanged($callId, $call['tracking_token'] ?? null, (int)$user['account_id'], 'completed');

        notify((int)$call['provider_account_id'], 'call_completed',
            $call['call_number'] . ' completed',
            $user['account_name'] . ' completed the call. $' . money($result['gross']) . ' released from your balance.',
            $callId);

        $pdo->commit();
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        errorResponse($e->getMessage(), 409);
    } catch (Throwable $e) {
        $pdo->rollBack();
        errorResponse('Could not complete call: ' . $e->getMessage(), 500);
    }

    successResponse([
        'gross' => money($result['gross']),
        'platform_fee' => money($result['fee']),
        'net_to_you' => money($result['net']),
        'payout_id' => $result['payout_id'],
    ], t('ok.job_completed'));
}

// ═══ GOA — GONE ON ARRIVAL (tower) ═══════════════════════════════════════════
// The tower drove out and the vehicle wasn't there. They earned the GOA fee,
// the provider gets the rest back. Photo proof required by default because
// this is the number one thing both sides argue about.
if ($method === 'POST' && $action === 'goa') {
    $user = requireAuth();
    requireAccountType($user, 'tower');
    $in = jsonInput();
    $callId = (int)($in['call_id'] ?? 0);
    if (!$callId) errorResponse('call_id is required');

    $pdo = getDB();

    if ((string)setting('goa_requires_photo', '1') === '1') {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS n FROM call_photos WHERE call_id = :c AND photo_type IN ('arrival','goa')"
        );
        $stmt->execute([':c' => $callId]);
        if ((int)$stmt->fetch()['n'] === 0) {
            errorResponse(t('err.goa_photo'), 422);
        }
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM calls WHERE id = :id AND awarded_tower_account_id = :t FOR UPDATE");
        $stmt->execute([':id' => $callId, ':t' => $user['account_id']]);
        $call = $stmt->fetch();
        if (!$call) throw new RuntimeException('Call not found or not assigned to you');
        if (in_array($call['status'], ['completed', 'canceled', 'goa', 'expired'], true)) {
            throw new RuntimeException(t('err.job_closed'));
        }

        $goa = (float)$call['goa_amount'];
        if ($goa <= 0) throw new RuntimeException(t('err.goa_no_amount'));

        $result = escrowPartialRelease($callId, $goa, 'GOA');

        $pdo->prepare(
            "UPDATE calls SET status = 'goa', completed_at = NOW(), platform_fee = :fee, tower_net = :net
              WHERE id = :id"
        )->execute([':fee' => money($result['fee']), ':net' => money($result['tower_net']), ':id' => $callId]);

        $pdo->prepare("UPDATE accounts SET jobs_goa = jobs_goa + 1 WHERE id = :a")
            ->execute([':a' => $user['account_id']]);

        if ($call['source'] === 'consumer' && $call['stripe_payment_intent_id']) {
            $cap = stripeCapturePayment($call['stripe_payment_intent_id'], $result['tower_gross']);
            $pdo->prepare("UPDATE calls SET payment_status = :ps WHERE id = :id")
                ->execute([':ps' => $cap['ok'] ? 'captured' : 'failed', ':id' => $callId]);
        }

        logCallEvent($callId, 'goa', $in['note'] ?? 'Vehicle not on scene',
            (int)$user['account_id'], (int)$user['id'],
            isset($in['lat']) ? (float)$in['lat'] : null,
            isset($in['lng']) ? (float)$in['lng'] : null);

        notify((int)$call['provider_account_id'], 'call_goa',
            $call['call_number'] . ' — GOA claimed',
            $user['account_name'] . ' reported the vehicle was gone. $' . money($result['tower_gross']) .
            ' GOA fee applied, $' . money($result['provider_refund']) . ' returned to your balance.', $callId);

        $pdo->commit();
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        errorResponse($e->getMessage(), 409);
    } catch (Throwable $e) {
        $pdo->rollBack();
        errorResponse('Could not process GOA: ' . $e->getMessage(), 500);
    }

    successResponse([
        'goa_amount' => money($result['tower_gross']),
        'net_to_you' => money($result['tower_net']),
        'returned_to_provider' => money($result['provider_refund']),
    ], t('ok.goa_recorded'));
}

// ═══ CANCEL (provider) ═══════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'cancel') {
    $user = requireAuth();
    requireAccountType($user, 'provider');
    $in = jsonInput();
    $callId = (int)($in['call_id'] ?? 0);
    if (!$callId) errorResponse('call_id is required');

    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM calls WHERE id = :id AND provider_account_id = :p FOR UPDATE");
        $stmt->execute([':id' => $callId, ':p' => $user['account_id']]);
        $call = $stmt->fetch();
        if (!$call) throw new RuntimeException(t('err.job_not_found'));
        if (in_array($call['status'], ['completed', 'canceled', 'goa', 'expired'], true)) {
            throw new RuntimeException(t('err.job_closed'));
        }

        // Cancelling on a tower who is already rolling isn't free. They get the
        // GOA amount; without that rule nobody would ever accept a call.
        $towerCompensated = 0.0;
        if (in_array($call['status'], ['en_route', 'on_scene', 'in_progress'], true)
            && $call['awarded_tower_account_id']) {
            $goa = (float)$call['goa_amount'];
            if ($goa > 0) {
                $r = escrowPartialRelease($callId, $goa, 'Provider canceled after dispatch');
                $towerCompensated = $r['tower_gross'];
            } else {
                escrowRefund($callId, 'Provider canceled');
            }
        } else {
            escrowRefund($callId, 'Provider canceled');
        }

        $pdo->prepare(
            "UPDATE calls SET status = 'canceled', canceled_at = NOW(), cancel_reason = :r WHERE id = :id"
        )->execute([':r' => $in['reason'] ?? null, ':id' => $callId]);

        $pdo->prepare("UPDATE bids SET status = 'rejected' WHERE call_id = :c AND status = 'pending'")
            ->execute([':c' => $callId]);

        logCallEvent($callId, 'canceled', $in['reason'] ?? null, (int)$user['account_id'], (int)$user['id']);

        if ($call['awarded_tower_account_id']) {
            notify((int)$call['awarded_tower_account_id'], 'call_canceled',
                $call['call_number'] . ' canceled',
                $towerCompensated > 0
                    ? 'The provider canceled. $' . money($towerCompensated) . ' has been credited to you.'
                    : 'The provider canceled this call.',
                $callId);
        }

        $pdo->commit();
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        errorResponse($e->getMessage(), 409);
    } catch (Throwable $e) {
        $pdo->rollBack();
        errorResponse('Could not cancel: ' . $e->getMessage(), 500);
    }

    successResponse(['tower_compensated' => money($towerCompensated)], t('ok.job_canceled'));
}

// ═══ MY CALLS ════════════════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'my-calls') {
    $user = requireAuth();
    $pdo = getDB();

    $status = $_GET['status'] ?? '';
    $limit = min((int)($_GET['limit'] ?? 50), 200);
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    if ($user['account_type'] === 'provider') {
        $sql = "SELECT c.*, t.name AS tower_name, t.rating_avg AS tower_rating,
                       (SELECT COUNT(*) FROM bids b WHERE b.call_id = c.id AND b.status = 'pending') AS bid_count
                  FROM calls c LEFT JOIN accounts t ON c.awarded_tower_account_id = t.id
                 WHERE c.provider_account_id = :a";
    } else {
        $sql = "SELECT c.*, a.name AS provider_name, a.rating_avg AS provider_rating, NULL AS bid_count
                  FROM calls c JOIN accounts a ON c.provider_account_id = a.id
                 WHERE c.awarded_tower_account_id = :a";
    }
    $params = [':a' => $user['account_id']];

    if ($status === 'active') {
        $sql .= " AND c.status IN ('open','awarded','en_route','on_scene','in_progress')";
    } elseif ($status !== '') {
        $sql .= " AND c.status = :s";
        $params[':s'] = $status;
    }
    $sql .= " ORDER BY c.created_at DESC LIMIT $limit OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll() as $c) {
        $row = publicCallRow($c, true);
        $row['awarded_amount'] = $c['awarded_amount'] !== null ? (float)$c['awarded_amount'] : null;
        $row['tower_net'] = $c['tower_net'] !== null ? (float)$c['tower_net'] : null;
        $row['platform_fee'] = $c['platform_fee'] !== null ? (float)$c['platform_fee'] : null;
        $row['tower_name'] = $c['tower_name'] ?? null;
        $row['bid_count'] = isset($c['bid_count']) ? (int)$c['bid_count'] : null;
        $out[] = $row;
    }
    successResponse(['calls' => $out, 'count' => count($out)]);
}

// ═══ EXPIRY SWEEP (cron) ═════════════════════════════════════════════════════
// Open calls nobody took. Refund the hold and clear them off the board —
// otherwise providers' money sits frozen behind dead calls.
if ($action === 'expire-sweep') {
    $pdo = getDB();
    $stmt = $pdo->query(
        "SELECT id, call_number, provider_account_id, source, stripe_payment_intent_id
           FROM calls
          WHERE status = 'open' AND expires_at < NOW() LIMIT 500"
    );
    $expired = 0;
    foreach ($stmt->fetchAll() as $call) {
        try {
            $pdo->beginTransaction();
            escrowRefund((int)$call['id'], 'Call expired with no taker');
            // Nobody came, so the customer must not be charged. Release the
            // authorisation rather than leaving it sitting on their card.
            if ($call['source'] === 'consumer' && $call['stripe_payment_intent_id']) {
                stripeCancelPayment($call['stripe_payment_intent_id']);
                $pdo->prepare("UPDATE calls SET payment_status = 'refunded' WHERE id = :id")
                    ->execute([':id' => $call['id']]);
            }
            $pdo->prepare("UPDATE calls SET status = 'expired' WHERE id = :id")->execute([':id' => $call['id']]);
            $pdo->prepare("UPDATE bids SET status = 'expired' WHERE call_id = :c AND status = 'pending'")
                ->execute([':c' => $call['id']]);
            logCallEvent((int)$call['id'], 'expired', 'No tower accepted before expiry');
            rtJobClosed((int)$call['id'], 'expired');

        notify((int)$call['provider_account_id'], 'call_expired',
                $call['call_number'] . ' expired',
                'No tower accepted this call. Your funds have been returned to your balance.',
                (int)$call['id']);
            $pdo->commit();
            $expired++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }
    successResponse(['expired' => $expired]);
}

errorResponse('Unknown action', 404);
