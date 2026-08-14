<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/escrow.php';
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/stripe_connect.php';
setCorsHeaders();

// ═══════════════════════════════════════════════════════════════════════════
//  DIRECT-TO-CONSUMER — the stranded motorist
//
//  Nothing here requires a login. Someone whose car just died on the Palmetto
//  at 11pm is not going to create an account, verify an email and remember a
//  password. They tap a Google ad, see a price, enter a card, and a truck comes.
//
//  Their identity is the tracking token issued at request time: unguessable,
//  emailed and texted to them, and the only key to their own job.
// ═══════════════════════════════════════════════════════════════════════════

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ═══ QUOTE — public, no auth, no obligation ══════════════════════════════════
if ($action === 'quote') {
    $in = $method === 'POST' ? jsonInput() : $_GET;

    $pickupLat  = isset($in['pickup_lat'])  ? (float)$in['pickup_lat']  : null;
    $pickupLng  = isset($in['pickup_lng'])  ? (float)$in['pickup_lng']  : null;
    $dropoffLat = isset($in['dropoff_lat']) ? (float)$in['dropoff_lat'] : null;
    $dropoffLng = isset($in['dropoff_lng']) ? (float)$in['dropoff_lng'] : null;

    if ($pickupLat === null || $pickupLng === null) {
        errorResponse(t('err.need_location'));
    }
    if ($msg = outsideLaunchArea($pickupLat, $pickupLng)) errorResponse($msg, 422);

    $service = in_array($in['service_type'] ?? 'tow',
        ['tow','winch_recovery','lockout','jumpstart','tire_change','fuel_delivery'], true)
        ? $in['service_type'] : 'tow';

    $miles = 0.0;
    if ($service === 'tow' && $dropoffLat !== null && $dropoffLng !== null) {
        $miles = round(haversineMiles($pickupLat, $pickupLng, $dropoffLat, $dropoffLng), 1);
    }

    $quote = quoteConsumerJob([
        'service_type'   => $service,
        'vehicle_class'  => in_array($in['vehicle_class'] ?? 'light',
                              ['light','medium','heavy','motorcycle'], true) ? $in['vehicle_class'] : 'light',
        'tow_miles'      => $miles,
        'is_accident'    => !empty($in['is_accident']),
        'has_keys'       => array_key_exists('has_keys', $in) ? !empty($in['has_keys']) : true,
        'wheels_lock'    => array_key_exists('wheels_lock', $in) ? !empty($in['wheels_lock']) : true,
        'is_underground' => !empty($in['is_underground']),
    ]);
    if (empty($quote['ok'])) errorResponse(t('err.no_pricing'), 422);

    // How many trucks could actually take this right now. An honest count beats
    // a price for a job nobody will run.
    $box = boundingBox($pickupLat, $pickupLng, 30);
    $stmt = getDB()->prepare(
        "SELECT COUNT(*) AS n
           FROM accounts a JOIN tower_profiles tp ON tp.account_id = a.id
          WHERE a.account_type = 'tower' AND a.is_active = 1
            AND a.verification_status = 'approved'
            AND tp.base_lat BETWEEN :minlat AND :maxlat
            AND tp.base_lng BETWEEN :minlng AND :maxlng"
    );
    $stmt->execute([
        ':minlat' => $box['min_lat'], ':maxlat' => $box['max_lat'],
        ':minlng' => $box['min_lng'], ':maxlng' => $box['max_lng'],
    ]);
    $nearby = (int)$stmt->fetch()['n'];

    successResponse([
        'total'         => $quote['total'],
        'lines'         => $quote['lines'],
        'tow_miles'     => $miles,
        'after_hours'   => $quote['after_hours'],
        'weekend'       => $quote['weekend'],
        'goa_amount'    => $quote['goa_amount'],
        'trucks_nearby' => $nearby,
        'note'          => t('note.not_charged_yet'),
    ]);
}

