<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/zones.php';

// ═══════════════════════════════════════════════════════════════════════════
//  DEMAND PRICING
//
//  Why this is an algorithm and not a model:
//
//   • It has to return a number before a stranded person on a phone loses
//     patience. Sub-millisecond, from two COUNT queries.
//   • When a customer disputes the charge — or a state regulator asks — you
//     have to be able to say exactly why the price was what it was. Every
//     quote records its inputs. A model that cannot explain itself cannot
//     answer either question.
//   • On day one there is no data to train on. What this does instead is
//     WRITE the data: surge_snapshots is a per-minute time series of demand,
//     supply and the multiplier chosen, and every call keeps the numbers that
//     produced its own price. When there is enough volume to fit a real model,
//     the inputs and outcomes are already sitting there and it drops in behind
//     the same function signature.
//
//  Three hard rules, in order of who wins:
//
//   1. EMERGENCY MODE beats everything. Raising the price of towing during a
//      declared state of emergency is unlawful in Florida (§501.160) and in
//      roughly 35 other states, and towing is often named in the statute. A
//      hurricane is simultaneously the largest demand spike this platform will
//      ever see and the exact moment surge becomes a crime. One switch, and it
//      applies instantly and everywhere.
//   2. A manual override beats the algorithm. It is an explicit human decision.
//      It always carries an expiry, because an override set during a storm and
//      forgotten will quietly ruin conversion for months.
//   3. The algorithm is capped, and can never discount below 1.0 by itself.
// ═══════════════════════════════════════════════════════════════════════════

// A typo ceiling, not a business rule. Protects against someone typing 20
// instead of 2.0 in the admin panel and billing a $95 tow at $1,900.
const SURGE_ABSOLUTE_MAX = 3.0;

function surgeTiers(): array {
    $raw = (string)setting('surge_tiers', '0.75:1.0,1.25:1.1,1.75:1.25,2.5:1.4,3.5:1.6,999:1.8');
    $tiers = [];
    foreach (explode(',', $raw) as $pair) {
        $parts = explode(':', trim($pair));
        if (count($parts) !== 2) continue;
        $tiers[] = ['ratio' => (float)$parts[0], 'mult' => (float)$parts[1]];
    }
    usort($tiers, fn($a, $b) => $a['ratio'] <=> $b['ratio']);
    return $tiers ?: [['ratio' => 999, 'mult' => 1.0]];
}

function surgeStateDisabled(?string $state): bool {
    if (!$state) return false;
    $list = array_filter(array_map('trim', explode(',', (string)setting('surge_disabled_states', ''))));
    return in_array(strtoupper(substr($state, 0, 2)), array_map('strtoupper', $list), true);
}

/**
 * Live demand near a point: consumer jobs still looking for a truck, plus a
 * weighted count of jobs that recently expired with nobody accepting.
 *
 * The unclaimed count matters more than it looks. An area with two open jobs
 * and four trucks looks balanced — unless six jobs already died unclaimed this
 * hour, which means those trucks are not really available at this price.
 */
function surgeDemand(float $lat, float $lng, float $radius): array {
    $pdo = getDB();
    $box = boundingBox($lat, $lng, $radius);
    $window    = max(1, (int)setting('surge_window_minutes', 15));
    $unclaimed = max(1, (int)setting('surge_unclaimed_minutes', 60));

    $geo = "pickup_lat BETWEEN :minlat AND :maxlat AND pickup_lng BETWEEN :minlng AND :maxlng";
    $box4 = [
        ':minlat' => $box['min_lat'], ':maxlat' => $box['max_lat'],
        ':minlng' => $box['min_lng'], ':maxlng' => $box['max_lng'],
    ];

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS n FROM calls
          WHERE status = 'open' AND $geo
            AND created_at >= DATE_SUB(NOW(), INTERVAL :w MINUTE)"
    );
    $stmt->execute($box4 + [':w' => $window]);
    $open = (int)$stmt->fetch()['n'];

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS n FROM calls
          WHERE status = 'expired' AND $geo
            AND created_at >= DATE_SUB(NOW(), INTERVAL :w MINUTE)"
    );
    $stmt->execute($box4 + [':w' => $unclaimed]);
    $dead = (int)$stmt->fetch()['n'];

    return ['open' => $open, 'unclaimed' => $dead];
}

/**
 * Trucks that could actually take a job right now: approved and in range,
 * minus the ones already on one. A busy truck is not supply.
 */
function surgeSupply(float $lat, float $lng, float $radius): int {
    $total = approvedTowersNear($lat, $lng, $radius);
    if ($total === 0) return 0;

    $box = boundingBox($lat, $lng, $radius);
    $stmt = getDB()->prepare(
        "SELECT COUNT(DISTINCT awarded_tower_account_id) AS n FROM calls
          WHERE awarded_tower_account_id IS NOT NULL
            AND status IN ('awarded','en_route','on_scene','in_progress')
            AND pickup_lat BETWEEN :minlat AND :maxlat
            AND pickup_lng BETWEEN :minlng AND :maxlng"
    );
    $stmt->execute([
        ':minlat' => $box['min_lat'], ':maxlat' => $box['max_lat'],
        ':minlng' => $box['min_lng'], ':maxlng' => $box['max_lng'],
    ]);
    $busy = (int)$stmt->fetch()['n'];

    return max(0, $total - $busy);
}

