<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/zones.php';
require_once __DIR__ . '/pricing.php';

// ═══════════════════════════════════════════════════════════════════════════
//  MARKET RATES — the price a city charges comes from the companies in it
//
//  Each approved company states what it charges. A zone's rates are the
//  average of those figures across the approved companies inside that zone,
//  recomputed whenever one of them changes or a company is approved.
//
//  ── Three rules this file exists to enforce ───────────────────────────────
//
//  1. ONE COMPANY NEVER SEES ANOTHER'S RATES. Not in an API response, not in
//     a page, not in an export. What a company charges is commercially
//     sensitive and it belongs to them. Everything here reduces the set to a
//     single number before it leaves.
//
//  2. MANUAL ALWAYS WINS. A pricing_rules row marked 'manual' is never
//     touched. The override is per zone and per service, so one price held by
//     hand does not drag a whole city off automatic.
//
//  3. THE AVERAGE IS AN INPUT, NOT A PUBLISHED PRICE. It informs one price
//     that the platform sets and can override. That distinction is the whole
//     difference between market research and running a price-fixing ring for
//     a group of competitors, and it is worth keeping on the right side of.
//
//  ── The part that is easy to get backwards ────────────────────────────────
//  A company saying "I charge $110 for a light tow" means it wants $110 in
//  its pocket. Our fee comes out of the customer's total, so charging the
//  customer $110 pays that company $99 and every one of them declines. Under
//  the default `rate_basis` of tower_net the stated figure is treated as the
//  NET and the customer total is grossed up to cover the fee.
// ═══════════════════════════════════════════════════════════════════════════

/** The services a company is asked about, and the shape of each answer. */
function rateSheetShape(): array {
    // 'miles' — asks for a mileage allowance and a per-mile rate beyond it.
    // 'hook'  — asks for the hook-up fee separately from the call price.
    //
    // The roadside services carry mileage now too: their flat price used to
    // cover any distance, which is fine across town and wrong when the
    // customer is 40 miles out. per_mile left blank keeps the old behaviour.
    return [
        ['service' => 'tow',            'class' => 'light',  'miles' => true,  'hook' => true],
        ['service' => 'tow',            'class' => 'medium', 'miles' => true,  'hook' => true],
        ['service' => 'tow',            'class' => 'heavy',  'miles' => true,  'hook' => true],
        ['service' => 'winch_recovery', 'class' => 'light',  'miles' => false, 'hook' => false],
        ['service' => 'lockout',        'class' => 'light',  'miles' => true,  'hook' => false],
        ['service' => 'jumpstart',      'class' => 'light',  'miles' => false, 'hook' => false],
        ['service' => 'tire_change',    'class' => 'light',  'miles' => true,  'hook' => false],
        ['service' => 'fuel_delivery',  'class' => 'light',  'miles' => true,  'hook' => false],
    ];
}

function towerRates(int $accountId): array {
    $st = getDB()->prepare(
        "SELECT service_type, vehicle_class, base_fee, hook_fee, included_miles, per_mile, updated_at
           FROM tower_rates WHERE account_id = :a"
    );
    $st->execute([':a' => $accountId]);
    $out = [];
    foreach ($st as $r) {
        $out[$r['service_type'] . ':' . $r['vehicle_class']] = [
            'base_fee'       => (float)$r['base_fee'],
            'hook_fee'       => (float)$r['hook_fee'],
            'included_miles' => (float)$r['included_miles'],
            'per_mile'       => (float)$r['per_mile'],
            'updated_at'     => $r['updated_at'],
        ];
    }
    return $out;
}

/**
 * Save one company's sheet. Rows with no price are DELETED rather than stored
 * as zero — "I don't do heavy duty" and "I do heavy duty for nothing" are
 * different answers, and an average that swallowed the second would drag a
 * whole city's heavy rate to the floor.
 */