// ═══ REQUEST — create the job and authorise the card ═════════════════════════
if ($method === 'POST' && $action === 'request') {
    $in = jsonInput();

    foreach (['pickup_address', 'pickup_lat', 'pickup_lng', 'customer_name', 'customer_phone'] as $f) {
        if (empty($in[$f])) errorResponse(str_replace('_', ' ', ucfirst($f)) . ' is required');
    }

    $pickupLat = (float)$in['pickup_lat'];
    $pickupLng = (float)$in['pickup_lng'];
    if ($msg = outsideLaunchArea($pickupLat, $pickupLng)) errorResponse($msg, 422);

    $service = in_array($in['service_type'] ?? 'tow',
        ['tow','winch_recovery','lockout','jumpstart','tire_change','fuel_delivery'], true)
        ? $in['service_type'] : 'tow';
    $class = in_array($in['vehicle_class'] ?? 'light',
        ['light','medium','heavy','motorcycle'], true) ? $in['vehicle_class'] : 'light';

    $dropoffLat = isset($in['dropoff_lat']) ? (float)$in['dropoff_lat'] : null;
    $dropoffLng = isset($in['dropoff_lng']) ? (float)$in['dropoff_lng'] : null;
    $miles = ($service === 'tow' && $dropoffLat && $dropoffLng)
        ? round(haversineMiles($pickupLat, $pickupLng, $dropoffLat, $dropoffLng), 1) : 0.0;

    $hasKeys    = array_key_exists('has_keys', $in) ? !empty($in['has_keys']) : true;
    $wheelsLock = array_key_exists('wheels_lock', $in) ? !empty($in['wheels_lock']) : true;

    // Price server-side. Never trust a total posted by the browser — that's the
    // one number a customer could tamper with to get a $900 recovery for $1.
    $quote = quoteConsumerJob([
        'service_type' => $service, 'vehicle_class' => $class, 'tow_miles' => $miles,
        'is_accident' => !empty($in['is_accident']), 'has_keys' => $hasKeys,
        'wheels_lock' => $wheelsLock, 'is_underground' => !empty($in['is_underground']),
    ]);
    if (empty($quote['ok'])) errorResponse(t('err.no_pricing'), 422);

    $total = $quote['total'];
    $goa   = (float)setting('consumer_goa_amount', 55.00);
    $expiryMin = (int)setting('consumer_call_expiry_min', 12);

    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        // A lightweight consumer account, keyed on phone so a repeat customer
        // builds a history instead of a pile of duplicates.
        $phone = normalizePhone($in['customer_phone']);
        $stmt = $pdo->prepare("SELECT id FROM accounts WHERE account_type = 'consumer' AND phone = :p LIMIT 1");
        $stmt->execute([':p' => $phone]);
        $consumerId = $stmt->fetch()['id'] ?? null;

        if (!$consumerId) {
            $pdo->prepare(
                "INSERT INTO accounts (account_type, name, slug, email, phone, city, state, lat, lng, verification_status)
                 VALUES ('consumer', :n, :s, :e, :p, :c, :st, :lat, :lng, 'approved')"
            )->execute([
                ':n' => $in['customer_name'],
                ':s' => uniqueSlug('cust-' . substr($phone, -4) . '-' . bin2hex(random_bytes(3))),
                ':e' => $in['customer_email'] ?? null, ':p' => $phone,
                ':c' => $in['pickup_city'] ?? null,
                ':st' => !empty($in['pickup_state']) ? strtoupper(substr($in['pickup_state'], 0, 2)) : null,
                ':lat' => $pickupLat, ':lng' => $pickupLng,
            ]);
            $consumerId = (int)$pdo->lastInsertId();
        }

        $callNumber    = generateCallNumber();
        $trackingToken = bin2hex(random_bytes(16));

        $pdo->prepare(
            "INSERT INTO calls (
                call_number, source, tracking_token, provider_account_id, service_type, vehicle_class,
                pickup_address, pickup_city, pickup_state, pickup_zip, pickup_lat, pickup_lng, pickup_notes,
                dropoff_address, dropoff_city, dropoff_state, dropoff_lat, dropoff_lng, tow_miles,
                vehicle_year, vehicle_make, vehicle_model, vehicle_color, vehicle_plate,
                has_keys, wheels_lock, is_accident, is_underground, is_ev,
                customer_name, customer_phone, customer_email,
                pricing_mode, offer_amount, goa_amount, price_breakdown,
                expires_at, status
             ) VALUES (
                :cn, 'consumer', :tok, :pa, :st, :vc,
                :addr, :city, :state, :zip, :lat, :lng, :notes,
                :daddr, :dcity, :dstate, :dlat, :dlng, :miles,
                :vy, :vm, :vmo, :vcol, :vplate,
                :keys, :wheels, :accident, :under, :ev,
                :cname, :cphone, :cemail,
                'accept', :offer, :goa, :breakdown,
                DATE_ADD(NOW(), INTERVAL :exp MINUTE), 'open'
             )"
        )->execute([
            ':cn' => $callNumber, ':tok' => $trackingToken, ':pa' => $consumerId,
            ':st' => $service, ':vc' => $class,
            ':addr' => $in['pickup_address'], ':city' => $in['pickup_city'] ?? null,
            ':state' => !empty($in['pickup_state']) ? strtoupper(substr($in['pickup_state'], 0, 2)) : null,
            ':zip' => $in['pickup_zip'] ?? null,
            ':lat' => $pickupLat, ':lng' => $pickupLng, ':notes' => $in['pickup_notes'] ?? null,
            ':daddr' => $in['dropoff_address'] ?? null, ':dcity' => $in['dropoff_city'] ?? null,
            ':dstate' => !empty($in['dropoff_state']) ? strtoupper(substr($in['dropoff_state'], 0, 2)) : null,
            ':dlat' => $dropoffLat, ':dlng' => $dropoffLng, ':miles' => $miles ?: null,
            ':vy' => $in['vehicle_year'] ?? null, ':vm' => $in['vehicle_make'] ?? null,
            ':vmo' => $in['vehicle_model'] ?? null, ':vcol' => $in['vehicle_color'] ?? null,
            ':vplate' => $in['vehicle_plate'] ?? null,
            ':keys' => $hasKeys ? 1 : 0, ':wheels' => $wheelsLock ? 1 : 0,
            ':accident' => !empty($in['is_accident']) ? 1 : 0,
            ':under' => !empty($in['is_underground']) ? 1 : 0,
            ':ev' => !empty($in['is_ev']) ? 1 : 0,
            ':cname' => $in['customer_name'], ':cphone' => $phone,
            ':cemail' => $in['customer_email'] ?? null,
            ':offer' => money($total), ':goa' => money($goa),
            ':breakdown' => json_encode($quote['lines']),
            ':exp' => $expiryMin,
        ]);
        $callId = (int)$pdo->lastInsertId();

        // Authorise, don't charge. If no truck takes it, the hold is released
        // and they were never charged a cent.
        $intent = stripeAuthorizeConsumerPayment(
            $total, $callId,
            ucfirst(str_replace('_', ' ', $service)) . ' — ' . $in['pickup_address'],
            $in['customer_email'] ?? null
        );

        $paymentIntentId = null;
        $clientSecret = null;
        if ($intent['ok']) {
            $paymentIntentId = $intent['data']['id'];
            $clientSecret    = $intent['data']['client_secret'];
            $pdo->prepare(
                "UPDATE calls SET stripe_payment_intent_id = :pi, payment_status = 'authorized' WHERE id = :id"
            )->execute([':pi' => $paymentIntentId, ':id' => $callId]);
        }

        escrowHold($callId, $consumerId, null, $total, 'card', $paymentIntentId);

        logCallEvent($callId, 'posted',
            'Customer request at $' . money($total) . ' via ' . ($in['utm_source'] ?? 'direct'),
            $consumerId);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        errorResponse(t('err.request_failed', ['detail' => $e->getMessage()]), 500);
    }

    successResponse([
        'call_id'        => $callId,
        'call_number'    => $callNumber,
        'tracking_token' => $trackingToken,
        'tracking_url'   => APP_URL . '/track/' . $trackingToken,
        'total'          => money($total),
        'lines'          => $quote['lines'],
        'expires_in_minutes' => $expiryMin,
        'client_secret'  => $clientSecret,
        'publishable_key'=> STRIPE_PUBLISHABLE_KEY,
        'payment_ready'  => $paymentIntentId !== null,
    ], t('ok.finding_truck'));
}

