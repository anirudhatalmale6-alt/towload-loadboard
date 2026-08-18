<?php
require_once __DIR__ . '/helpers.php';
// towerIsCapable() — coverage must ask the same capability question the
// board and the push fan-out ask, from the one definition.
require_once __DIR__ . '/matching.php';

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
/**
 * Bump this to make the next resolveZone() re-read the table. A zone created
 * mid-request — which is exactly what approving a company now does — would
 * otherwise be invisible to the rest of that request, so the newly opened
 * market would look uncovered to the very call that opened it.
 */
function bustZoneCache(): void { $GLOBALS['TL_ZONE_EPOCH'] = ($GLOBALS['TL_ZONE_EPOCH'] ?? 0) + 1; }

function resolveZone(?float $lat, ?float $lng, ?string $state = null): array {
    static $zones = null;
    static $epoch = -1;
    $now = $GLOBALS['TL_ZONE_EPOCH'] ?? 0;
    if ($epoch !== $now) { $zones = null; $epoch = $now; }
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
 * How many approved, active towing companies can reach a point right now.
 *
 * Counts a company if EITHER its yard is in range OR one of its devices has
 * reported a recent position in range. A truck dropping a car sixty miles from
 * its own yard is real coverage for the street it is standing on, and before
 * this it counted for nothing.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * A UNION, NEVER A REPLACEMENT. This is the whole safety property.
 *
 * Coverage decides whether a stranded motorist is allowed to ask for a tow at
 * all. Had live positions REPLACED yards, coverage would flicker minute to
 * minute — "no trucks near you" at 3am because a phone was asleep, from a
 * company that would gladly have come. Because a fresh device can only ADD to
 * the count, a phone that goes quiet costs nobody anything: the yard is still
 * there underneath, exactly as before.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Freshness is tighter here than for alerting. An alert is an offer somebody
 * may ignore; coverage is the platform telling a customer help is available and
 * then taking their card details. A twenty-minute-old position is a truck that
 * may be thirty miles further down the interstate.
 *
 * Deliberately does NOT filter on capability at the company level for the area
 * question. A customer asking for a heavy wreck in a town with only light-duty
 * trucks still deserves a straight answer about the area, and gating coverage on
 * capability would hide a whole market behind one unusual request.
 */
function approvedTowersNear(?float $lat, ?float $lng, ?float $radius = null,
                             ?array $call = null): int {
    if ($lat === null || $lng === null) return 0;
    $radius = $radius ?? (float)setting('coverage_radius_miles', 35);
    $freshMin = max(1, (int)setting('coverage_location_max_age_minutes', 20));

    $box = boundingBox($lat, $lng, $radius);
    // tp.* because the capability gate needs the has_* columns, and it must ask
    // exactly the question the board asks — towerIsCapable() reads a whole
    // profile row. Selecting the three geometry columns and hand-checking one
    // flag here is how coverage and the board drift apart.
    //
    // The device position comes back as its own two columns rather than being
    // COALESCEd over the yard: both are needed below, because a company may
    // qualify on either and the count must not double up.
    $stmt = getDB()->prepare(
        "SELECT tp.*,
                (SELECT s.last_lat FROM push_subscriptions s
                  WHERE s.account_id = tp.account_id AND s.is_active = 1
                    AND s.use_device_location = 1
                    AND s.last_lat BETWEEN :dminlat AND :dmaxlat
                    AND s.last_lng BETWEEN :dminlng AND :dmaxlng
                    AND s.last_location_at > DATE_SUB(NOW(), INTERVAL :fresh MINUTE)
                  ORDER BY s.last_location_at DESC LIMIT 1) AS dev_lat,
                (SELECT s.last_lng FROM push_subscriptions s
                  WHERE s.account_id = tp.account_id AND s.is_active = 1
                    AND s.use_device_location = 1
                    AND s.last_lat BETWEEN :dminlat2 AND :dmaxlat2
                    AND s.last_lng BETWEEN :dminlng2 AND :dmaxlng2
                    AND s.last_location_at > DATE_SUB(NOW(), INTERVAL :fresh2 MINUTE)
                  ORDER BY s.last_location_at DESC LIMIT 1) AS dev_lng
           FROM accounts a
           JOIN tower_profiles tp ON tp.account_id = a.id
          WHERE a.account_type = 'tower'
            AND a.is_active = 1
            AND a.verification_status = 'approved'
            -- Off duty means off duty. A company that has switched itself to
            -- 'not taking jobs' is not coverage, and counting it is how a
            -- customer's card gets authorised at 3am for a job nobody has any
            -- intention of turning out for.
            AND tp.is_available = 1
            -- Near by yard, OR with a truck reported near right now.
            AND (
                  (tp.base_lat BETWEEN :minlat AND :maxlat
                   AND tp.base_lng BETWEEN :minlng AND :maxlng)
               OR EXISTS (SELECT 1 FROM push_subscriptions s2
                           WHERE s2.account_id = tp.account_id AND s2.is_active = 1
                             AND s2.use_device_location = 1
                             AND s2.last_lat BETWEEN :eminlat AND :emaxlat
                             AND s2.last_lng BETWEEN :eminlng AND :emaxlng
                             AND s2.last_location_at > DATE_SUB(NOW(), INTERVAL :fresh3 MINUTE))
                )"
    );
    $bind = [
        ':minlat' => $box['min_lat'], ':maxlat' => $box['max_lat'],
        ':minlng' => $box['min_lng'], ':maxlng' => $box['max_lng'],
    ];
    // The same box, under the names each subquery uses. PDO with
    // ATTR_EMULATE_PREPARES off will not let one placeholder be reused.
    foreach ([['dminlat','dmaxlat','dminlng','dmaxlng'],
              ['dminlat2','dmaxlat2','dminlng2','dmaxlng2'],
              ['eminlat','emaxlat','eminlng','emaxlng']] as $names) {
        $bind[':' . $names[0]] = $box['min_lat'];
        $bind[':' . $names[1]] = $box['max_lat'];
        $bind[':' . $names[2]] = $box['min_lng'];
        $bind[':' . $names[3]] = $box['max_lng'];
    }
    $bind[':fresh'] = $freshMin;
    $bind[':fresh2'] = $freshMin;
    $bind[':fresh3'] = $freshMin;
    $stmt->execute($bind);

    $n = 0;
    foreach ($stmt as $row) {
        // The nearer of the two, because either one being in range is what
        // makes this company coverage. Measuring only the yard would throw away
        // the very truck that qualified the row.
        $d = null;
        if ($row['base_lat'] !== null && $row['base_lng'] !== null) {
            $d = haversineMiles((float)$row['base_lat'], (float)$row['base_lng'], $lat, $lng);
        }
        if ($row['dev_lat'] !== null && $row['dev_lng'] !== null) {
            $dDev = haversineMiles((float)$row['dev_lat'], (float)$row['dev_lng'], $lat, $lng);
            $d = $d === null ? $dDev : min($d, $dDev);
        }
        if ($d === null) continue;

        // Either we are inside their stated service radius, or they are inside
        // ours. Towers understate their radius constantly; the second test stops
        // an entire city reading as uncovered because everyone typed 15 miles.
        if ($d > (float)$row['service_radius_miles'] && $d > $radius) continue;
        // Can this company actually run THIS job? A wheel-lift outfit is not
        // coverage for a semi, however close it is parked.
        if ($call !== null && !towerIsCapable($row, $call)) continue;
        $n++;
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
function coverageAt(?float $lat, ?float $lng, ?string $state = null,
                    ?array $call = null): array {
    // Work the state out from the trucks when nobody told us one.
    //
    // This was a silent no-coverage bug, and a bad one. resolveZone() only
    // matches a statewide zone if it is HANDED a state, and falls back to the
    // national zone, which is deliberately not live. A customer who tapped
    // "use my location" sends coordinates and nothing else — the browser gets
    // no place name from GPS — so pickup_state was null, the zone came back
    // national, and coverageAt said "not covered" while cheerfully reporting a
    // truck sitting three miles away.
    //
    // The answer is already in the data: if an approved company is in range of
    // this point, the point is in that company's state. No geocoding, no extra
    // API, and it answers the exact question being asked.
    if ($state === null || $state === '') {
        $state = stateFromNearestTower($lat, $lng);
    }

    $zone = resolveZone($lat, $lng, $state);
    $min  = max(1, (int)setting('min_trucks_for_coverage', 1));

    // Trucks that can run THIS job, on duty right now. When no job shape is
    // handed in (the admin coverage map, a bare probe) this is every approved
    // truck on duty, exactly as before.
    $trucks = approvedTowersNear($lat, $lng, null, $call);

    // Two different noes, and the customer deserves to be told which.
    //
    // "We are not in your area yet" is a statement about the business. Saying
    // it because every local company happened to flip its duty switch off for
    // the night is simply false — and it is the worst kind of false, because a
    // customer who reads it once has no reason ever to come back, and it is
    // said to somebody standing next to a broken car in a town we DO cover.
    //
    // So count the companies based here at all, separately from the ones on
    // duty right now.
    // Capable companies BASED here, on duty or not. Separating this from the
    // line above is what distinguishes "the right truck exists here but is off
    // duty" from "no truck of this kind operates here at all".
    $capableInArea = $trucks > 0 ? $trucks : towersInArea($lat, $lng, null, $call);

    // Any approved company here at all, whatever it can tow. Only needed to
    // answer the third question, so only asked when the first two came back
    // empty.
    $inArea = $capableInArea > 0 ? $capableInArea : towersInArea($lat, $lng);

    $covered = !empty($zone['is_live']) && $trucks >= $min;
    $reason  = null;
    if (!$covered) {
        // THREE different noes now, and they are not interchangeable. A semi
        // driver told "no trucks are free right now, try again shortly" will
        // sit on the hard shoulder retrying for something that is never going
        // to come, because nobody within range owns a heavy wrecker.
        //
        // A zone that is not live is a decision Ricardo has made about that
        // market, so it counts as no coverage regardless of who is parked there.
        if (empty($zone['is_live'])) {
            $reason = 'no_coverage';
        } elseif ($capableInArea > 0) {
            $reason = 'none_available';  // right kind of truck here, none free
        } elseif ($inArea > 0) {
            $reason = 'no_capable';      // we cover here, but not this vehicle
        } else {
            $reason = 'no_coverage';     // we genuinely are not here
        }
    }

    return [
        'zone'            => $zone,
        'trucks'          => $trucks,
        'trucks_in_area'  => $capableInArea,
        'any_trucks_here' => $inArea,
        'covered'         => $covered,
        'reason'          => $reason,
    ];
}

/**
 * Companies based within range, ON DUTY OR NOT.
 *
 * Same filters as approvedTowersNear minus the duty switch: this answers "do we
 * operate here", where the other answers "can somebody come right now".
 */
function towersInArea(?float $lat, ?float $lng, ?float $radius = null,
                      ?array $call = null): int {
    if ($lat === null || $lng === null) return 0;
    $radius = $radius ?? (float)setting('coverage_radius_miles', 35);

    $box = boundingBox($lat, $lng, $radius);
    $stmt = getDB()->prepare(
        "SELECT tp.*
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
        if ($d > (float)$row['service_radius_miles'] && $d > $radius) continue;
        if ($call !== null && !towerIsCapable($row, $call)) continue;
        $n++;
    }
    return $n;
}

/**
 * The state of the closest approved towing company in range.
 *
 * Deliberately does NOT filter on the duty switch, and that is a correction:
 * it used to, copied from approvedTowersNear for consistency. But whether a
 * company is taking jobs tonight has nothing whatever to do with which state a
 * point is in. With the only local company off duty, the state came back null,
 * the zone fell through to the not-live national one, and the customer was
 * told "we are not in your area yet" about a town we plainly cover.
 *
 * Consistency with approvedTowersNear was the wrong goal here. They answer
 * different questions: that one asks "can somebody come now", this one asks
 * "where on the map is this".
 */
function stateFromNearestTower(?float $lat, ?float $lng, ?float $radius = null): ?string {
    if ($lat === null || $lng === null) return null;
    $radius = $radius ?? (float)setting('coverage_radius_miles', 35);

    $box = boundingBox($lat, $lng, $radius);
    $stmt = getDB()->prepare(
        "SELECT a.state, tp.base_lat, tp.base_lng, tp.service_radius_miles
           FROM accounts a
           JOIN tower_profiles tp ON tp.account_id = a.id
          WHERE a.account_type = 'tower'
            AND a.is_active = 1
            AND a.verification_status = 'approved'
            AND a.state IS NOT NULL AND a.state <> ''
            AND tp.base_lat BETWEEN :minlat AND :maxlat
            AND tp.base_lng BETWEEN :minlng AND :maxlng"
    );
    $stmt->execute([
        ':minlat' => $box['min_lat'], ':maxlat' => $box['max_lat'],
        ':minlng' => $box['min_lng'], ':maxlng' => $box['max_lng'],
    ]);

    $bestState = null;
    $bestDist  = null;
    foreach ($stmt as $row) {
        $d = haversineMiles((float)$row['base_lat'], (float)$row['base_lng'], $lat, $lng);
        if ($d > (float)$row['service_radius_miles'] && $d > $radius) continue;
        if ($bestDist === null || $d < $bestDist) {
            $bestDist  = $d;
            $bestState = strtoupper(substr((string)$row['state'], 0, 2));
        }
    }
    return $bestState;
}