function saveTowerRates(int $accountId, array $rows): int {
    $pdo = getDB();
    $allowed = [];
    foreach (rateSheetShape() as $s) $allowed[$s['service'] . ':' . $s['class']] = $s;

    $up = $pdo->prepare(
        "INSERT INTO tower_rates (account_id, service_type, vehicle_class, base_fee, hook_fee, included_miles, per_mile)
              VALUES (:a, :s, :c, :b, :h, :i, :m)
         ON DUPLICATE KEY UPDATE base_fee = VALUES(base_fee),
                                 hook_fee = VALUES(hook_fee),
                                 included_miles = VALUES(included_miles),
                                 per_mile = VALUES(per_mile)"
    );
    $del = $pdo->prepare(
        "DELETE FROM tower_rates WHERE account_id = :a AND service_type = :s AND vehicle_class = :c"
    );

    $n = 0;
    foreach ($rows as $row) {
        $key = ($row['service_type'] ?? '') . ':' . ($row['vehicle_class'] ?? 'light');
        if (!isset($allowed[$key])) continue;
        [$service, $class] = explode(':', $key);

        $base = isset($row['base_fee']) && $row['base_fee'] !== '' ? (float)$row['base_fee'] : 0.0;
        if ($base <= 0) {
            $del->execute([':a' => $accountId, ':s' => $service, ':c' => $class]);
            continue;
        }
        // A tow with no per-mile figure is a flat price for the whole job. Left
        // at zero it prices every distance the same, so treat blank included
        // miles as "the base covers a short local tow" rather than zero miles.
        $miles = $allowed[$key]['miles']
            ? (isset($row['included_miles']) && $row['included_miles'] !== '' ? (float)$row['included_miles'] : 0.0)
            : 0.0;
        $per   = $allowed[$key]['miles']
            ? (isset($row['per_mile']) && $row['per_mile'] !== '' ? (float)$row['per_mile'] : 0.0)
            : 0.0;

        // Only tows are asked for it, and blank means "my call price already
        // covers the hook" — which is what the form asked for before this
        // field existed, so it must stay the harmless answer.
        $hook = !empty($allowed[$key]['hook']) && isset($row['hook_fee']) && $row['hook_fee'] !== ''
            ? max(0.0, (float)$row['hook_fee']) : 0.0;

        $up->execute([':a' => $accountId, ':s' => $service, ':c' => $class,
                      ':b' => round($base, 2), ':h' => round($hook, 2),
                      ':i' => round($miles, 2), ':m' => round($per, 2)]);
        $n++;
    }
    return $n;
}

/** Approved tower accounts whose base sits inside a zone. */
function towersInZone(array $zone): array {
    $pdo = getDB();
    $sql = "SELECT a.id, tp.base_lat, tp.base_lng
              FROM accounts a
              JOIN tower_profiles tp ON tp.account_id = a.id
             WHERE a.account_type = 'tower'
               AND a.is_active = 1
               AND a.verification_status = 'approved'
               AND tp.base_lat IS NOT NULL AND tp.base_lng IS NOT NULL";

    $ids = [];
    if ((int)$zone['radius_miles'] > 0 && $zone['center_lat'] !== null) {
        foreach ($pdo->query($sql) as $r) {
            $d = haversineMiles((float)$zone['center_lat'], (float)$zone['center_lng'],
                                (float)$r['base_lat'], (float)$r['base_lng']);
            if ($d <= (float)$zone['radius_miles']) $ids[] = (int)$r['id'];
        }
        return $ids;
    }

    // Statewide zone. The state on the account is what defines membership here
    // — a circle test has no centre to work from.
    if (!empty($zone['state'])) {
        $st = $pdo->prepare($sql . " AND a.state = :st");
        $st->execute([':st' => strtoupper(substr((string)$zone['state'], 0, 2))]);
        foreach ($st as $r) $ids[] = (int)$r['id'];
    }
    return $ids;
}

/**
 * The average of what the companies in a zone charge, per service and class.
 * Returns [] when nobody has answered — an empty result must leave the
 * existing rates alone rather than reprice a city at zero.
 */
function marketAverages(array $accountIds): array {
    if (!$accountIds) return [];
    $in = implode(',', array_map('intval', $accountIds));

    // AVG over the rows that exist. A company that did not price heavy duty
    // contributes nothing to the heavy average instead of a zero.
    $rows = getDB()->query(
        "SELECT service_type, vehicle_class,
                COUNT(*)                AS n,
                AVG(base_fee)           AS base_fee,
                AVG(hook_fee)           AS hook_fee,
                AVG(NULLIF(included_miles, 0)) AS included_miles,
                AVG(NULLIF(per_mile, 0))       AS per_mile
           FROM tower_rates
          WHERE account_id IN ($in) AND base_fee > 0
          GROUP BY service_type, vehicle_class"
    )->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $out[$r['service_type'] . ':' . $r['vehicle_class']] = [
            'n'              => (int)$r['n'],
            'base_fee'       => round((float)$r['base_fee'], 2),
            // Averaged across ALL of them, zeros included — a company with no
            // separate hook fee genuinely charges nothing for it, so it must
            // pull the market average down rather than be skipped.
            'hook_fee'       => round((float)$r['hook_fee'], 2),
            'included_miles' => $r['included_miles'] === null ? 0.0 : round((float)$r['included_miles'], 1),
            'per_mile'       => $r['per_mile'] === null ? 0.0 : round((float)$r['per_mile'], 2),
        ];
    }
    return $out;
}