// ═══ TRACK — the customer's own view, by token ═══════════════════════════════
if ($action === 'track') {
    $token = $_GET['token'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) errorResponse(t('err.bad_tracking'), 404);

    $stmt = getDB()->prepare(
        "SELECT c.*, t.name AS tower_name, t.phone AS tower_phone, t.rating_avg AS tower_rating,
                t.jobs_completed AS tower_jobs
           FROM calls c LEFT JOIN accounts t ON c.awarded_tower_account_id = t.id
          WHERE c.tracking_token = :tok"
    );
    $stmt->execute([':tok' => $token]);
    $call = $stmt->fetch();
    if (!$call) errorResponse(t('err.job_not_found'), 404);

    $stmt = getDB()->prepare(
        "SELECT event_type, detail, created_at FROM call_events
          WHERE call_id = :c AND event_type IN ('posted','awarded','en_route','on_scene','in_progress','completed','goa','canceled','expired')
          ORDER BY created_at ASC"
    );
    $stmt->execute([':c' => $call['id']]);

    $friendly = [];
    foreach (['open','awarded','en_route','on_scene','in_progress','completed','goa','canceled','expired'] as $st) {
        $friendly[$st] = t('status.' . $st);
    }

    successResponse([
        'call_number'   => $call['call_number'],
        'status'        => $call['status'],
        'status_text'   => $friendly[$call['status']] ?? $call['status'],
        'service_type'  => $call['service_type'],
        'pickup_address'=> $call['pickup_address'],
        'dropoff_address'=> $call['dropoff_address'],
        'total'         => (float)$call['offer_amount'],
        'lines'         => $call['price_breakdown'] ? json_decode($call['price_breakdown'], true) : [],
        'payment_status'=> $call['payment_status'],
        'eta_minutes'   => $call['awarded_eta_minutes'],
        'awarded_at'    => $call['awarded_at'],
        'expires_at'    => $call['expires_at'],
        // The driver's number only appears once someone is actually coming.
        'tower' => $call['awarded_tower_account_id'] ? [
            'name'   => $call['tower_name'],
            'phone'  => $call['tower_phone'],
            'rating' => (float)$call['tower_rating'],
            'jobs'   => (int)$call['tower_jobs'],
        ] : null,
        'timeline' => $stmt->fetchAll(),
    ]);
}

