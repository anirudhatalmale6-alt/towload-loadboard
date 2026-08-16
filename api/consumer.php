<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/escrow.php';
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/zones.php';
require_once __DIR__ . '/../includes/geo.php';   // pickupState()
require_once __DIR__ . '/../includes/surge.php';
require_once __DIR__ . '/../includes/legal.php';
require_once __DIR__ . '/../includes/stripe_connect.php';
require_once __DIR__ . '/../includes/webpush.php';
require_once __DIR__ . '/../includes/realtime.php';
setCorsHeaders();

// ═══════════════════════════════════════════════════════════════════════════
//  DIRECT-TO-CONSUMER — the stranded motorist
//
//  Nothing here requires a login. Someone whose car just died on the Palmetto
//  at 11pm is not going to create an account, verify an email and remember a
//  password. They tap a Google ad, see a price, enter a card, and a truck comes.
//
//  Their identity is the tracking token issued at request time: unguessable,
//  texted to them, and the only key to their own job.
//
//  Two rules this file exists to enforce:
//
//   • ONE PRICE. The customer is never shown line items. The full breakdown is
//     stored on the job for the tower and for a chargeback six weeks later, but
//     it never reaches this screen.
//
//   • NEVER TAKE MONEY WE CANNOT DELIVER ON. If there is no approved truck in
//     range, we say so and keep their number instead of authorising a card for
//     a job that will expire unclaimed. Nationwide ads make this the difference
//     between a recruiting list and a pile of refunds.
// ═══════════════════════════════════════════════════════════════════════════

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ─── QUOTE TOKENS ────────────────────────────────────────────────────────────
// The price a customer taps Confirm on has to be the price they are charged,
// even if demand moves in the ninety seconds they spend typing their plate.
// The quote is signed and short-lived: the browser cannot alter the total, and
// the server does not silently re-price underneath them.
function signQuote(array $facts): string {
    $facts['exp'] = time() + 600;   // ten minutes; a stale quote re-prices
    $body = base64url_encode(json_encode($facts));
    return $body . '.' . base64url_encode(hash_hmac('sha256', $body, JWT_SECRET, true));
}

