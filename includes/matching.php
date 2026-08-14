<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/pricing.php';

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

/**
 * Insurance is checked at the moment of accepting, not at upload time.
 * A certificate that expired last week must stop dispatch today.
 */
function towerCanAccept(int $accountId): array {
    if ((string)setting('require_coi_to_accept', '1') !== '1') return ['ok' => true];

    $stmt = getDB()->prepare(
        "SELECT expires_at FROM compliance_docs
          WHERE account_id = :a AND doc_type = 'coi_liability' AND status = 'approved'
          ORDER BY expires_at DESC LIMIT 1"
    );
    $stmt->execute([':a' => $accountId]);
    $doc = $stmt->fetch();

    if (!$doc) {
        return ['ok' => false, 'reason' => 'No approved liability insurance certificate on file.'];
    }
    if ($doc['expires_at'] && strtotime($doc['expires_at']) < time()) {
        return ['ok' => false, 'reason' => 'Your liability insurance expired on ' . $doc['expires_at']
            . '. Upload a current certificate to keep accepting calls.'];
    }
    return ['ok' => true];
}

/**
 * Board rows hide customer PII. Only the awarded tower — and the provider who
 * posted it — ever see the name, phone, exact address, plate and VIN.
 * Without this, the first thing that happens is both sides go around the
 * platform and the marketplace dies.
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
        'tow_miles'      => $c['tow_miles'] !== null ? (float)$c['tow_miles'] : null,
        'vehicle'        => trim(($c['vehicle_year'] ?? '') . ' ' . ($c['vehicle_make'] ?? '') . ' ' . ($c['vehicle_model'] ?? '')),
        'vehicle_color'  => $c['vehicle_color'],
        'has_keys'       => (bool)$c['has_keys'],
        'wheels_lock'    => (bool)$c['wheels_lock'],
        'is_accident'    => (bool)$c['is_accident'],
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
        'is_funded'      => true,   // money is held before a call ever hits the board
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

    if ($revealCustomer) {
        $row['pickup_address']  = $c['pickup_address'];
        $row['pickup_notes']    = $c['pickup_notes'];
        $row['dropoff_address'] = $c['dropoff_address'];
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