// ═══ CANCEL — customer changes their mind ════════════════════════════════════
if ($method === 'POST' && $action === 'cancel') {
    $in = jsonInput();
    $token = $in['token'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) errorResponse(t('err.bad_tracking'), 404);

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM calls WHERE tracking_token = :tok");
    $stmt->execute([':tok' => $token]);
    $call = $stmt->fetch();
    if (!$call) errorResponse(t('err.job_not_found'), 404);

    if (in_array($call['status'], ['completed','canceled','goa','expired'], true)) {
        errorResponse(t('err.job_closed'), 409);
    }

    // Free to cancel until a driver is rolling. After that the tower has burned
    // fuel and time, so the GOA fee applies — same rule as the board side.
    $charged = 0.0;
    $pdo->beginTransaction();
    try {
        if (in_array($call['status'], ['en_route','on_scene','in_progress'], true)
            && $call['awarded_tower_account_id']) {
            $charged = (float)$call['goa_amount'];
            escrowPartialRelease((int)$call['id'], $charged, 'Customer cancelled after dispatch');
            if ($call['stripe_payment_intent_id']) {
                stripeCapturePayment($call['stripe_payment_intent_id'], $charged);
            }
            $pdo->prepare("UPDATE calls SET payment_status = 'captured' WHERE id = :id")
                ->execute([':id' => $call['id']]);
        } else {
            escrowRefund((int)$call['id'], 'Customer cancelled');
            if ($call['stripe_payment_intent_id']) {
                stripeCancelPayment($call['stripe_payment_intent_id']);
            }
            $pdo->prepare("UPDATE calls SET payment_status = 'refunded' WHERE id = :id")
                ->execute([':id' => $call['id']]);
        }

        $pdo->prepare("UPDATE calls SET status = 'canceled', canceled_at = NOW(), cancel_reason = 'Cancelled by customer' WHERE id = :id")
            ->execute([':id' => $call['id']]);
        logCallEvent((int)$call['id'], 'canceled', 'Cancelled by customer');

        if ($call['awarded_tower_account_id']) {
            notify((int)$call['awarded_tower_account_id'], 'call_canceled',
                $call['call_number'] . ' cancelled by the customer',
                $charged > 0 ? '$' . money($charged) . ' has been credited to you.' : '',
                (int)$call['id']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        errorResponse('Could not cancel: ' . $e->getMessage(), 500);
    }

    successResponse([
        'charged' => money($charged),
        'message' => $charged > 0
            ? t('msg.canceled_fee', ['amount' => money($charged)])
            : t('msg.canceled_free'),
    ], t('ok.job_canceled'));
}

errorResponse('Unknown action', 404);