/**
 * The multiplier for a job at this point, right now.
 *
 * Returns multiplier, a machine-readable reason, and the raw counts. The
 * caller is expected to store all of it on the call.
 */
function computeSurge(?float $lat, ?float $lng, ?array $zone = null, ?string $state = null): array {
    $zone = $zone ?? resolveZone($lat, $lng, $state);
    $state = $state ?: ($zone['state'] ?? null);
    $max = min((float)setting('surge_max_multiplier', 1.8), SURGE_ABSOLUTE_MAX);

    $flat = function (string $reason) use ($zone) {
        return ['multiplier' => 1.00, 'reason' => $reason, 'demand' => 0,
                'supply' => 0, 'unclaimed' => 0, 'ratio' => 0.0,
                'zone_id' => (int)$zone['id']];
    };

    // 1. The emergency brake. Nothing overrides this — not a manual override,
    //    not a per-zone setting.
    if ((string)setting('emergency_mode', '0') === '1')  return $flat('emergency_mode');
    if (surgeStateDisabled($state))                      return $flat('state_disabled');
    if ((string)setting('surge_enabled', '1') !== '1')   return $flat('disabled');
    if (empty($zone['surge_enabled']))                   return $flat('zone_disabled');

    // 2. A live manual override. Expiry is mandatory — an override with no
    //    expiry is treated as expired rather than as permanent.
    if ($zone['manual_surge'] !== null && !empty($zone['manual_surge_until'])
        && strtotime($zone['manual_surge_until']) > time()) {
        $m = min(max((float)$zone['manual_surge'], 0.5), SURGE_ABSOLUTE_MAX);
        return ['multiplier' => round($m, 2), 'reason' => 'manual', 'demand' => 0,
                'supply' => 0, 'unclaimed' => 0, 'ratio' => 0.0, 'zone_id' => (int)$zone['id']];
    }

    if ($lat === null || $lng === null) return $flat('no_location');

    $radius = (float)setting('coverage_radius_miles', 35);
    $d = surgeDemand($lat, $lng, $radius);
    $supply = surgeSupply($lat, $lng, $radius);

    // No trucks at all is a coverage problem, not a pricing one. Charging more
    // does not conjure a truck, and it is indefensible if it ends in a refund.
    if ($supply === 0) return $flat('no_supply');

    $weight  = (float)setting('surge_unclaimed_weight', 0.5);
    $demand  = $d['open'] + ($d['unclaimed'] * $weight);
    $minDemand = (int)setting('surge_min_demand', 2);

    // One request in an area is noise, not a shortage.
    if ($d['open'] < $minDemand) {
        return ['multiplier' => 1.00, 'reason' => 'low_demand', 'demand' => $d['open'],
                'supply' => $supply, 'unclaimed' => $d['unclaimed'], 'ratio' => 0.0,
                'zone_id' => (int)$zone['id']];
    }

    $ratio = $demand / max(1, $supply);
    $mult = 1.0;
    foreach (surgeTiers() as $tier) {
        if ($ratio <= $tier['ratio']) { $mult = $tier['mult']; break; }
        $mult = $tier['mult'];
    }

    // Never below 1.0 automatically, never above the cap. Rounded to 0.05 so
    // the same conditions produce the same number twice.
    $mult = max(1.0, min($mult, $max));
    $mult = round($mult * 20) / 20;

    $out = ['multiplier' => round($mult, 2), 'reason' => 'computed',
            'demand' => $d['open'], 'supply' => $supply,
            'unclaimed' => $d['unclaimed'], 'ratio' => round($ratio, 3),
            'zone_id' => (int)$zone['id']];

    recordSurgeSnapshot($out);
    return $out;
}

/**
 * One row per zone per minute. A public quote endpoint can be hit thousands of
 * times an hour by a bot; the unique key means that produces one row, not
 * thousands, while still giving a usable time series.
 */
function recordSurgeSnapshot(array $s): void {
    try {
        getDB()->prepare(
            "INSERT IGNORE INTO surge_snapshots
                (zone_id, minute_bucket, open_demand, available_supply, unclaimed_recent,
                 ratio, multiplier, reason)
             VALUES (:z, DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:00'), :d, :s, :u, :r, :m, :reason)"
        )->execute([
            ':z' => $s['zone_id'], ':d' => $s['demand'], ':s' => $s['supply'],
            ':u' => $s['unclaimed'], ':r' => $s['ratio'], ':m' => $s['multiplier'],
            ':reason' => $s['reason'],
        ]);
    } catch (Throwable $e) {
        // Analytics must never take down a quote. Losing a data point is
        // survivable; failing to price a job in front of a stranded customer
        // is not.
    }
}

// Human-readable, for the tower's itemised view and the admin panel. The
// customer never sees this — they see one price.
function surgeReasonText(string $reason): string {
    $map = [
        'computed'       => t('surge.computed'),
        'manual'         => t('surge.manual'),
        'emergency_mode' => t('surge.emergency'),
        'state_disabled' => t('surge.off'),
        'zone_disabled'  => t('surge.off'),
        'disabled'       => t('surge.off'),
        'low_demand'     => t('surge.normal'),
        'no_supply'      => t('surge.normal'),
        'no_location'    => t('surge.normal'),
    ];
    return $map[$reason] ?? $reason;
}
