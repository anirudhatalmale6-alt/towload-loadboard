<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/branding.php';
require_once __DIR__ . '/../includes/escrow.php';
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/zones.php';
require_once __DIR__ . '/../includes/geo.php';   // pickupState()
require_once __DIR__ . '/../includes/surge.php';
require_once __DIR__ . '/../includes/legal.php';
require_once __DIR__ . '/../includes/stripe_connect.php';
require_once __DIR__ . '/../includes/webpush.php';
require_once __DIR__ . '/../includes/realtime.php';
require_once __DIR__ . '/../includes/ratings.php';
require_once __DIR__ . '/../includes/sweep.php';
require_once __DIR__ . '/../includes/promise.php';
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

/**
 * What is wrong with the vehicle. The customer picks one of these; anything
 * else is discarded rather than stored, because this string is shown to a
 * driver deciding what to bring and is not free text a stranger gets to write.
 *
 * 'accident' is the one that carries weight beyond the label: it sets
 * is_accident, which prices a surcharge and puts a hazard flag on the board.
 */
const VEHICLE_PROBLEMS = [
    'wont_start', 'overheated', 'accident', 'flat_tire',
    'transmission', 'wont_move', 'junk', 'other',
];

function cleanProblem($raw): ?string {
    $v = is_string($raw) ? trim($raw) : '';
    return in_array($v, VEHICLE_PROBLEMS, true) ? $v : null;
}

// The inputs that decide a price. If any of them changed between the quote and
// the request, the quote no longer describes this job and we re-price.
/**
 * The customer-facing wording for each kind of no.
 *
 * One function because these three are easy to conflate and each conflation is
 * a specific lie: "not in your area" to somebody in a town we serve, or "try
 * again in a few minutes" to somebody whose vehicle nobody nearby can tow.
 */
function coverageMessage(?string $reason, bool $saved): string {
    if ($reason === 'none_available') return t('msg.none_available');
    if ($reason === 'no_capable')     return t('msg.no_capable');
    return $saved ? t('msg.no_coverage_saved') : t('msg.no_coverage');
}