function readQuoteToken(?string $token): ?array {
    if (!$token || substr_count($token, '.') !== 1) return null;
    [$body, $sig] = explode('.', $token);
    $expected = base64url_encode(hash_hmac('sha256', $body, JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return null;
    $facts = json_decode(base64url_decode($body), true);
    if (!is_array($facts) || ($facts['exp'] ?? 0) < time()) return null;
    return $facts;
}

// The inputs that decide a price. If any of them changed between the quote and
// the request, the quote no longer describes this job and we re-price.
function quoteFingerprint(array $o): string {
    return implode('|', [
        $o['service_type'], $o['vehicle_class'], number_format((float)$o['tow_miles'], 1, '.', ''),
        !empty($o['is_accident']) ? 1 : 0, !empty($o['has_keys']) ? 1 : 0,
        !empty($o['wheels_lock']) ? 1 : 0, !empty($o['is_underground']) ? 1 : 0,
        (int)$o['zone_id'],
    ]);
}

function normaliseJobOpts(array $in, float $lat, float $lng): array {
    // Default FIRST, then whitelist. Written the other way round —
    // `in_array($in['x'] ?? 'tow', ...) ? $in['x'] : 'tow'` — the test passes on
    // the default while the result reads a key that was never sent, so an
    // omitted field becomes NULL rather than 'tow'. That produced a call with a
    // null service type and a request that died at the very last step.
    $service = $in['service_type'] ?? 'tow';
    if (!in_array($service, ['tow','winch_recovery','lockout','jumpstart','tire_change','fuel_delivery'], true)) {
        $service = 'tow';
    }
    $class = $in['vehicle_class'] ?? 'light';
    if (!in_array($class, ['light','medium','heavy','motorcycle'], true)) {
        $class = 'light';
    }

    $dLat = isset($in['dropoff_lat']) ? (float)$in['dropoff_lat'] : null;
    $dLng = isset($in['dropoff_lng']) ? (float)$in['dropoff_lng'] : null;
    $miles = ($service === 'tow' && $dLat && $dLng)
        ? round(haversineMiles($lat, $lng, $dLat, $dLng), 1) : 0.0;

    return [
        'service_type'   => $service,
        'vehicle_class'  => $class,
        'tow_miles'      => $miles,
        'is_accident'    => !empty($in['is_accident']),
        // Absent means the customer never saw the question. Defaulting these to
        // false would flag almost every job "no keys, wheels locked" and price
        // it as a recovery.
        'has_keys'       => array_key_exists('has_keys', $in) ? !empty($in['has_keys']) : true,
        'wheels_lock'    => array_key_exists('wheels_lock', $in) ? !empty($in['wheels_lock']) : true,
        'is_underground' => !empty($in['is_underground']),
        'lat'            => $lat,
        'lng'            => $lng,
        'state'          => pickupState($in),
    ];
}

// ═══ QUOTE — public, no auth, no obligation ══════════════════════════════════
if ($action === 'quote') {
    $in = $method === 'POST' ? jsonInput() : $_GET;

    $lat = isset($in['pickup_lat']) ? (float)$in['pickup_lat'] : null;
    $lng = isset($in['pickup_lng']) ? (float)$in['pickup_lng'] : null;
    if ($lat === null || $lng === null) errorResponse(t('err.need_location'));

    $coverage = coverageAt($lat, $lng, pickupState($in));

    // No truck in range. Say so plainly and do not quote a price we cannot
    // honour — a price followed by silence is what produces a chargeback and a
    // one-star review of a company that never even got the job.
    if (!$coverage['covered']) {
        successResponse([
            'covered'       => false,
            'trucks_nearby' => $coverage['trucks'],
            'area'          => zoneName($coverage['zone']),
            'message'       => t('msg.no_coverage'),
        ]);
    }

    $opts = normaliseJobOpts($in, $lat, $lng);
    $opts['zone'] = $coverage['zone'];

    $quote = quoteConsumerJob($opts);
    if (empty($quote['ok'])) errorResponse(t('err.no_pricing'), 422);

    $opts['zone_id'] = $quote['zone_id'];
    $view = customerQuoteView($quote, $opts['service_type']);

    successResponse($view + [
        'covered'       => true,
        'trucks_nearby' => $coverage['trucks'],
        'tow_miles'     => $opts['tow_miles'],
        'note'          => t('note.not_charged_yet'),
        // Locks this price in for ten minutes.
        'quote_token'   => signQuote([
            'fp'    => quoteFingerprint($opts),
            'total' => $quote['total'],
            'surge' => $quote['surge'],
            'zone'  => $quote['zone_id'],
            'lines' => $quote['lines'],
        ]),
    ]);
}

// ═══ REQUEST — create the job and authorise the card ═════════════════════════
if ($method === 'POST' && $action === 'request') {
    $in = jsonInput();

    foreach (['pickup_address', 'pickup_lat', 'pickup_lng', 'customer_name', 'customer_phone'] as $f) {
        if (empty($in[$f])) {
            errorResponse(t('err.field_required', ['field' => t('field.' . $f)]));
        }
    }

    // Terms have to be accepted before anything is created, not after — an
    // acceptance row pointing at a job that failed to save proves nothing.
    if (termsRequired() && empty($in['accept_terms'])) {
        errorResponse(t('err.terms_required'), 422);
    }

    $lat = (float)$in['pickup_lat'];
    $lng = (float)$in['pickup_lng'];

    $coverage = coverageAt($lat, $lng, pickupState($in));
    if (!$coverage['covered']) {
        // Capture the lead rather than dropping the click on the floor.
        saveCoverageLead($in, $lat, $lng, $coverage['trucks']);
        successResponse([
            'covered'       => false,
            'trucks_nearby' => $coverage['trucks'],
            'message'       => t('msg.no_coverage_saved'),
        ]);
    }

    $opts = normaliseJobOpts($in, $lat, $lng);
    $opts['zone'] = $coverage['zone'];
    $opts['zone_id'] = (int)$coverage['zone']['id'];

    // Honour the signed quote if it still describes this job; otherwise price
    // it fresh. Never trust a total posted by the browser — that is the one
    // number a customer could tamper with to get a $900 recovery for $1.
    $held = readQuoteToken($in['quote_token'] ?? null);
    if ($held && ($held['fp'] ?? '') === quoteFingerprint($opts)
        && (int)($held['zone'] ?? -1) === $opts['zone_id']) {
        $quote = [
            'ok' => true, 'total' => (float)$held['total'], 'lines' => $held['lines'],
            'surge' => $held['surge'], 'zone_id' => $opts['zone_id'],
            'zone_name' => zoneName($coverage['zone']),
            'platform_fee' => consumerFee((float)$held['total']),
            'tower_receives' => round((float)$held['total'] - consumerFee((float)$held['total']), 2),
        ];
    } else {
        $quote = quoteConsumerJob($opts);
        if (empty($quote['ok'])) errorResponse(t('err.no_pricing'), 422);
    }

    $total = (float)$quote['total'];
    $goa   = (float)setting('consumer_goa_amount', 55.00);
    $expiryMin = (int)setting('consumer_call_expiry_min', 12);
    $surge = $quote['surge'];

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
                ':st' => pickupState($in),
                ':lat' => $lat, ':lng' => $lng,
            ]);
            $consumerId = (int)$pdo->lastInsertId();
        }

        $callNumber    = generateCallNumber();
        $trackingToken = bin2hex(random_bytes(16));

        $pdo->prepare(
            "INSERT INTO calls (
                call_number, source, zone_id, tracking_token, provider_account_id, service_type, vehicle_class,
                pickup_address, pickup_city, pickup_state, pickup_zip, pickup_lat, pickup_lng, pickup_notes,
                dropoff_address, dropoff_city, dropoff_state, dropoff_lat, dropoff_lng, tow_miles,
                vehicle_year, vehicle_make, vehicle_model, vehicle_color, vehicle_plate,
                has_keys, wheels_lock, is_accident, is_underground, is_ev,
                customer_name, customer_phone, customer_email,
                pricing_mode, offer_amount, goa_amount, price_breakdown,
                surge_multiplier, surge_reason, surge_demand, surge_supply,
                expires_at, status
             ) VALUES (
                :cn, 'consumer', :zone, :tok, :pa, :st, :vc,
                :addr, :city, :state, :zip, :lat, :lng, :notes,
                :daddr, :dcity, :dstate, :dlat, :dlng, :miles,
                :vy, :vm, :vmo, :vcol, :vplate,
                :keys, :wheels, :accident, :under, :ev,
                :cname, :cphone, :cemail,
                'accept', :offer, :goa, :breakdown,
                :sm, :sr, :sd, :ss,
                DATE_ADD(NOW(), INTERVAL :exp MINUTE), 'open'
             )"
        )->execute([
            ':cn' => $callNumber, ':zone' => $opts['zone_id'], ':tok' => $trackingToken, ':pa' => $consumerId,
            ':st' => $opts['service_type'], ':vc' => $opts['vehicle_class'],
            ':addr' => $in['pickup_address'], ':city' => $in['pickup_city'] ?? null,
            ':state' => $opts['state'], ':zip' => $in['pickup_zip'] ?? null,
            ':lat' => $lat, ':lng' => $lng, ':notes' => $in['pickup_notes'] ?? null,
            ':daddr' => $in['dropoff_address'] ?? null, ':dcity' => $in['dropoff_city'] ?? null,
            ':dstate' => !empty($in['dropoff_state']) ? strtoupper(substr($in['dropoff_state'], 0, 2)) : null,
            ':dlat' => isset($in['dropoff_lat']) ? (float)$in['dropoff_lat'] : null,
            ':dlng' => isset($in['dropoff_lng']) ? (float)$in['dropoff_lng'] : null,
            ':miles' => $opts['tow_miles'] ?: null,
            ':vy' => $in['vehicle_year'] ?? null, ':vm' => $in['vehicle_make'] ?? null,
            ':vmo' => $in['vehicle_model'] ?? null, ':vcol' => $in['vehicle_color'] ?? null,
            ':vplate' => $in['vehicle_plate'] ?? null,
            ':keys' => $opts['has_keys'] ? 1 : 0, ':wheels' => $opts['wheels_lock'] ? 1 : 0,
            ':accident' => $opts['is_accident'] ? 1 : 0,
            ':under' => $opts['is_underground'] ? 1 : 0,
            ':ev' => !empty($in['is_ev']) ? 1 : 0,
            ':cname' => $in['customer_name'], ':cphone' => $phone,
            ':cemail' => $in['customer_email'] ?? null,
            ':offer' => money($total), ':goa' => money($goa),
            ':breakdown' => json_encode($quote['lines']),
            ':sm' => number_format((float)$surge['multiplier'], 2, '.', ''),
            ':sr' => $surge['reason'] ?? null,
            ':sd' => $surge['demand'] ?? null, ':ss' => $surge['supply'] ?? null,
            ':exp' => $expiryMin,
        ]);
        $callId = (int)$pdo->lastInsertId();

        // Proof of acceptance, tied to this exact job and this exact version.
        if (termsRequired()) {
            recordAcceptance('terms_customer', $consumerId, null, $callId);
        }

        // Authorise, don't charge. If no truck takes it, the hold is released
        // and they were never charged a cent.
        $intent = stripeAuthorizeConsumerPayment(
            $total, $callId,
            ucfirst(str_replace('_', ' ', $opts['service_type'])) . ' — ' . $in['pickup_address'],
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
            'Customer request at $' . money($total) . ' (surge ' . $surge['multiplier'] . 'x, '
            . ($surge['reason'] ?? '') . ') via ' . ($in['utm_source'] ?? 'direct'),
            $consumerId);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        errorResponse(t('err.request_failed', ['detail' => $e->getMessage()]), 500);
    }

    // Every board that is open sees the job appear without waiting for its
    // next poll. A nudge only — the browser refetches through the normal API,
    // which is what decides whether that operator may see this job at all.
    rtJobPosted($callId, $in['pickup_city'] ?? null, $opts['state'] ?? null);

    // Wake the trucks. Queued rather than sent inline: the job is committed and
    // the card is authorised by this point, so the alert is a side effect and
    // the customer should not be watching a spinner while phones ring.
    pushNewJobAfterResponse($callId);

    successResponse([
        'covered'        => true,
        'call_id'        => $callId,
        'call_number'    => $callNumber,
        'tracking_token' => $trackingToken,
        'tracking_url'   => APP_URL . '/track/' . $trackingToken,
        'total'          => money($total),
        'expires_in_minutes' => $expiryMin,
        'client_secret'  => $clientSecret,
        'publishable_key'=> STRIPE_PUBLISHABLE_KEY,
        'payment_ready'  => $paymentIntentId !== null,
    ], t('ok.finding_truck'));
}