/**
 * Turn what a company wants to receive into what the customer is charged.
 *
 * Our fee is a percentage of the customer's total, taken out of it. So paying
 * a company $110 means charging $110 / (1 - fee). Getting this backwards is
 * silent: every price looks reasonable and every operator quietly declines,
 * because each job pays 10% under what they said they would work for.
 */
function grossUpForFee(float $towerNet): float {
    if ((string)setting('rate_basis', 'tower_net') !== 'tower_net') return $towerNet;
    $pct = (float)setting('consumer_fee_percent', 10.0);
    if ($pct <= 0 || $pct >= 100) return $towerNet;
    return round($towerNet / (1 - $pct / 100), 2);
}

/**
 * Rewrite a zone's automatic rates from the companies working in it.
 * Returns a short report so the caller can log or show what moved.
 */
function recomputeZoneRates(int $zoneId): array {
    if ((string)setting('auto_rates_enabled', '1') !== '1') {
        return ['skipped' => 'auto_rates_disabled'];
    }
    $zone = zoneById($zoneId);
    if (!$zone || (int)($zone['id'] ?? -1) !== $zoneId) return ['skipped' => 'no_such_zone'];

    $minN  = max(1, (int)setting('auto_rates_min_companies', 1));
    $avgs  = marketAverages(towersInZone($zone));
    $pdo   = getDB();
    $written = 0; $held = 0; $thin = 0;

    foreach ($avgs as $key => $a) {
        if ($a['n'] < $minN) { $thin++; continue; }
        [$service, $class] = explode(':', $key);

        // Never overwrite a price someone set deliberately.
        $ex = $pdo->prepare(
            "SELECT id, rate_source FROM pricing_rules
              WHERE zone_id = :z AND service_type = :s AND vehicle_class = :c LIMIT 1"
        );
        $ex->execute([':z' => $zoneId, ':s' => $service, ':c' => $class]);
        $row = $ex->fetch();
        if ($row && $row['rate_source'] === 'manual') { $held++; continue; }

        $base = grossUpForFee($a['base_fee']);
        $hook = grossUpForFee($a['hook_fee']);
        $per  = grossUpForFee($a['per_mile']);

        // Time-of-day policy is the platform's, not the company's. A company
        // states a flat rate; the night and weekend premiums, and what an
        // accident or a locked wheel adds, are decisions made once for the
        // whole product. Inherit them from the national row so a market going
        // automatic does not silently drop them to 1.0 and start selling
        // Saturday nights at the Tuesday price.
        $base0 = $pdo->prepare(
            "SELECT after_hours_multiplier, weekend_multiplier, accident_surcharge,
                    no_keys_surcharge, wheels_locked_surcharge, underground_surcharge
               FROM pricing_rules
              WHERE zone_id = 0 AND service_type = :s AND vehicle_class = :c LIMIT 1"
        );
        $base0->execute([':s' => $service, ':c' => $class]);
        $d = $base0->fetch() ?: [
            'after_hours_multiplier' => 1.00, 'weekend_multiplier' => 1.00,
            'accident_surcharge' => 0, 'no_keys_surcharge' => 0,
            'wheels_locked_surcharge' => 0, 'underground_surcharge' => 0,
        ];

        $pdo->prepare(
            "INSERT INTO pricing_rules
                 (zone_id, rate_source, sample_size, computed_at, service_type, vehicle_class,
                  base_fee, hook_fee, included_miles, per_mile, minimum_total,
                  after_hours_multiplier, weekend_multiplier, accident_surcharge,
                  no_keys_surcharge, wheels_locked_surcharge, underground_surcharge, is_active)
             VALUES (:z, 'auto', :n, NOW(), :s, :c, :b, :hk, :i, :m, :min,
                     :ah, :wk, :acc, :nk, :wl, :ug, 1)
             ON DUPLICATE KEY UPDATE
                 rate_source = 'auto', sample_size = VALUES(sample_size),
                 computed_at = NOW(), base_fee = VALUES(base_fee),
                 hook_fee = VALUES(hook_fee),
                 included_miles = VALUES(included_miles), per_mile = VALUES(per_mile),
                 minimum_total = VALUES(minimum_total),
                 -- Refreshed too, not just seeded. An 'auto' row is entirely
                 -- derived; leaving these behind on update means a change to
                 -- the national weekend policy reaches every market except the
                 -- ones that already went automatic. To hold any of it by
                 -- hand, switch the row to manual — that is what manual is.
                 after_hours_multiplier = VALUES(after_hours_multiplier),
                 weekend_multiplier = VALUES(weekend_multiplier),
                 accident_surcharge = VALUES(accident_surcharge),
                 no_keys_surcharge = VALUES(no_keys_surcharge),
                 wheels_locked_surcharge = VALUES(wheels_locked_surcharge),
                 underground_surcharge = VALUES(underground_surcharge),
                 is_active = 1"
        )->execute([
            ':z' => $zoneId, ':n' => $a['n'], ':s' => $service, ':c' => $class,
            ':b' => $base, ':hk' => $hook, ':i' => $a['included_miles'], ':m' => $per,
            // The floor is everything charged before a wheel turns — call price
            // plus the hook. Leaving the hook out of it would let a short job
            // price under what the companies said they charge to hook up.
            ':min' => round($base + $hook, 2),
            ':ah' => $d['after_hours_multiplier'], ':wk' => $d['weekend_multiplier'],
            ':acc' => $d['accident_surcharge'], ':nk' => $d['no_keys_surcharge'],
            ':wl' => $d['wheels_locked_surcharge'], ':ug' => $d['underground_surcharge'],
        ]);
        $written++;
    }

    return ['zone_id' => $zoneId, 'written' => $written, 'held_manual' => $held,
            'too_few_companies' => $thin, 'companies' => count(towersInZone($zone))];
}

