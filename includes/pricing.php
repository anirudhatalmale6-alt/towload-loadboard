<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/zones.php';
require_once __DIR__ . '/surge.php';

// ═══════════════════════════════════════════════════════════════════════════
//  CONSUMER PRICING
//
//  A stranded motorist will not name a price and will not wait for an auction.
//  The platform quotes instantly, the way Uber does. The quote has to satisfy
//  two people at once: cheap enough that the customer taps Confirm, and fat
//  enough that a tower actually accepts it — otherwise the job sits unclaimed
//  and we have taken money for a truck that never comes.
//
//  ── Who sees what ──────────────────────────────────────────────────────────
//  The customer sees ONE number. All in, nothing itemised, nothing added at
//  the end. That is Ricardo's call and it is the right one: a line item for
//  "no keys" invites an argument at the roadside about whether the keys were
//  really lost.
//
//  The tower sees the full breakdown, because a driver decides whether to roll
//  based on whether the money covers the miles and the winch work.
//
//  The breakdown is STORED on every job regardless. Six weeks later, when a
//  customer disputes the charge with their bank, the only thing that wins that
//  chargeback is showing exactly how the price was built at the moment they
//  agreed to it. Recomputing it against today's rules proves nothing.
//
//  ── Order of operations ────────────────────────────────────────────────────
//  base + mileage + condition surcharges
//    → market rate multiplier (this city vs the national table)
//    → after-hours / weekend multiplier
//    → demand multiplier (capped, killable, see surge.php)
//    → minimum charge
//    → tower net floor
// ═══════════════════════════════════════════════════════════════════════════

/**
 * The rate row for a service, for a zone.
 *
 * Falls back twice rather than failing: a zone-specific row, then the national
 * table, then light duty for the same service. A missing rate row must never
 * lose a customer at the price step — it should quote something sane and show
 * up as a wrong number to fix, not as a dead page.
 */
function getPricingRule(string $serviceType, string $vehicleClass, int $zoneId = 0): ?array {
    $pdo = getDB();
    $sql = "SELECT * FROM pricing_rules
             WHERE service_type = :s AND vehicle_class = :c AND zone_id = :z AND is_active = 1
             LIMIT 1";

    if ($zoneId !== 0) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':s' => $serviceType, ':c' => $vehicleClass, ':z' => $zoneId]);
        if ($rule = $stmt->fetch()) return $rule;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':s' => $serviceType, ':c' => $vehicleClass, ':z' => 0]);
    if ($rule = $stmt->fetch()) return $rule;

    $stmt->execute([':s' => $serviceType, ':c' => 'light', ':z' => 0]);
    return $stmt->fetch() ?: null;
}

/**
 * Scale a national rate row to a local market.
 * One number per city instead of fifteen rows per city.
 */
