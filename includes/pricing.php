<?php
require_once __DIR__ . '/helpers.php';

// ═══════════════════════════════════════════════════════════════════════════
//  CONSUMER PRICING
//
//  A stranded motorist will not name a price and will not wait for an auction.
//  The platform quotes instantly, the way Uber does. The quote has to satisfy
//  two people at once: cheap enough that the customer taps Confirm, and fat
//  enough that a tower actually accepts it — otherwise the job sits unclaimed
//  and we've taken money for a truck that never comes.
//
//  Every quote is returned with its full breakdown and that breakdown is stored
//  on the call, so a customer disputing a charge sees the same numbers we showed
//  them rather than a recalculation against rules that may have changed since.
// ═══════════════════════════════════════════════════════════════════════════

function getPricingRule(string $serviceType, string $vehicleClass): ?array {
    $stmt = getDB()->prepare(
        "SELECT * FROM pricing_rules
          WHERE service_type = :s AND vehicle_class = :c AND is_active = 1
          LIMIT 1"
    );
    $stmt->execute([':s' => $serviceType, ':c' => $vehicleClass]);
    $rule = $stmt->fetch();
    if ($rule) return $rule;

    // Fall back to light duty for the same service rather than failing the
    // quote — a missing rule row must never lose a customer at the price step.
    $stmt->execute([':s' => $serviceType, ':c' => 'light']);
    return $stmt->fetch() ?: null;
}

function isAfterHours(?int $hour = null): bool {
    $hour = $hour ?? (int)date('G');
    $start = (int)setting('after_hours_start', 20);
    $end   = (int)setting('after_hours_end', 6);
    // The window wraps midnight, so it's "after start OR before end".
    return $start > $end ? ($hour >= $start || $hour < $end)
                         : ($hour >= $start && $hour < $end);
}

function isWeekend(): bool {
    $dow = (int)date('N');   // 6 = Saturday, 7 = Sunday
    return $dow >= 6;
}

/**
 * Quote a consumer job.
 *
 * $opts: service_type, vehicle_class, tow_miles,
 *        is_accident, has_keys, wheels_lock, is_underground
 *
 * Returns the customer-facing total plus a line-by-line breakdown.
 */
function quoteConsumerJob(array $opts): array {
    $service = $opts['service_type'] ?? 'tow';
    $class   = $opts['vehicle_class'] ?? 'light';
    $miles   = max(0.0, (float)($opts['tow_miles'] ?? 0));

    $rule = getPricingRule($service, $class);
    if (!$rule) {
        return ['ok' => false, 'error' => 'We do not price that service yet. Please call us.'];
    }

    $lines = [];

    $base = (float)$rule['base_fee'];
    $lines[] = ['label' => 'Base service fee', 'amount' => round($base, 2)];
    $subtotal = $base;

    // Mileage, only beyond what the base fee already covers.
    $billableMiles = 0.0;
    if ((float)$rule['per_mile'] > 0 && $miles > (float)$rule['included_miles']) {
        $billableMiles = round($miles - (float)$rule['included_miles'], 1);
        $mileCost = round($billableMiles * (float)$rule['per_mile'], 2);
        $lines[] = [
            'label'  => $billableMiles . ' mi beyond the first ' . (float)$rule['included_miles'],
            'amount' => $mileCost,
        ];
        $subtotal += $mileCost;
    }

    // Condition surcharges — these reflect real extra work for the driver, and
    // they are shown individually so the price never looks arbitrary.
    $surcharges = [
        'accident_surcharge'       => [!empty($opts['is_accident']),                    'Accident recovery'],
        'no_keys_surcharge'        => [array_key_exists('has_keys', $opts) && !$opts['has_keys'], 'No keys available'],
        'wheels_locked_surcharge'  => [array_key_exists('wheels_lock', $opts) && !$opts['wheels_lock'], 'Wheels will not roll'],
        'underground_surcharge'    => [!empty($opts['is_underground']),                 'Underground / low clearance'],
    ];
    foreach ($surcharges as $col => [$applies, $label]) {
        $amt = (float)$rule[$col];
        if ($applies && $amt > 0) {
            $lines[] = ['label' => $label, 'amount' => round($amt, 2)];
            $subtotal += $amt;
        }
    }

    // Time-of-day and weekend multipliers, applied to everything above.
    $multiplier = 1.0;
    $multiplierLabel = null;
    if (isAfterHours()) {
        $multiplier = (float)$rule['after_hours_multiplier'];
        $multiplierLabel = 'After-hours rate';
    } elseif (isWeekend()) {
        $multiplier = (float)$rule['weekend_multiplier'];
        $multiplierLabel = 'Weekend rate';
    }
    if ($multiplier > 1.0) {
        $uplift = round($subtotal * ($multiplier - 1), 2);
        $lines[] = [
            'label'  => $multiplierLabel . ' (' . rtrim(rtrim(number_format($multiplier, 2), '0'), '.') . 'x)',
            'amount' => $uplift,
        ];
        $subtotal += $uplift;
    }

    $total = round($subtotal, 2);
    $minimum = (float)$rule['minimum_total'];
    if ($total < $minimum) {
        $lines[] = ['label' => 'Minimum charge adjustment', 'amount' => round($minimum - $total, 2)];
        $total = $minimum;
    }

    // Our cut is taken out of the total, not added on top — the customer sees
    // one number and the tower sees what they'll actually be paid.
    $fee = consumerFee($total);

    return [
        'ok'             => true,
        'total'          => round($total, 2),
        'lines'          => $lines,
        'platform_fee'   => $fee,
        'tower_receives' => round($total - $fee, 2),
        'billable_miles' => $billableMiles,
        'after_hours'    => isAfterHours(),
        'weekend'        => isWeekend(),
        'goa_amount'     => (float)setting('consumer_goa_amount', 55.00),
    ];
}

// Consumer jobs carry a bigger cut than board jobs: the platform paid for the
// ad click that produced this customer, the board did not.
function consumerFee(float $total): float {
    $pct = (float)setting('consumer_fee_percent', 20.0);
    $min = (float)setting('consumer_fee_minimum', 15.00);
    $fee = round($total * $pct / 100, 2);
    if ($fee < $min) $fee = $min;
    if ($fee > $total) $fee = $total;
    return round($fee, 2);
}
