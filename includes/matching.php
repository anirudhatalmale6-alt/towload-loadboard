<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/pricing.php';
require_once __DIR__ . '/compliance.php';

// ═══════════════════════════════════════════════════════════════════════════
//  MATCHING + VISIBILITY RULES
//  Shared by the board, bidding and dispatch push. Kept out of the endpoint
//  files so "who can see what" has exactly one definition.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Which capability flags a call requires.
 * ANDed against tower_profiles so a lockout never lands in front of a
 * heavy-duty-only operator, and a heavy wreck never goes to a single wheel-lift.
 */
function requiredCapabilities(array $call): array {
    $caps = [];
    switch ($call['service_type'] ?? 'tow') {
        case 'lockout':        $caps[] = 'has_lockout'; break;
        case 'jumpstart':      $caps[] = 'has_jumpstart'; break;
        case 'tire_change':    $caps[] = 'has_tire_change'; break;
        case 'fuel_delivery':  $caps[] = 'has_fuel_delivery'; break;
        case 'winch_recovery': $caps[] = 'has_winch_recovery'; break;
    }
    switch ($call['vehicle_class'] ?? 'light') {
        case 'medium':     $caps[] = 'has_medium_duty'; break;
        case 'heavy':      $caps[] = 'has_heavy_duty'; break;
        case 'motorcycle': $caps[] = 'has_motorcycle'; break;
        default:           $caps[] = 'has_light_duty';
    }
    // A car that won't roll has to go on a flatbed regardless of what was ticked.
    if (!empty($call['needs_flatbed']) || empty($call['wheels_lock'])) $caps[] = 'has_flatbed';
    if (!empty($call['is_ev']))          $caps[] = 'has_ev_certified';
    if (!empty($call['is_underground'])) $caps[] = 'has_lowclearance';

    return array_values(array_unique($caps));
}

function towerIsCapable(array $profile, array $call): bool {
    foreach (requiredCapabilities($call) as $cap) {
        if (empty($profile[$cap])) return false;
    }
    return true;
}

/** Has this channel been confirmed, for the value currently on the account? */
function verifiedFor(array $account, string $channel): bool {
    $at      = $account[$channel . '_verified_at'] ?? null;
    $checked = trim((string)($account[$channel . '_verified_value'] ?? ''));
    $current = trim((string)($account[$channel] ?? ''));

    // Comparing the value, not just the timestamp. Otherwise a company verifies
    // one number, edits it to another on the profile screen, and keeps a green
    // tick against a number nobody has ever answered.
    return $at !== null && $current !== '' && $checked === $current;
}

/**
 * The three things standing between a towing company and its first job:
 * documents, email, phone. Returned as a list so the dashboard can show them as
 * steps and the board can refuse a job, without the two disagreeing.
 */
function towerVerificationSteps(int $accountId): array {
    $stmt = getDB()->prepare(
        "SELECT id, email, phone, email_verified_at, email_verified_value,
                phone_verified_at, phone_verified_value
           FROM accounts WHERE id = :a"
    );
    $stmt->execute([':a' => $accountId]);
    $account = $stmt->fetch() ?: [];

    $docState    = docsState($accountId);
    $docsOk      = $docState === 'approved';
    $needDocs    = (string)setting('require_coi_to_accept', '1') === '1';
    $needContact = (string)setting('require_verification_to_accept', '1') === '1';

    $emailOk = verifiedFor($account, 'email');
    $phoneOk = verifiedFor($account, 'phone');

    $steps = [
        [
            'key'      => 'documents',
            'state'    => $docState,
            'done'     => $docsOk,
            'required' => $needDocs,
            'label'    => t('v.step_docs'),
            // 'pending' is the case this whole rewrite exists for: everything is
            // uploaded and the company is waiting on a person, which is not a
            // failure on their side and must not be worded as one.
            'detail'   => t('v.docs_' . $docState),
        ],
        [
            'key'      => 'email',
            'state'    => $emailOk ? 'approved' : 'missing',
            'done'     => $emailOk,
            'required' => $needContact,
            'label'    => t('v.step_email'),
            'detail'   => $emailOk ? t('v.email_done') : t('v.email_todo'),
        ],
        [
            'key'      => 'phone',
            'state'    => $phoneOk ? 'approved' : 'missing',
            'done'     => $phoneOk,
            'required' => $needContact,
            'label'    => t('v.step_phone'),
            'detail'   => $phoneOk ? t('v.phone_done') : t('v.phone_todo'),
        ],
    ];

    $outstanding = array_values(array_filter($steps, fn($s) => $s['required'] && !$s['done']));

    // The headline names ONE thing — whichever the operator should do next.
    // A banner listing three problems at once gets read as "this is broken".
    $reason = $outstanding ? $outstanding[0]['detail'] : null;

    return [
        'ok'          => count($outstanding) === 0,
        // Documents pending review is a wait, not a fault. The dashboard styles
        // it calmly instead of as an error the operator has to fix.
        'waiting'     => count($outstanding) === 1
                         && $outstanding[0]['key'] === 'documents'
                         && $docState === 'pending',
        'reason'      => $reason,
        'steps'       => $steps,
        'outstanding' => count($outstanding),
    ];
}

