<?php
require_once __DIR__ . '/helpers.php';

// ═══════════════════════════════════════════════════════════════════════════
//  PRICING ZONES + COVERAGE
//
//  Going nationwide is easy to say and easy to get wrong. Two things break
//  the moment you leave one metro:
//
//   1. Prices. A light-duty tow is not the same number in Miami and in rural
//      Georgia. Maintaining a full rate table per city becomes unmanageable
//      inside a month, so there is ONE national table and a per-market
//      multiplier. Opening a city is one row.
//
//   2. Coverage. An ad can run anywhere; a truck cannot. Taking a card for a
//      job with no truck in range is the single worst thing this platform can
//      do — the customer waits, nobody comes, the hold expires, and we paid
//      for the click that produced them. Coverage is therefore decided by
//      counting real approved trucks, not by looking at a state code.
// ═══════════════════════════════════════════════════════════════════════════

const NATIONAL_ZONE_ID = 0;

function nationalZone(): array {
    return [
        'id' => NATIONAL_ZONE_ID,
        'name' => 'United States', 'name_es' => 'Estados Unidos',
        'state' => null, 'radius_miles' => 0,
        'rate_multiplier' => 1.00,
        'is_live' => 0,              // nowhere is live by default; zones opt in
        'surge_enabled' => 1,
        'manual_surge' => null, 'manual_surge_until' => null,
    ];
}

/**
 * The zone a point falls in. Most specific wins: a city circle beats a
 * statewide row beats the national default.
 *
 * Circles are compared by real distance, not by the bounding box, so a zone
 * defined around downtown Miami does not accidentally claim Naples.
 */
function resolveZone(?float $lat, ?float $lng, ?string $state = null): array {
    static $zones = null;
    if ($zones === null) {
        $zones = getDB()->query(
            "SELECT * FROM pricing_zones WHERE is_active = 1 ORDER BY radius_miles ASC"
        )->fetchAll();
    }

    $state = $state ? strtoupper(substr($state, 0, 2)) : null;
    $best = null;
    $bestRadius = null;

    foreach ($zones as $z) {
        // Circle zone
        if ((int)$z['radius_miles'] > 0 && $z['center_lat'] !== null && $z['center_lng'] !== null) {
            if ($lat === null || $lng === null) continue;
            $d = haversineMiles((float)$z['center_lat'], (float)$z['center_lng'], $lat, $lng);
            if ($d <= (float)$z['radius_miles']) {
                if ($bestRadius === null || (float)$z['radius_miles'] < $bestRadius) {
                    $best = $z; $bestRadius = (float)$z['radius_miles'];
                }
            }
            continue;
        }
        // Statewide zone — only used if no circle matched, hence the large
        // sentinel radius rather than a second pass.
        if (!empty($z['state']) && $state && $z['state'] === $state) {
            if ($bestRadius === null || $bestRadius > 100000) {
                $best = $z; $bestRadius = 100000;
            }
        }
    }

    return $best ?: nationalZone();
}

function zoneById(int $id): array {
    if ($id === NATIONAL_ZONE_ID) return nationalZone();
    $stmt = getDB()->prepare("SELECT * FROM pricing_zones WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: nationalZone();
}

function zoneName(array $zone): string {
    return currentLang() === 'es' && !empty($zone['name_es'])
        ? $zone['name_es'] : ($zone['name'] ?? 'United States');
}

// ─── COVERAGE ────────────────────────────────────────────────────────────────

/**
 * How many approved, active towing companies are based within range of a point.
 *
 * Deliberately does NOT filter on capability. A customer asking for a heavy
 * wreck in a town with only light-duty trucks still deserves a straight answer
 * about the area, and gating coverage on capability would hide a whole market
 * behind one unusual request.
 */
function approvedTowersNear(?float $lat, ?float $lng, ?float $radius = null): int {
    if ($lat === null || $lng === null) return 0;
    $radius = $radius ?? (float)setting('coverage_radius_miles', 35);

    $box = boundingBox($lat, $lng, $radius);
    $stmt = getDB()->prepare(
        "SELECT tp.base_lat, tp.base_lng, tp.service_radius_miles
           FROM accounts a
           JOIN tower_profiles tp ON tp.account_id = a.id
          WHERE a.account_type = 'tower'
            AND a.is_active = 1
            AND a.verification_status = 'approved'
            AND tp.base_lat BETWEEN :minlat AND :maxlat
            AND tp.base_lng BETWEEN :minlng AND :maxlng"
    );
    $stmt->execute([
        ':minlat' => $box['min_lat'], ':maxlat' => $box['max_lat'],
        ':minlng' => $box['min_lng'], ':maxlng' => $box['max_lng'],
    ]);

    $n = 0;
    foreach ($stmt as $row) {
        $d = haversineMiles((float)$row['base_lat'], (float)$row['base_lng'], $lat, $lng);
        // Either we are inside their stated service radius, or they are inside
        // ours. Towers understate their radius constantly; the second test stops
        // an entire city reading as uncovered because everyone typed 15 miles.
        if ($d <= (float)$row['service_radius_miles'] || $d <= $radius) $n++;
    }
    return $n;
}

/**
 * Can we honestly take money for a job at this point?
 *
 * Returns ['covered' => bool, 'trucks' => int, 'zone' => array].
 * A zone marked not-live overrides the truck count: it is the manual brake for
 * "we have one truck there but I am not ready to sell that city yet".
 */
function coverageAt(?float $lat, ?float $lng, ?string $state = null): array {
    $zone   = resolveZone($lat, $lng, $state);
    $trucks = approvedTowersNear($lat, $lng);
    $min    = max(1, (int)setting('min_trucks_for_coverage', 1));

    return [
        'zone'    => $zone,
        'trucks'  => $trucks,
        'covered' => !empty($zone['is_live']) && $trucks >= $min,
    ];
}