// ═══ COVERAGE LEAD — "tell me when you're in my area" ════════════════════════
if ($method === 'POST' && $action === 'lead') {
    $in = jsonInput();
    if (empty($in['phone']) && empty($in['email'])) errorResponse(t('err.need_contact'));
    saveCoverageLead($in,
        isset($in['pickup_lat']) ? (float)$in['pickup_lat'] : null,
        isset($in['pickup_lng']) ? (float)$in['pickup_lng'] : null,
        (int)($in['trucks_nearby'] ?? 0));
    successResponse([], t('ok.lead_saved'));
}

function saveCoverageLead(array $in, ?float $lat, ?float $lng, int $trucks): void {
    try {
        getDB()->prepare(
            "INSERT INTO coverage_leads
                (kind, name, phone, email, service_type, pickup_address, city, state, zip,
                 lat, lng, trucks_nearby, utm_source, lang)
             VALUES (:k, :n, :p, :e, :s, :addr, :c, :st, :z, :lat, :lng, :tn, :utm, :lang)"
        )->execute([
            ':k'   => ($in['kind'] ?? 'customer') === 'tower' ? 'tower' : 'customer',
            ':n'   => $in['customer_name'] ?? $in['name'] ?? null,
            ':p'   => !empty($in['customer_phone']) ? normalizePhone($in['customer_phone'])
                        : (!empty($in['phone']) ? normalizePhone($in['phone']) : null),
            ':e'   => $in['customer_email'] ?? $in['email'] ?? null,
            ':s'   => $in['service_type'] ?? null,
            ':addr'=> $in['pickup_address'] ?? null,
            ':c'   => $in['pickup_city'] ?? $in['city'] ?? null,
            // Worth getting right even here. Leads are the list that decides
            // which market gets switched on next, and a lead filed under the
            // wrong state is an argument for opening the wrong city.
            ':st'  => pickupState($in) ?? pickupState($in, 'state'),
            ':z'   => $in['pickup_zip'] ?? $in['zip'] ?? null,
            ':lat' => $lat, ':lng' => $lng, ':tn' => $trucks,
            ':utm' => $in['utm_source'] ?? null, ':lang' => currentLang(),
        ]);
    } catch (Throwable $e) {
        // A failed lead insert must never surface as an error to someone who is
        // already being told we cannot help them.
    }
}