/**
 * Insurance is checked at the moment of accepting, not at upload time.
 * A certificate that expired last week must stop dispatch today.
 */
function towerCanAccept(int $accountId): array {
    $v = towerVerificationSteps($accountId);
    return $v['ok']
        ? ['ok' => true]
        : ['ok' => false, 'reason' => $v['reason'], 'waiting' => $v['waiting']];
}

/**
 * Board rows hide the customer's IDENTITY, not the job.
 *
 * Name, phone, notes, plate and VIN stay behind the accept — those are what let
 * a tower ring the customer directly and take the work off the platform. The
 * addresses are shown, because without them nobody can tell what the job
 * actually is or commit honestly to an ETA.
 */
function publicCallRow(array $c, bool $revealCustomer = false): array {
    $row = [
        'id'             => (int)$c['id'],
        'call_number'    => $c['call_number'],
        'service_type'   => $c['service_type'],
        'vehicle_class'  => $c['vehicle_class'],
        'pickup_city'    => $c['pickup_city'],
        'pickup_state'   => $c['pickup_state'],
        'pickup_zip'     => $c['pickup_zip'],
        'pickup_lat'     => (float)$c['pickup_lat'],
        'pickup_lng'     => (float)$c['pickup_lng'],
        'dropoff_city'   => $c['dropoff_city'],
        'dropoff_state'  => $c['dropoff_state'],
        // So the drop-off line can link to an exact point rather than a string
        // Google has to guess at. Null on a job with no destination — a lockout
        // has nowhere to go.
        'dropoff_lat'    => $c['dropoff_lat'] !== null ? (float)$c['dropoff_lat'] : null,
        'dropoff_lng'    => $c['dropoff_lng'] !== null ? (float)$c['dropoff_lng'] : null,
        'tow_miles'      => $c['tow_miles'] !== null ? (float)$c['tow_miles'] : null,
        'vehicle'        => trim(($c['vehicle_year'] ?? '') . ' ' . ($c['vehicle_make'] ?? '') . ' ' . ($c['vehicle_model'] ?? '')),
        'vehicle_color'  => $c['vehicle_color'],
        'has_keys'       => (bool)$c['has_keys'],
        'wheels_lock'    => (bool)$c['wheels_lock'],
        'is_accident'    => (bool)$c['is_accident'],
        // What the customer said is wrong with it. The single most useful line
        // on the card for a driver deciding what to bring — a no-start and a
        // wreck are the same "tow" to the board and completely different jobs
        // in the yard.
        'problem'        => $c['problem'] ?? null,
        'is_underground' => (bool)$c['is_underground'],
        'needs_flatbed'  => (bool)$c['needs_flatbed'],
        'is_ev'          => (bool)$c['is_ev'],
        'pricing_mode'   => $c['pricing_mode'],
        'offer_amount'   => (float)$c['offer_amount'],
        'goa_amount'     => (float)$c['goa_amount'],
        'scheduled_for'  => $c['scheduled_for'],
        'expires_at'     => $c['expires_at'],
        'status'         => $c['status'],
        'created_at'     => $c['created_at'],
        'source'         => $c['source'] ?? 'board',
        // On a direct-from-customer job the "provider" account IS the stranded
        // motorist, so showing that name on the open board would leak the very
        // PII the masking below exists to protect.
        'provider_name'  => ($c['source'] ?? 'board') === 'consumer'
                              ? 'Direct customer' : ($c['provider_name'] ?? null),
        'provider_rating'=> isset($c['provider_rating']) ? (float)$c['provider_rating'] : null,
        // Read from the row, not asserted. This was hardcoded true, so every job
        // on the board carried a "paid job" badge whether or not any money
        // existed behind it — and that badge is the single reason a towing
        // company drops what it is doing and turns out.
        //
        // The two sources secure money in different places, so they are asked
        // different questions:
        //   consumer — a hold on the customer's card, which lives at Stripe
        //   board    — debited from the posting provider's balance at post time
        //              (calls.php refuses to post the job if it will not cover)
        'is_funded'      => ($c['source'] ?? 'board') === 'consumer'
                              ? in_array($c['payment_status'] ?? '', ['authorized', 'captured'], true)
                              : true,
    ];

    // What the tower actually takes home, computed server-side with the fee that
    // will really be applied. Board jobs and consumer jobs carry different cuts,
    // so a client-side percentage would promise one number at accept time and
    // pay a different one on completion.
    $fee = ($c['source'] ?? 'board') === 'consumer'
             ? consumerFee((float)$c['offer_amount'])
             : platformFee((float)$c['offer_amount']);
    $row['platform_fee_estimate'] = $fee;
    $row['tower_net_estimate']    = round((float)$c['offer_amount'] - $fee, 2);

    // ─── Itemised, for the tower only ────────────────────────────────────────
    // The customer sees one number; the driver sees how it was built. Whether
    // $185 is worth starting the truck depends entirely on whether it is 4
    // miles or 22, and whether the winch work is paid for.
    //
    // Read from the stored breakdown rather than recomputed, so what a tower is
    // shown is what the customer actually agreed to pay.
    $row['lines'] = !empty($c['price_breakdown'])
        ? (json_decode($c['price_breakdown'], true) ?: []) : [];

    $surge = isset($c['surge_multiplier']) ? (float)$c['surge_multiplier'] : 1.0;
    $row['surge_multiplier'] = $surge;
    $row['surge_active']     = $surge > 1.0;

    // The exact addresses, on the open board, BEFORE anyone accepts.
    //
    // These used to be masked to city level with everything else, on the
    // reasoning that hiding them stops a tower going round the platform. That
    // was the wrong thing to hide. An operator cannot judge a job from
    // "Hialeah, FL": a car on the shoulder of the Palmetto, one in a parking
    // deck with 6'2" clearance and one up a private drive are three different
    // jobs at the same price, and he is being asked to commit to an ETA
    // without knowing which. Guessing wrong is how a truck turns up that
    // cannot do the work.
    //
    // What actually enables going around the platform is the customer's NAME
    // and PHONE, and those stay masked until the job is accepted. An address
    // with nobody to call is just the information needed to price the drive.
    $row['pickup_address']  = $c['pickup_address'];
    $row['dropoff_address'] = $c['dropoff_address'];

    if ($revealCustomer) {
        // Notes can carry anything the customer typed, including their name or
        // "call me on the other number" — it stays behind the accept.
        $row['pickup_notes']    = $c['pickup_notes'];
        $row['customer_name']   = $c['customer_name'];
        $row['customer_phone']  = $c['customer_phone'];
        $row['vehicle_plate']   = $c['vehicle_plate'];
        $row['vehicle_vin']     = $c['vehicle_vin'];
    } else {
        // Enough to price the job, not enough to go around the platform.
        $row['pickup_area']    = trim(($c['pickup_city'] ?? '') . ', ' . ($c['pickup_state'] ?? ''), ', ');
        $row['customer_name']  = maskName($c['customer_name'] ?? null);
        $row['customer_phone'] = maskPhone($c['customer_phone'] ?? null);
    }
    return $row;
}
