<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/realtime.php';
require_once __DIR__ . '/../includes/escrow.php';
require_once __DIR__ . '/../includes/matching.php';
require_once __DIR__ . '/../includes/stripe_connect.php';
require_once __DIR__ . '/../includes/sweep.php';
require_once __DIR__ . '/../includes/settlement.php';
require_once __DIR__ . '/../includes/photos.php';
// The photo endpoints need both: isAdminRequest() to let an admin see any
// photo, and complianceFilePath() to resolve a stored path safely. Serving
// fataled with an empty 500 without them — photos.php pulls in uploads.php but
// nothing was pulling in adminauth.
require_once __DIR__ . '/../includes/adminauth.php';
require_once __DIR__ . '/../includes/geocode.php';   // requestIp()
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

    // No cron on this host. The board is the busiest authenticated page in the
    // product, so it is where the expiry sweep gets its heartbeat — a tower
    // refreshing his jobs is what releases a customer's card hold on a job
    // nobody took. Rate-limited inside; costs nothing when it is not due.
    runSweepIfDue();

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

    // file_url is left over from before there was anywhere to upload to; the
    // real ones come back as endpoint URLs that check who is asking.
    $detail['photos'] = photoList($callId);
    $detail['photo_state'] = photoState($callId);

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

        // The board HIDES jobs a company cannot run; hiding is not refusing.
        // `?show_all=1` returns them with can_run:false, and nothing stopped a
        // POST straight to this endpoint. A wheel-lift accepting a semi means a
        // truck turns out that physically cannot do the work, while the
        // customer waits and every other qualified company stops seeing the job.
        $prof = $pdo->prepare("SELECT * FROM tower_profiles WHERE account_id = :a");
        $prof->execute([':a' => $user['account_id']]);
        $profile = $prof->fetch();
        if (!$profile || !towerIsCapable($profile, $call)) {
            throw new RuntimeException(t('err.not_capable'));
        }

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

        // Close the job and NOTHING else. No Stripe call inside this
        // transaction: a capture that succeeded on a transaction that then
        // rolled back would charge a customer for a job the database still
        // believed was open, with nothing anywhere to refund it. Committing
        // first also means this row stops being completable by anybody else,
        // so the capture below cannot be raced into happening twice.
        // Written down, not enforced. Refusing to let a driver close a job at
        // 2am over a missing photograph strands his payout and earns a phone
        // call; he is warned hard on the way in, and if he goes ahead anyway
        // that fact is recorded. A damage claim three weeks later is then
        // answerable either way — here are the photographs, or here is the
        // record that he was told and chose not to take them.
        $photos = photoState($callId);

        $pdo->prepare(
            "UPDATE calls SET status = 'completed', completed_at = NOW(),
                    photos_complete = :pc
              WHERE id = :id"
        )->execute([':pc' => $photos['complete'] ? 1 : 0, ':id' => $callId]);

        if (!$photos['complete']) {
            logCallEvent($callId, 'photos_missing',
                'Completed without: ' . $photos['missing_summary'],
                (int)$user['account_id'], (int)$user['id']);
        }

        $pdo->prepare("UPDATE accounts SET jobs_completed = jobs_completed + 1 WHERE id = :a")
            ->execute([':a' => $user['account_id']]);

        logCallEvent($callId, 'completed', 'Job completed by the driver',
            (int)$user['account_id'], (int)$user['id']);

        $pdo->commit();
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        errorResponse($e->getMessage(), 409);
    } catch (Throwable $e) {
        $pdo->rollBack();
        errorResponse('Could not complete call: ' . $e->getMessage(), 500);
    }

    // ─── Now the money ──────────────────────────────────────────────────────
    // Outside the transaction, and after the job is safely closed. If this
    // fails the work still counts as done — the driver did it — and the job
    // sits completed-but-unsettled for an admin to retry, rather than the
    // tower being queued for a payout against a charge that never landed.
    $settle = settleCall($callId, 'complete', $amount);

    if (!$settle['ok']) {
        rtJobChanged($callId, $call['tracking_token'] ?? null, (int)$user['account_id'], 'completed');
        successResponse([
            'settled'        => false,
            'payment_issue'  => true,
        ], t('ok.job_done_payment_pending'));
    }

    $pdo->prepare("UPDATE calls SET platform_fee = :fee, tower_net = :net WHERE id = :id")
        ->execute([':fee' => money($settle['fee']), ':net' => money($settle['net']), ':id' => $callId]);

    logCallEvent($callId, 'settled',
        'Settled — $' . money($settle['gross']) . ' collected, $' . money($settle['net']) . ' net to tower');

    rtJobChanged($callId, $call['tracking_token'] ?? null, (int)$user['account_id'], 'completed');

    // A consumer job's "provider" IS the stranded motorist, who has no balance
    // and would be baffled to be told money was released from one.
    if (($call['source'] ?? 'board') !== 'consumer') {
        notify((int)$call['provider_account_id'], 'call_completed',
            $call['call_number'] . ' completed',
            $user['account_name'] . ' completed the call. $' . money($settle['gross']) . ' released from your balance.',
            $callId);
    }

    successResponse([
        'settled'      => true,
        'gross'        => money($settle['gross']),
        'platform_fee' => money($settle['fee']),
        'net_to_you'   => money($settle['net']),
        'payout_id'    => $settle['payout_id'],
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

    // A photograph of the empty space, taken on scene. Until job photos could
    // be uploaded at all this rule made GOA impossible rather than careful —
    // it demanded proof there was no way to provide.
    if ((string)setting('goa_requires_photo', '1') === '1') {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS n FROM call_photos
              WHERE call_id = :c AND photo_type IN ('goa','arrival')"
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

        // Close it first, settle after — same reasoning as completion. A GOA
        // charges the call-out fee only, and a partial capture is exactly how
        // that is expressed: Stripe takes the $55 and releases the rest of the
        // authorisation back to the customer by itself. There is no separate
        // refund to make, and making one would return money twice.
        $pdo->prepare(
            "UPDATE calls SET status = 'goa', completed_at = NOW() WHERE id = :id"
        )->execute([':id' => $callId]);

        $pdo->prepare("UPDATE accounts SET jobs_goa = jobs_goa + 1 WHERE id = :a")
            ->execute([':a' => $user['account_id']]);

        // The claim itself carries where it was made from and over what
        // connection, alongside the photograph. A GOA is money moving on one
        // person's say-so, and this is what makes it answerable later.
        logCallEvent($callId, 'goa',
            mb_substr(($in['note'] ?? 'Vehicle not on scene')
                . ' — claimed from ' . requestIp()
                . (isset($in['lat'], $in['lng'])
                    ? sprintf(' at %.5f, %.5f', (float)$in['lat'], (float)$in['lng'])
                    : ' (no position from the phone)'), 0, 500),
            (int)$user['account_id'], (int)$user['id'],
            isset($in['lat']) ? (float)$in['lat'] : null,
            isset($in['lng']) ? (float)$in['lng'] : null);

        $pdo->commit();
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        errorResponse($e->getMessage(), 409);
    } catch (Throwable $e) {
        $pdo->rollBack();
        errorResponse('Could not process GOA: ' . $e->getMessage(), 500);
    }

    // ─── Now the money ──────────────────────────────────────────────────────
    $settle = settleCall($callId, 'goa', $goa);

    if (!$settle['ok']) {
        rtJobChanged($callId, $call['tracking_token'] ?? null, (int)$user['account_id'], 'goa');
        successResponse([
            'settled'       => false,
            'payment_issue' => true,
        ], t('ok.goa_recorded_payment_pending'));
    }

    getDB()->prepare("UPDATE calls SET platform_fee = :fee, tower_net = :net WHERE id = :id")
        ->execute([':fee' => money($settle['fee']), ':net' => money($settle['net']), ':id' => $callId]);

    logCallEvent($callId, 'settled',
        'GOA settled — $' . money($settle['gross']) . ' collected, $' . money($settle['net']) . ' net to tower');

    rtJobChanged($callId, $call['tracking_token'] ?? null, (int)$user['account_id'], 'goa');

    if (($call['source'] ?? 'board') !== 'consumer') {
        notify((int)$call['provider_account_id'], 'call_goa',
            $call['call_number'] . ' — GOA claimed',
            $user['account_name'] . ' reported the vehicle was gone. $' . money($settle['gross']) .
            ' GOA fee applied, $' . money($settle['refund']) . ' returned to your balance.', $callId);
    }

    successResponse([
        'settled'    => true,
        'goa_amount' => money($settle['gross']),
        'net_to_you' => money($settle['net']),
        'returned_to_provider' => money($settle['refund']),
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

    // SELECT and WHERE are kept apart so the totals below can reuse the exact
    // same filter. Summing the rows this query returns would total ONE PAGE —
    // a driver who filters to a month with 60 jobs in it would be shown the
    // first 50 and no indication that is what he is looking at. A wrong total
    // that looks right is worse than no total.
    if ($user['account_type'] === 'provider') {
        $select = "SELECT c.*, t.name AS tower_name, t.rating_avg AS tower_rating,
                          (SELECT COUNT(*) FROM bids b WHERE b.call_id = c.id AND b.status = 'pending') AS bid_count
                     FROM calls c LEFT JOIN accounts t ON c.awarded_tower_account_id = t.id";
        $where  = " WHERE c.provider_account_id = :a";
    } else {
        $select = "SELECT c.*, a.name AS provider_name, a.rating_avg AS provider_rating, NULL AS bid_count
                     FROM calls c JOIN accounts a ON c.provider_account_id = a.id";
        $where  = " WHERE c.awarded_tower_account_id = :a";
    }
    $params = [':a' => $user['account_id']];

    if ($status === 'active') {
        $where .= " AND c.status IN ('open','awarded','en_route','on_scene','in_progress')";
    } elseif ($status === 'closed') {
        // Everything finished, one way or another. A driver looking back
        // through his week does not think of "completed" and "gone on arrival"
        // as different lists — they are both jobs he turned out for.
        $where .= " AND c.status IN ('completed','goa','canceled','expired')";
    } elseif ($status !== '') {
        $where .= " AND c.status = :s";
        $params[':s'] = $status;
    }

    // Free-text search across the things somebody actually remembers about a
    // job: its number, where it was, the vehicle, the customer's name.
    //
    // LIKE with a leading wildcard cannot use an index, which is fine at this
    // size and honest about why: an operator searching his own history is
    // scanning at most a few thousand rows, already filtered to his account.
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $where .= " AND (c.call_number LIKE :q OR c.pickup_address LIKE :q
                       OR c.pickup_city LIKE :q OR c.dropoff_address LIKE :q
                       OR c.dropoff_city LIKE :q OR c.customer_name LIKE :q
                       OR c.vehicle_make LIKE :q OR c.vehicle_model LIKE :q
                       OR c.vehicle_plate LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }

    // Date window, inclusive. `to` is compared against the end of that day so
    // picking the same date twice returns that day's work rather than nothing.
    if (!empty($_GET['from'])) {
        $where .= " AND c.created_at >= :from";
        $params[':from'] = substr((string)$_GET['from'], 0, 10) . ' 00:00:00';
    }
    if (!empty($_GET['to'])) {
        $where .= " AND c.created_at <= :to";
        $params[':to'] = substr((string)$_GET['to'], 0, 10) . ' 23:59:59';
    }
    if (!empty($_GET['service_type'])) {
        $where .= " AND c.service_type = :svc";
        $params[':svc'] = (string)$_GET['service_type'];
    }

    $sql = $select . $where . " ORDER BY c.created_at DESC LIMIT $limit OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll() as $c) {
        $row = publicCallRow($c, true);
        $row['awarded_amount'] = $c['awarded_amount'] !== null ? (float)$c['awarded_amount'] : null;
        $row['tower_net'] = $c['tower_net'] !== null ? (float)$c['tower_net'] : null;
        $row['platform_fee'] = $c['platform_fee'] !== null ? (float)$c['platform_fee'] : null;
        $row['tower_name'] = $c['tower_name'] ?? null;
        // When it finished. publicCallRow() carries created_at but not this,
        // and a history list sorted by when the work happened needs it.
        $row['completed_at'] = $c['completed_at'] ?? null;
        // The photo checklist rides along with each of the company's own jobs.
        // Fetching it per card afterwards would be one request per job on a
        // screen a driver refreshes constantly.
        if (($c['awarded_tower_account_id'] ?? null) !== null) {
            $row['photo_state'] = photoState((int)$c['id']);
            $row['photos']      = photoList((int)$c['id']);
        }
        $row['bid_count'] = isset($c['bid_count']) ? (int)$c['bid_count'] : null;
        $out[] = $row;
    }
    // Totals over the WHOLE filtered set, not the page above.
    //
    // Only completed and GOA jobs count towards earnings: a canceled or expired
    // job is not money, and rolling it in would show a driver a figure he can
    // never withdraw. tower_net is read from the row rather than recomputed,
    // so this agrees with what was actually paid.
    // Earnings come from PAYOUTS, not from calls.tower_net.
    //
    // payouts is what the balance and the withdrawal button are built from, so
    // it is the only figure a driver can reconcile against what lands in his
    // bank. calls.tower_net is a denormalised copy that went unwritten for a
    // while, and a history total that disagrees with the Money screen is worse
    // than no total at all.
    //
    // Correlated subqueries rather than a JOIN: two payout rows against one
    // call would double every count in the same SELECT.
    $paid  = "(SELECT SUM(p.%s) FROM payouts p
                WHERE p.call_id = c.id AND p.tower_account_id = c.awarded_tower_account_id)";
    $money = $user['account_type'] === 'provider'
        ? "SUM(CASE WHEN c.status IN ('completed','goa') THEN c.awarded_amount ELSE 0 END) AS gross,
           0 AS net, 0 AS fees"
        : sprintf("COALESCE(SUM(%s), 0) AS gross, COALESCE(SUM(%s), 0) AS net, COALESCE(SUM(%s), 0) AS fees",
                  sprintf($paid, 'gross_amount'), sprintf($paid, 'net_amount'), sprintf($paid, 'platform_fee'));

    $tot = $pdo->prepare(
        "SELECT COUNT(*) AS jobs,
                SUM(c.status IN ('completed','goa')) AS paid_jobs,
                SUM(c.status = 'goa')      AS goa_jobs,
                SUM(c.status = 'canceled') AS canceled_jobs,
                $money
           FROM calls c" . $where
    );
    $tot->execute($params);
    $t = $tot->fetch() ?: [];

    successResponse([
        'calls'  => $out,
        'count'  => count($out),
        // count() is this page; totals.jobs is every job matching the filter.
        // Named differently on purpose so no screen can mistake one for the other.
        'totals' => [
            'jobs'          => (int)($t['jobs'] ?? 0),
            'paid_jobs'     => (int)($t['paid_jobs'] ?? 0),
            'goa_jobs'      => (int)($t['goa_jobs'] ?? 0),
            'canceled_jobs' => (int)($t['canceled_jobs'] ?? 0),
            'net'           => round((float)($t['net'] ?? 0), 2),
            'gross'         => round((float)($t['gross'] ?? 0), 2),
            'fees'          => round((float)($t['fees'] ?? 0), 2),
        ],
    ]);
}