function recomputeAllZones(): array {
    $out = [];
    foreach (getDB()->query("SELECT id FROM pricing_zones WHERE is_active = 1") as $z) {
        $out[] = recomputeZoneRates((int)$z['id']);
    }
    return $out;
}

/**
 * Open a market around a company that has just been approved.
 *
 * Does nothing if a live zone already reaches them, so approving the tenth
 * company in Miami does not carve a tenth circle out of it.
 *
 * Deliberately keyed off APPROVAL rather than signup. Coverage already
 * requires an approved truck in range, so a zone created at signup would sit
 * there doing nothing until approval anyway — and a market that opens when
 * anyone fills in a form is a market where the first customer can be sent to
 * a company nobody has checked.
 */
function ensureZoneForTower(int $accountId): ?int {
    if ((string)setting('auto_open_markets', '1') !== '1') return null;

    $st = getDB()->prepare(
        "SELECT a.name, a.city, a.state, tp.base_lat, tp.base_lng
           FROM accounts a JOIN tower_profiles tp ON tp.account_id = a.id
          WHERE a.id = :id AND a.account_type = 'tower'
            AND a.verification_status = 'approved' AND a.is_active = 1"
    );
    $st->execute([':id' => $accountId]);
    $t = $st->fetch();
    if (!$t || $t['base_lat'] === null || $t['base_lng'] === null) return null;

    $lat = (float)$t['base_lat'];
    $lng = (float)$t['base_lng'];

    $existing = resolveZone($lat, $lng, $t['state'] ?? null);
    if (!empty($existing['is_live']) && (int)$existing['id'] !== NATIONAL_ZONE_ID) {
        // Already covered. Their rates still belong in that zone's average.
        recomputeZoneRates((int)$existing['id']);
        return (int)$existing['id'];
    }

    $radius = max(5, (float)setting('auto_open_radius_miles', 35));
    $city   = trim((string)($t['city'] ?? ''));
    $state  = strtoupper(substr((string)($t['state'] ?? ''), 0, 2));
    $name   = $city !== '' ? ($city . ($state ? ', ' . $state : '')) : ('Market ' . $accountId);

    $ins = getDB()->prepare(
        "INSERT INTO pricing_zones
             (name, name_es, state, center_lat, center_lng, radius_miles,
              rate_multiplier, is_live, is_active, surge_enabled,
              auto_created, opened_by_account_id)
         VALUES (:n, :n2, :st, :lat, :lng, :r, 1.00, 1, 1, 1, 1, :acct)"
    );
    $ins->execute([
        ':n' => $name, ':n2' => $name, ':st' => $state ?: null,
        ':lat' => $lat, ':lng' => $lng, ':r' => $radius, ':acct' => $accountId,
    ]);
    $zoneId = (int)getDB()->lastInsertId();
    bustZoneCache();

    recomputeZoneRates($zoneId);
    return $zoneId;
}