// ═══ TRACK — the customer's own view, by token ═══════════════════════════════
if ($action === 'track') {
    $token = $_GET['token'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) errorResponse(t('err.bad_tracking'), 404);

    $stmt = getDB()->prepare(
        "SELECT c.*, t.name AS tower_name, t.phone AS tower_phone, t.rating_avg AS tower_rating,
                t.jobs_completed AS tower_jobs, t.verification_status AS tower_status,
                t.verified_at AS tower_verified_at
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
        // One number, same as at booking. The stored breakdown is for the tower
        // and for a disputed charge, not for this screen.
        'total'         => (float)$call['offer_amount'],
        'payment_status'=> $call['payment_status'],
        'eta_minutes'   => $call['awarded_eta_minutes'],
        'awarded_at'    => $call['awarded_at'],
        'expires_at'    => $call['expires_at'],
        // The driver's number only appears once someone is actually coming, and
        // the verified badge is what makes handing over a car to a stranger
        // feel survivable.
        'tower' => $call['awarded_tower_account_id'] ? [
            'name'     => $call['tower_name'],
            'phone'    => $call['tower_phone'],
            'rating'   => (float)$call['tower_rating'],
            'jobs'     => (int)$call['tower_jobs'],
            'verified' => $call['tower_status'] === 'approved',
            'verified_since' => $call['tower_verified_at'],
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
    // fuel and time, so the call-out fee applies.
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
            rtJobChanged((int)$call['id'], $call['tracking_token'] ?? null,
                         (int)$call['awarded_tower_account_id'], 'canceled');
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