// ═══ JOB PHOTOS ══════════════════════════════════════════════════════════════
// The evidence a damage claim is answered with. call_photos has existed since
// the first schema with nothing able to write to it — which also meant
// goa_requires_photo demanded a photograph that could not be taken, so a driver
// who turned out to an empty space could not claim the fee he had earned.
if ($method === 'POST' && $action === 'photo') {
    $user = requireAuth();
    requireAccountType($user, 'tower');

    $callId = (int)($_POST['call_id'] ?? 0);
    $type   = (string)($_POST['photo_type'] ?? '');
    if (!$callId) errorResponse('call_id is required');
    if (!photoTypeIsValid($type)) errorResponse(t('err.photo_type'));

    $pdo = getDB();
    $stmt = $pdo->prepare(
        "SELECT id, status, awarded_tower_account_id FROM calls WHERE id = :id"
    );
    $stmt->execute([':id' => $callId]);
    $call = $stmt->fetch();
    if (!$call) errorResponse(t('err.job_not_found'), 404);

    // Only the company running the job. Photographs of a stranger's plate and
    // VIN are not something any signed-in operator gets to add to any job.
    if ((int)$call['awarded_tower_account_id'] !== (int)$user['account_id']) {
        errorResponse(t('err.no_permission'), 403);
    }
    // Closed jobs stay closed. Allowing photos onto a finished job would let
    // evidence be added after a dispute had already started.
    if (in_array($call['status'], ['canceled', 'expired'], true)) {
        errorResponse(t('err.job_closed'), 409);
    }

    $stored = storeComplianceFile($_FILES['file'] ?? [], (int)$user['account_id']);
    if (empty($stored['ok'])) errorResponse($stored['error'], 400);

    // One shot per required slot. Re-taking replaces rather than stacking, so
    // the checklist cannot read "done" off a blurry first attempt while a good
    // one sits underneath it — but the extra types stack freely, because
    // "damage" is genuinely many photographs.
    if (!in_array($type, photoExtraTypes(), true)) {
        $pdo->prepare("DELETE FROM call_photos WHERE call_id = :c AND photo_type = :t")
            ->execute([':c' => $callId, ':t' => $type]);
    }

    // Taken here, or picked out of the library. Whitelisted rather than
    // trusted: this is the field that says how much the photograph is worth as
    // evidence, so a client that sends anything else is recorded as 'unknown'
    // rather than being believed.
    $source = (string)($_POST['source'] ?? 'unknown');
    if (!in_array($source, ['camera', 'library'], true)) $source = 'unknown';

    $pdo->prepare(
        "INSERT INTO call_photos
            (call_id, account_id, uploaded_by_user_id, photo_type, source, file_url,
             stored_path, mime_type, file_size, note, lat, lng, ip_address,
             accuracy_m, taken_at)
         VALUES (:c, :a, :u, :t, :src, '', :p, :m, :sz, :n, :lat, :lng, :ip, :acc, NOW())"
    )->execute([
        ':c' => $callId, ':a' => $user['account_id'], ':u' => $user['id'],
        ':t' => $type, ':src' => $source,
        ':p' => $stored['path'], ':m' => $stored['mime'],
        ':sz' => $stored['size'],
        ':n' => isset($_POST['note']) ? mb_substr((string)$_POST['note'], 0, 255) : null,
        // Where and from what connection. The coordinates come from the phone
        // and the IP from the request, so neither is worth much alone — but a
        // photograph of an empty parking space that also says it was taken at
        // that space, from that connection, at that minute is evidence rather
        // than a picture of some tarmac.
        ':lat' => isset($_POST['lat']) ? (float)$_POST['lat'] : null,
        ':lng' => isset($_POST['lng']) ? (float)$_POST['lng'] : null,
        ':ip'  => requestIp(),
        ':acc' => isset($_POST['accuracy_m']) ? (int)$_POST['accuracy_m'] : null,
    ]);
    $photoId = (int)$pdo->lastInsertId();

    logCallEvent($callId, 'photo', photoLabel($type) . ' photographed',
        (int)$user['account_id'], (int)$user['id']);

    successResponse([
        'photo_id' => $photoId,
        'photos'   => photoState($callId),
    ], t('ok.photo_saved'));
}