function applyZoneRates(array $rule, float $multiplier): array {
    if (abs($multiplier - 1.0) < 0.001) return $rule;
    // hook_fee belongs in this list. Every money column has to be here or a
    // market on a 0.95 multiplier scales everything except the one that was
    // added last, and the mistake shows up as a price a few dollars out with
    // nothing failing. included_miles is absent on purpose — it is a distance.
    foreach (['base_fee','hook_fee','per_mile','minimum_total','accident_surcharge',
              'no_keys_surcharge','wheels_locked_surcharge','underground_surcharge'] as $col) {
        $rule[$col] = round((float)$rule[$col] * $multiplier, 2);
    }
    return $rule;
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
 *        is_accident, has_keys, wheels_lock, is_underground,
 *        lat, lng, state         (for zone + demand pricing)
 *        surge                   (optional pre-computed surge, to avoid
 *                                 recomputing between quote and request)
 */
function quoteConsumerJob(array $opts): array {
    $service = $opts['service_type'] ?? 'tow';
    $class   = $opts['vehicle_class'] ?? 'light';
    $miles   = max(0.0, (float)($opts['tow_miles'] ?? 0));

    $lat = isset($opts['lat']) ? (float)$opts['lat'] : null;
    $lng = isset($opts['lng']) ? (float)$opts['lng'] : null;
    $zone = $opts['zone'] ?? resolveZone($lat, $lng, $opts['state'] ?? null);

    $rule = getPricingRule($service, $class, (int)$zone['id']);
    if (!$rule) {
        return ['ok' => false, 'error' => t('err.no_pricing')];
    }
    // Zone rows are already local; only the national table gets scaled.
    if ((int)$rule['zone_id'] === 0) {
        $rule = applyZoneRates($rule, (float)$zone['rate_multiplier']);
    }

    $lines = [];

    $base = (float)$rule['base_fee'];
    $lines[] = ['label' => t('price.base'), 'amount' => round($base, 2)];
    $subtotal = $base;

    // Hook-up, itemised separately when the market charges for it. Zero for
    // every rate entered before the field existed, where the call price already
    // covered it — so nothing that was already priced moves.
    $hook = (float)($rule['hook_fee'] ?? 0);
    if ($hook > 0) {
        $lines[] = ['label' => t('price.hook'), 'amount' => round($hook, 2)];
        $subtotal += $hook;
    }

    // Mileage, only beyond what the base fee already covers.
    $billableMiles = 0.0;
    if ((float)$rule['per_mile'] > 0 && $miles > (float)$rule['included_miles']) {
        $billableMiles = round($miles - (float)$rule['included_miles'], 1);
        $mileCost = round($billableMiles * (float)$rule['per_mile'], 2);
        $lines[] = [
            'label'  => t('price.miles', ['n' => $billableMiles, 'inc' => (float)$rule['included_miles']]),
            'amount' => $mileCost,
        ];
        $subtotal += $mileCost;
    }

    // Condition surcharges — these reflect real extra work for the driver, and
    // they are itemised for the tower so the job never looks underpaid.
    $surcharges = [
        'accident_surcharge'       => [!empty($opts['is_accident']),                    'price.accident'],
        'no_keys_surcharge'        => [array_key_exists('has_keys', $opts) && !$opts['has_keys'], 'price.no_keys'],
        'wheels_locked_surcharge'  => [array_key_exists('wheels_lock', $opts) && !$opts['wheels_lock'], 'price.wheels'],
        'underground_surcharge'    => [!empty($opts['is_underground']),                 'price.underground'],
    ];
    foreach ($surcharges as $col => [$applies, $labelKey]) {
        $amt = (float)$rule[$col];
        if ($applies && $amt > 0) {
            $lines[] = ['label' => t($labelKey), 'amount' => round($amt, 2)];
            $subtotal += $amt;
        }
    }

    // Time-of-day and weekend multipliers, applied to everything above.
    $multiplier = 1.0;
    $multiplierLabel = null;
    if (isAfterHours()) {
        $multiplier = (float)$rule['after_hours_multiplier'];
        $multiplierLabel = 'price.after_hours';
    } elseif (isWeekend()) {
        $multiplier = (float)$rule['weekend_multiplier'];
        $multiplierLabel = 'price.weekend';
    }
    if ($multiplier > 1.0) {
        $uplift = round($subtotal * ($multiplier - 1), 2);
        $lines[] = [
            'label'  => t($multiplierLabel, ['x' => trimNum($multiplier)]),
            'amount' => $uplift,
        ];
        $subtotal += $uplift;
    }

    // ─── Demand pricing ──────────────────────────────────────────────────────
    // Passed in when the caller already computed it, so the number a customer
    // was shown is the number they are charged even if the market moves between
    // the quote and the confirm tap.
    $surge = $opts['surge'] ?? computeSurge($lat, $lng, $zone, $opts['state'] ?? null);
    $surgeMult = (float)$surge['multiplier'];
    if ($surgeMult > 1.0) {
        $uplift = round($subtotal * ($surgeMult - 1), 2);
        $lines[] = [
            'label'  => t('price.demand', ['x' => trimNum($surgeMult)]),
            'amount' => $uplift,
        ];
        $subtotal += $uplift;
    }

    $total = round($subtotal, 2);
    $minimum = (float)$rule['minimum_total'];
    if ($total < $minimum) {
        $lines[] = ['label' => t('price.minimum'), 'amount' => round($minimum - $total, 2)];
        $total = $minimum;
    }

    // ─── Tower net floor ─────────────────────────────────────────────────────
    // Optional. Below a certain payout it is not worth starting the truck, and
    // a job nobody accepts is worse for everyone than a job priced honestly.
    // 0 disables it entirely.
    $floor = (float)setting('tower_minimum_net', 0);
    if ($floor > 0 && round($total - consumerFee($total), 2) < $floor) {
        // Solve for the total that clears the floor after the percentage cut.
        $pct = (float)setting('consumer_fee_percent', 10.0);
        $needed = $pct >= 100 ? $total : round($floor / (1 - $pct / 100), 2);
        if ($needed > $total) {
            $lines[] = ['label' => t('price.minimum'), 'amount' => round($needed - $total, 2)];
            $total = $needed;
        }
    }

    // Our cut comes out of the total, never added on top — the customer sees
    // one number and the tower sees what they will actually be paid.
    $fee = consumerFee($total);

    return [
        'ok'             => true,
        'total'          => round($total, 2),
        'lines'          => $lines,
        'platform_fee'   => $fee,
        'tower_receives' => round($total - $fee, 2),
        'billable_miles' => $billableMiles,
        'included_miles' => (float)$rule['included_miles'],
        'after_hours'    => isAfterHours(),
        'weekend'        => isWeekend(),
        'surge'          => $surge,
        'zone_id'        => (int)$zone['id'],
        'zone_name'      => zoneName($zone),
        'goa_amount'     => (float)setting('consumer_goa_amount', 55.00),
    ];
}

/**
 * What the CUSTOMER is shown: one price and what it covers.
 *
 * Deliberately no line items and no percentages. "Includes 12 miles of towing"
 * answers the only question they actually have — is anything going to be added
 * later — and the answer is no.
 */
function customerQuoteView(array $quote, string $service = 'tow'): array {
    $includes = [];

    if ($service === 'tow') {
        $miles = (float)$quote['included_miles'] + (float)$quote['billable_miles'];
        $includes[] = $miles > 0
            ? t('inc.miles', ['n' => trimNum($miles)])
            : t('inc.tow');
    } else {
        $includes[] = t('inc.service');
    }
    $includes[] = t('inc.hookup');
    $includes[] = t('inc.allin');

    return [
        'total'       => $quote['total'],
        'includes'    => $includes,
        'goa_amount'  => $quote['goa_amount'],
        // True when demand pricing moved the number. The customer is told that
        // prices are higher than usual right now — not by how much, and not why.
        // Hiding it entirely is what makes people feel cheated after the fact.
        'busy_now'    => (float)$quote['surge']['multiplier'] > 1.0,
    ];
}

/**
 * What the TOWER is shown: every line, the demand multiplier and its reason,
 * the platform cut, and the net. This is the screen a driver decides on.
 */
function towerQuoteView(array $quote): array {
    return [
        'total'          => $quote['total'],
        'lines'          => $quote['lines'],
        'platform_fee'   => $quote['platform_fee'],
        'tower_receives' => $quote['tower_receives'],
        'surge'          => [
            'multiplier' => (float)$quote['surge']['multiplier'],
            'reason'     => $quote['surge']['reason'],
            'reason_text'=> surgeReasonText($quote['surge']['reason']),
        ],
        'zone_name'      => $quote['zone_name'],
    ];
}

// The platform's cut. Defaults mirror the live settings so a missing row can
// never silently charge a different rate than the one that was agreed.
function consumerFee(float $total): float {
    $pct = (float)setting('consumer_fee_percent', 10.0);
    $min = (float)setting('consumer_fee_minimum', 5.00);
    $fee = round($total * $pct / 100, 2);
    if ($fee < $min) $fee = $min;
    if ($fee > $total) $fee = $total;
    return round($fee, 2);
}

// 1.25 not 1.25000, 12 not 12.0 — these land inside sentences.
function trimNum(float $n): string {
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
}