function quoteFingerprint(array $o): string {
    return implode('|', [
        $o['service_type'], $o['vehicle_class'], number_format((float)$o['tow_miles'], 1, '.', ''),
        !empty($o['is_accident']) ? 1 : 0, !empty($o['has_keys']) ? 1 : 0,
        (string)($o['problem'] ?? ''),
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
        'problem'        => cleanProblem($in['problem'] ?? null),
        // Derived, not asked. The separate "was it in an accident?" question is
        // gone from the form, but the surcharge and the board's hazard flag
        // both still hang off this boolean. The ?? keeps a browser running a
        // cached copy of the old page pricing the same as it always did.
        'is_accident'    => (($in['problem'] ?? null) === 'accident') || !empty($in['is_accident']),
        // Absent means the customer never saw the question. Defaulting these to
        // false would flag almost every job "no keys, wheels locked" and price
        // it as a recovery.
        'has_keys'       => array_key_exists('has_keys', $in) ? !empty($in['has_keys']) : true,
        'wheels_lock'    => array_key_exists('wheels_lock', $in) ? !empty($in['wheels_lock']) : true,
        'is_underground' => !empty($in['is_underground']),
        // Carried so the COVERAGE check can ask the same capability question
        // the board and the push fan-out ask. Left out, a coverage check reads
        // them as absent and counts a truck the board would then hide the job
        // from — which is precisely how a customer gets a card hold for a job
        // no qualified truck can see.
        'is_ev'          => !empty($in['is_ev']),
        'needs_flatbed'  => !empty($in['needs_flatbed']),
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

    // Normalise FIRST — coverage cannot answer "is there a truck for this job"
    // without knowing what the job is.
    $opts     = normaliseJobOpts($in, $lat, $lng);
    $coverage = coverageAt($lat, $lng, pickupState($in), $opts);

    // No truck in range. Say so plainly and do not quote a price we cannot
    // honour — a price followed by silence is what produces a chargeback and a
    // one-star review of a company that never even got the job.
    if (!$coverage['covered']) {
        successResponse([
            'covered'       => false,
            'trucks_nearby' => $coverage['trucks'],
            'area'          => zoneName($coverage['zone']),
            // Which kind of no. 'none_available' means we DO cover here and
            // every local company is off duty or busy — telling that customer
            // "we are not in your area yet" would be untrue, and it is said to
            // somebody standing next to a broken car in a town we serve.
            'reason'        => $coverage['reason'],
            'message'       => coverageMessage($coverage['reason'], false),
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

    $opts     = normaliseJobOpts($in, $lat, $lng);
    $coverage = coverageAt($lat, $lng, pickupState($in), $opts);
    if (!$coverage['covered']) {
        // Capture the lead rather than dropping the click on the floor.
        saveCoverageLead($in, $lat, $lng, $coverage['trucks'],
                         $coverage['reason'], $opts['vehicle_class'] ?? null);
        successResponse([
            'covered'       => false,
            'trucks_nearby' => $coverage['trucks'],
            'reason'        => $coverage['reason'],
            'message'       => coverageMessage($coverage['reason'], true),
        ]);
    }

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
                has_keys, wheels_lock, is_accident, problem, is_underground, is_ev,
                customer_name, customer_phone, customer_email,
                pricing_mode, offer_amount, goa_amount, price_breakdown,
                surge_multiplier, surge_reason, surge_demand, surge_supply,
                expires_at, status
             ) VALUES (
                :cn, 'consumer', :zone, :tok, :pa, :st, :vc,
                :addr, :city, :state, :zip, :lat, :lng, :notes,
                :daddr, :dcity, :dstate, :dlat, :dlng, :miles,
                :vy, :vm, :vmo, :vcol, :vplate,
                :keys, :wheels, :accident, :problem, :under, :ev,
                :cname, :cphone, :cemail,
                'accept', :offer, :goa, :breakdown,
                :sm, :sr, :sd, :ss,
                DATE_ADD(NOW(), INTERVAL :exp MINUTE), 'draft'
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
            ':problem'  => $opts['problem'],
            ':under' => $opts['is_underground'] ? 1 : 0,
            ':ev' => !empty($opts['is_ev']) ? 1 : 0,
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
            // The intent id is recorded; payment_status is deliberately left at
            // 'none'. Creating a PaymentIntent attaches no card and holds no
            // money — it sits in requires_payment_method until the customer
            // enters one. Writing 'authorized' here is how every job came to
            // carry a "paid job" badge on the board with nothing behind it,
            // which is the one promise a towing company turns out for. Only
            // /consumer/confirm-payment sets it, and only after asking Stripe.
            //
            // ('none' rather than a new 'pending': the column is an ENUM without
            // it, and status='draft' already identifies a job waiting on a card.)
            $pdo->prepare(
                "UPDATE calls SET stripe_payment_intent_id = :pi WHERE id = :id"
            )->execute([':pi' => $paymentIntentId, ':id' => $callId]);
        }

        // No escrow hold yet. escrowHold() writes a row that says money is being
        // held against this job, and at this point no card has been entered —
        // the row would be describing money that does not exist. It is written
        // in /consumer/confirm-payment, against a hold Stripe has confirmed.

        logCallEvent($callId, 'requested',
            'Customer request at $' . money($total) . ' (surge ' . $surge['multiplier'] . 'x, '
            . ($surge['reason'] ?? '') . ') via ' . ($in['utm_source'] ?? 'direct'),
            $consumerId);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        errorResponse(t('err.request_failed', ['detail' => $e->getMessage()]), 500);
    }

    // NOTHING is broadcast or pushed here. The job is a draft with no money
    // secured against it; waking trucks for it would be offering work that may
    // never be paid for. Both happen in /consumer/confirm-payment, once Stripe
    // confirms the hold.

    successResponse([
        'covered'        => true,
        'call_id'        => $callId,
        'call_number'    => $callNumber,
        'tracking_token' => $trackingToken,
        'tracking_url'   => APP_URL . '/track/' . $trackingToken,
        'total'          => money($total),
        // What they would be charged for cancelling once a truck is rolling.
        // Sent with the request rather than read from the quote the browser is
        // holding, so the figure disclosed on the payment screen is the one
        // stored against THIS job — the quote in memory can be stale, and a
        // disclosed number that does not match what gets charged is worse than
        // no disclosure at all.
        'goa_amount'     => money($goa),
        'expires_in_minutes' => $expiryMin,
        'client_secret'  => $clientSecret,
        'publishable_key'=> STRIPE_PUBLISHABLE_KEY,
        'payment_ready'  => $paymentIntentId !== null,
    ], t('ok.finding_truck'));
}

// ═══ CONFIRM THE CARD HOLD ═══════════════════════════════════════════════════
// The customer has entered a card and Stripe has authorised it. Only now does
// the job become real: it goes on the board and the trucks are woken.
//
// The authoritative answer comes from STRIPE, never from the browser. A client
// saying "it worked" is a client that can be edited, and the whole promise the
// towing companies show up for — the money is already secured — rests on this
// one check being honest.
if ($method === 'POST' && $action === 'confirm-payment') {
    $in    = jsonInput();
    $token = (string)($in['token'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) errorResponse(t('err.bad_tracking'), 404);

    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT * FROM calls WHERE tracking_token = :t");
    $stmt->execute([':t' => $token]);
    $call = $stmt->fetch();
    if (!$call) errorResponse(t('err.job_not_found'), 404);

    // Already live. Safe to call twice — the customer may refresh, and Stripe's
    // own redirect can land here a second time.
    if ($call['status'] !== 'draft') {
        successResponse(['already' => true, 'status' => $call['status']], t('ok.finding_truck'));
    }

    if (empty($call['stripe_payment_intent_id'])) errorResponse(t('err.pay_no_intent'), 409);

    $pi = stripeRequest('GET', '/payment_intents/' . $call['stripe_payment_intent_id']);
    if (empty($pi['ok'])) {
        error_log('[pay] could not read intent ' . $call['stripe_payment_intent_id'] . ': ' . ($pi['error'] ?? ''));
        errorResponse(t('err.pay_check_failed'), 502);
    }

    $status = $pi['data']['status'] ?? '';
    // requires_capture is the one we want: the money is held on the card and
    // not yet taken. succeeded would mean it was captured outright, which this
    // flow never does but is still a valid hold from our point of view.
    if ($status !== 'requires_capture' && $status !== 'succeeded') {
        errorResponse(t('err.pay_not_held', ['status' => str_replace('_', ' ', $status)]), 402);
    }

    // The amount Stripe actually holds must match what the customer was quoted.
    // If they diverge, something re-priced between the two calls and the honest
    // move is to stop rather than dispatch a job at the wrong number.
    $held = ((int)($pi['data']['amount'] ?? 0)) / 100;
    if (abs($held - (float)$call['offer_amount']) > 0.01) {
        error_log('[pay] amount mismatch on call ' . $call['id'] . ': held ' . $held
                  . ' vs quoted ' . $call['offer_amount']);
        errorResponse(t('err.pay_amount_mismatch'), 409);
    }

    $pdo->prepare(
        "UPDATE calls
            SET status = 'open', payment_status = 'authorized',
                expires_at = DATE_ADD(NOW(), INTERVAL :exp MINUTE)
          WHERE id = :id AND status = 'draft'"
    )->execute([
        // The clock starts when the job actually reaches the board, not when
        // the customer began typing their card in.
        ':exp' => max(5, (int)setting('consumer_call_expiry_min', 12)),
        ':id'  => $call['id'],
    ]);

    escrowHold((int)$call['id'], (int)$call['provider_account_id'], null,
               (float)$call['offer_amount'], 'card', $call['stripe_payment_intent_id']);

    logCallEvent((int)$call['id'], 'posted',
        'Card authorised $' . money($held) . ' — job released to the board',
        (int)$call['provider_account_id']);

    rtJobPosted((int)$call['id'], $call['pickup_city'] ?? null, $call['pickup_state'] ?? null);
    pushNewJobAfterResponse((int)$call['id']);

    successResponse([
        'status'         => 'open',
        'tracking_token' => $token,
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

function saveCoverageLead(array $in, ?float $lat, ?float $lng, int $trucks,
                          ?string $reason = null, ?string $vehicleClass = null): void {
    try {
        getDB()->prepare(
            "INSERT INTO coverage_leads
                (kind, name, phone, email, service_type, vehicle_class, reason,
                 pickup_address, city, state, zip,
                 lat, lng, trucks_nearby, utm_source, lang)
             VALUES (:k, :n, :p, :e, :s, :vc, :rsn, :addr, :c, :st, :z, :lat, :lng, :tn, :utm, :lang)"
        )->execute([
            // WHY it failed, and for what vehicle. Without these a saved lead
            // reads as "somebody wanted a tow here", which is already covered.
            // 'no_capable' + 'heavy' is a different instruction entirely: it
            // says recruit a heavy-duty company in THIS town, and it is the
            // only record that a paying customer was turned away for want of one.
            ':vc'  => $vehicleClass,
            ':rsn' => $reason,
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
    // The other heartbeat for the sweep. A stranded customer polls this every
    // few seconds, which means an expiring job gets cleaned up even at an hour
    // when no towing company has the board open. Rate-limited inside.
    runSweepIfDue();

    $token = $_GET['token'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) errorResponse(t('err.bad_tracking'), 404);

    $stmt = getDB()->prepare(
        "SELECT c.*, t.name AS tower_name, t.phone AS tower_phone, t.rating_avg AS tower_rating,
                t.jobs_completed AS tower_jobs, t.verification_status AS tower_status,
                t.verified_at AS tower_verified_at, t.rating_count AS tower_rating_count,
                t.logo_url AS tower_logo
           FROM calls c LEFT JOIN accounts t ON c.awarded_tower_account_id = t.id
          WHERE c.tracking_token = :tok"
    );
    $stmt->execute([':tok' => $token]);
    $call = $stmt->fetch();
    if (!$call) errorResponse(t('err.job_not_found'), 404);

    // Only what has happened since the last company handed the job back.
    //
    // Without this cut the timeline keeps the previous driver's ticks: a
    // customer whose company just cancelled sees "Driver assigned ✓" and
    // "Driver on the way ✓" sitting above the words "finding you another
    // driver". 'posted' is always kept — that is their own booking, and it did
    // happen.
    //
    // Cut by ROW ID, not by timestamp. call_events.created_at is a DATETIME
    // with one-second resolution, and accept → en route → release inside the
    // same second is not hypothetical: it is exactly what a company does when
    // it takes a job by mistake. A `created_at >= released_at` filter let every
    // one of those events straight back through, and the ticks reappeared.
    $stmt = getDB()->prepare(
        "SELECT event_type, detail, created_at FROM call_events
          WHERE call_id = :c AND event_type IN ('posted','awarded','en_route','on_scene','in_progress','completed','goa','canceled','expired')
            AND (event_type = 'posted'
                 OR id > COALESCE((SELECT MAX(e2.id) FROM call_events e2
                                    WHERE e2.call_id = :c2 AND e2.event_type = 'released'), 0))
          ORDER BY created_at ASC, id ASC"
    );
    $stmt->execute([':c' => $call['id'], ':c2' => $call['id']]);

    $friendly = [];
    foreach (['draft','open','awarded','en_route','on_scene','in_progress','completed','goa','canceled','expired'] as $st) {
        $friendly[$st] = t('status.' . $st);
    }

    $promise = etaPromise($call);
    $promisedRemaining = $promise['minutes_remaining'];

    // What cancelling costs right now, decided by the server. The button on
    // the page says what it will do, and it can only say that honestly if the
    // number comes from the same function that will charge it.
    $cancel = customerCancelFee($call);

    // A customer who closed the tab on the card screen and came back to their
    // link must land back on that card screen — not on "Finding you a truck"
    // for a job no operator can see. Handing the client secret back lets the
    // page pick the payment up exactly where it was left.
    $payment = null;
    if ($call['status'] === 'draft') {
        $payment = [
            'required'        => true,
            'total'           => (float)$call['offer_amount'],
            'goa_amount'      => (float)$call['goa_amount'],
            'publishable_key' => STRIPE_PUBLISHABLE_KEY,
            'client_secret'   => null,
        ];
        if (!empty($call['stripe_payment_intent_id'])) {
            $pi = stripeRequest('GET', '/payment_intents/' . $call['stripe_payment_intent_id']);
            // The secret is only useful while the intent is still waiting for a
            // card. Once it is held or captured this branch is unreachable
            // anyway, because the call is no longer a draft.
            if (!empty($pi['ok'])) $payment['client_secret'] = $pi['data']['client_secret'] ?? null;
        }
    }

    successResponse([
        'call_number'   => $call['call_number'],
        'status'        => $call['status'],
        'status_text'   => $friendly[$call['status']] ?? $call['status'],
        'payment'       => $payment,
        'service_type'  => $call['service_type'],
        'pickup_address'=> $call['pickup_address'],
        'dropoff_address'=> $call['dropoff_address'],
        // One number, same as at booking. The stored breakdown is for the tower
        // and for a disputed charge, not for this screen.
        'total'         => (float)$call['offer_amount'],
        'payment_status'=> $call['payment_status'],
        'eta_minutes'   => $call['awarded_eta_minutes'],
        // What the driver promised, counted down.
        //
        // eta_minutes is the number he typed when he accepted and it never
        // changes — so a customer told "about 20 minutes" at 11:51 was still
        // reading "about 20 minutes" at 12:15. Computed in SQL against NOW()
        // rather than in the browser, because the browser's clock and its idea
        // of this server's timezone are both things that can be wrong.
        //
        // Goes negative once he is overdue, and the screen says so rather than
        // counting back up. A live GPS ETA still beats this whenever there is
        // one — this is the fallback for a driver who is not sharing location.
        'eta_promised_remaining' => $promisedRemaining,
        // The promise was broken, and it stays broken even after a late truck
        // arrives. This is what unlocks the free cancel, so the page never has
        // to work it out from the countdown going negative.
        'eta_missed'    => $promise['missed'],
        'awarded_at'    => $call['awarded_at'],
        'expires_at'    => $call['expires_at'],

        // ── The search that had already succeeded and then collapsed ────────
        //
        // A job that is 'open' having once been awarded is not the same screen
        // as a job that is 'open' for the first time. The customer was told a
        // company was coming and given a name, a phone number and a countdown;
        // "Finding you a truck" appearing again with no explanation reads as
        // the page having forgotten, which is the moment they ring a
        // competitor. Only sent while the search is actually running again.
        'requeued' => ($call['status'] === 'open' && (int)$call['released_count'] > 0) ? [
            'released_at' => $call['released_at'],
            'times'       => (int)$call['released_count'],
        ] : null,

        // What the cancel button will actually do. free_reason is 'eta_missed'
        // once the company has blown the ETA it set itself — the customer owes
        // nothing from that minute on, whether or not the truck later turns up.
        'cancel' => [
            'fee'         => $cancel['amount'],
            'free'        => $cancel['amount'] <= 0,
            'free_reason' => $cancel['free_reason'],
        ],
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
            'rating_count'   => (int)$call['tower_rating_count'],
            // How many reviews the reviews screen can actually SHOW, counted
            // from the ratings table rather than the denormalised counter on
            // the account. The two disagree wherever a counter was seeded or
            // edited directly, and the customer card must not offer to open a
            // list that turns out to be empty -- an advertised "2 reviews" that
            // opens on nothing is worse than never having offered the link.
            'reviews_available' => (int)(function () use ($call) {
                $s = getDB()->prepare("SELECT COUNT(*) FROM ratings WHERE rated_account_id = :a");
                $s->execute([':a' => $call['awarded_tower_account_id']]);
                return $s->fetchColumn();
            })(),
            // The company's own mark, shown beside its name and dropped on the
            // map. Null for most companies, and the screen must look finished
            // without one -- a logo is the exception, not the layout.
            'logo_url'       => absoluteUrl($call['tower_logo']),
            'pin_url'        => absoluteUrl(logoPinUrl($call['tower_logo'])),
        ] : null,
        // Rating only ever appears on a finished job, and only inside the
        // window — asking someone to rate a truck that has not arrived yet is
        // how you collect ratings about the wait rather than the work.
        'can_rate'   => ratingWindowOpen($call),
        'my_rating'  => customerRatingFor((int)$call['id']),
        'timeline' => $stmt->fetchAll(),
    ]);
}

// ═══ RATE — how did the towing company do? ═══════════════════════════════════
// The tracking token is the customer's only credential. It came to them by text
// on the job they are rating, which is exactly the right scope: it authorises
// rating that one job and nothing else.
if ($method === 'POST' && $action === 'rate') {
    $in    = jsonInput();
    $token = $in['token'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) errorResponse(t('err.bad_tracking'), 404);

    $stmt = getDB()->prepare("SELECT * FROM calls WHERE tracking_token = :tok");
    $stmt->execute([':tok' => $token]);
    $call = $stmt->fetch();
    if (!$call) errorResponse(t('err.job_not_found'), 404);

    $res = saveCustomerRating($call, (int)($in['stars'] ?? 0), $in['comment'] ?? null);
    if (!$res['ok']) errorResponse($res['error'], 409);

    successResponse(['my_rating' => customerRatingFor((int)$call['id'])], t('ok.rating_saved'));
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

    // Free until a driver is rolling — after that the tower has burned fuel and
    // time, so the call-out fee applies — and free again the moment that tower
    // blows the ETA it set for itself.
    //
    // Decided here, by the server, from the same function the tracking screen
    // quoted. Reading a fee the browser sent, or recomputing it a second way in
    // this handler, is how the number on the button and the number on the card
    // statement come to disagree.
    $decision = customerCancelFee($call);
    $charged  = $decision['amount'];

    $pdo->beginTransaction();
    try {
        if ($charged > 0) {
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

        // Why it was free is written down, not inferred later. Three weeks on,
        // "cancelled, no fee" and "cancelled past a promised 15-minute ETA the
        // truck was 40 minutes into" are the same row unless this says so, and
        // the second one is the only answer to a company disputing it.
        $why = $decision['free_reason'] === 'eta_missed'
             ? 'Cancelled by customer — promised ETA of ' . (int)$call['awarded_eta_minutes']
               . ' min had passed, no fee charged'
             : 'Cancelled by customer';
        $pdo->prepare("UPDATE calls SET status = 'canceled', canceled_at = NOW(), cancel_reason = :r WHERE id = :id")
            ->execute([':r' => $why, ':id' => $call['id']]);
        logCallEvent((int)$call['id'], 'canceled', $why);

        if ($call['awarded_tower_account_id']) {
            rtJobChanged((int)$call['id'], $call['tracking_token'] ?? null,
                         (int)$call['awarded_tower_account_id'], 'canceled');
            // The operator is told the reason too. "Cancelled, $0" with no
            // explanation is what a company escalates; "you promised 15 and
            // they waited past it" is what changes the next ETA they type.
            notify((int)$call['awarded_tower_account_id'], 'call_canceled',
                $call['call_number'] . ' cancelled by the customer',
                $charged > 0
                    ? '$' . money($charged) . ' has been credited to you.'
                    : ($decision['free_reason'] === 'eta_missed'
                        ? 'Your ' . (int)$call['awarded_eta_minutes'] . ' minute ETA had passed, '
                          . 'so no call-out fee was charged.'
                        : ''),
                (int)$call['id']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        errorResponse('Could not cancel: ' . $e->getMessage(), 500);
    }

    successResponse([
        'charged' => money($charged),
        'free_reason' => $decision['free_reason'],
        'message' => $charged > 0
            ? t('msg.canceled_fee', ['amount' => money($charged)])
            : ($decision['free_reason'] === 'eta_missed'
                ? t('msg.canceled_eta_missed')
                : t('msg.canceled_free')),
    ], t('ok.job_canceled'));
}

errorResponse('Unknown action', 404);