// Serving one. Never a direct link: these are pictures of a stranger's vehicle,
// plate and VIN, so the file goes out only to the company that took it, the
// customer's own provider account, or an admin.
if ($method === 'GET' && $action === 'photo') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) { http_response_code(404); exit; }

    $stmt = getDB()->prepare(
        "SELECT p.*, c.awarded_tower_account_id, c.provider_account_id
           FROM call_photos p JOIN calls c ON c.id = p.call_id
          WHERE p.id = :id"
    );
    $stmt->execute([':id' => $id]);
    $photo = $stmt->fetch();
    if (!$photo) { http_response_code(404); exit; }

    $allowed = false;
    if (isAdminRequest()) {
        $allowed = true;
    } else {
        $token  = bearerToken();
        $claims = $token ? verifyJWT($token) : null;
        $acct   = (int)($claims['account_id'] ?? 0);
        if ($claims && ($claims['kind'] ?? '') !== 'admin' && $acct > 0
            && ($acct === (int)$photo['awarded_tower_account_id']
             || $acct === (int)$photo['provider_account_id'])) {
            $allowed = true;
        }
    }
    if (!$allowed) { http_response_code(403); exit; }

    $path = complianceFilePath($photo['stored_path']);
    if (!$path) { http_response_code(404); exit; }

    header('Content-Type: ' . ($photo['mime_type'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    // Private: this is somebody's vehicle and number plate, not a public asset.
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

// ═══ EXPIRY SWEEP ════════════════════════════════════════════════════════════
// The work now lives in includes/sweep.php so that the same code runs whether
// it is reached from here or from runSweepIfDue() riding along on ordinary
// traffic. This endpoint stays so a real cron can be pointed at it.
if ($action === 'expire-sweep') {
    // Guarded. It was reachable by anybody who typed the URL, and it cancels
    // Stripe authorisations and closes jobs — not something a stranger should
    // be able to fire at will. The sweep no longer NEEDS calling from outside
    // (runSweepIfDue rides on ordinary traffic), so this exists only for a real
    // cron, and a cron can carry a secret.
    $given = $_GET['key'] ?? ($_SERVER['HTTP_X_SWEEP_KEY'] ?? '');
    $want  = (string)setting('sweep_key', '');
    if ($want === '' || !hash_equals($want, (string)$given)) {
        errorResponse(t('err.no_permission'), 403);
    }
    successResponse(runSweep());
}

errorResponse('Unknown action', 404);
