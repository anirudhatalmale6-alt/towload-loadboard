<?php
require_once __DIR__ . '/helpers.php';

// ═══════════════════════════════════════════════════════════════════════════
//  LIVE TRUCK TRACKING
//
//  What a stranded customer actually wants is not a map. It is to stop
//  wondering. The map is just the most legible way to say "someone is really
//  coming, and here is how far away they are right now".
//
//  Which means the number has to be true. A frozen marker that looks live is
//  worse than an honest "updating" — the first time a customer notices the
//  truck hasn't moved in four minutes while the app insists it is 6 minutes
//  away, they stop believing the screen and start phoning.
//
//  ── The rule that governs this whole file ────────────────────────────────
//  A driver's position is SHOWN to a customer ONLY while he is on a live job,
//  and only to the customer of that job. Not before accepting, not after
//  completing, never in general.
//
//  That rule used to be enforced by there being nowhere else to look: the only
//  driver position in the schema was calls.truck_lat, which exists per job.
//  That is no longer true — push_subscriptions.last_lat now holds a coarse
//  position so jobs can be matched against the truck rather than the yard.
//
//  So the rule is now enforced by this file rather than by the schema, and it
//  is enforced in exactly one place: customerTrackingView(). Whichever source
//  a coordinate comes from, it is released only for a TRACKABLE status, only
//  for the account that accepted that job, and only with its true age
//  attached. Do not read either position anywhere else on a customer path.
// ═══════════════════════════════════════════════════════════════════════════

// Statuses during which a truck is genuinely moving toward or working a job.
const TRACKABLE_STATUSES = ['awarded', 'en_route', 'on_scene', 'in_progress'];

function trackingEnabled(): bool {
    return (string)setting('tracking_enabled', '1') === '1';
}

/**
 * Estimated road distance and driving time.
 *
 * Straight-line distance times a road factor. This is a stand-in and it is
 * signposted as one: it is decent in a grid city and plainly wrong anywhere a
 * bay, river or rail line forces a detour — Miami Beach to the mainland being
 * the obvious local case, where the crow flies over Biscayne Bay and the truck
 * does not.
 *
 * Isolated here on purpose. Swapping in Google Directions or Mapbox means
 * replacing this one function; nothing else in the codebase computes an ETA.
 */
function estimateEta(float $fromLat, float $fromLng, float $toLat, float $toLng,
                     ?float $currentSpeedMph = null): array {
    $straight = haversineMiles($fromLat, $fromLng, $toLat, $toLng);
    $roadMiles = $straight * (float)setting('tracking_road_factor', 1.35);

    // A truck's instantaneous speed is a bad predictor on its own — it is 0 at
    // every red light. Blend it with the assumed average so the ETA does not
    // leap to infinity every time he stops, and floor it so a crawling truck
    // still produces a finite number.
    $assumed = (float)setting('tracking_avg_speed_mph', 28);
    $speed = $assumed;
    if ($currentSpeedMph !== null && $currentSpeedMph > 3) {
        $speed = ($currentSpeedMph + $assumed) / 2;
    }
    $speed = max(8.0, min($speed, 70.0));

    $minutes = (int)ceil(($roadMiles / $speed) * 60);

    return [
        // Never promise under a minute. "Arriving" is the honest word for the
        // last stretch, and the app says that rather than counting to zero.
        'minutes'        => max(1, $minutes),
        'road_miles'     => round($roadMiles, 2),
        'straight_miles' => round($straight, 2),
        'meters'         => (int)round($roadMiles * 1609.34),
    ];
}

/**
 * Record one position from a driver's phone.
 *
 * Returns ['ok' => bool, 'error' => ?string, 'eta' => ?array, 'moved' => bool].
 * Never throws — a phone in a tunnel sending nonsense must not 500.
 */
function recordTruckLocation(array $call, int $accountId, ?int $userId, array $in): array {
    if (!trackingEnabled()) {
        return ['ok' => false, 'error' => t('err.tracking_off')];
    }

    // The job has to be live, and it has to be HIS job. Both, every ping —
    // not checked once at the start of a shift.
    if ((int)$call['awarded_tower_account_id'] !== $accountId) {
        return ['ok' => false, 'error' => t('err.not_your_job')];
    }
    if (!in_array($call['status'], TRACKABLE_STATUSES, true)) {
        return ['ok' => false, 'error' => t('err.job_not_live'), 'stop' => true];
    }

    $lat = isset($in['lat']) ? (float)$in['lat'] : null;
    $lng = isset($in['lng']) ? (float)$in['lng'] : null;
    if ($lat === null || $lng === null
        || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180
        || ($lat == 0.0 && $lng == 0.0)) {
        return ['ok' => false, 'error' => t('err.bad_location')];
    }

    $accuracy = isset($in['accuracy_m']) ? (int)round((float)$in['accuracy_m']) : null;
    if ($accuracy !== null && ($accuracy < 0 || $accuracy > 65535)) $accuracy = null;

    $heading = isset($in['heading']) && $in['heading'] !== null ? (int)round((float)$in['heading']) : null;
    if ($heading !== null && ($heading < 0 || $heading > 359)) $heading = null;

    $speed = isset($in['speed_mph']) && $in['speed_mph'] !== null ? (float)$in['speed_mph'] : null;
    if ($speed !== null && ($speed < 0 || $speed > 200)) $speed = null;

    // The device's own clock, clamped. A phone with a wrong date would
    // otherwise write breadcrumbs in 1970 or next year, and both break the
    // ordering that everything downstream depends on.
    $recordedAt = time();
    if (!empty($in['recorded_at'])) {
        $ts = is_numeric($in['recorded_at'])
                ? (int)$in['recorded_at'] : strtotime((string)$in['recorded_at']);
        if ($ts && $ts > time() - 3600 && $ts < time() + 120) $recordedAt = $ts;
    }

    $pdo = getDB();

    // ── Should this position move the marker? ───────────────────────────────
    // Two reasons it might not, and both still get stored: the trail should
    // record what the phone actually said, even where the map should not act
    // on it.
    $moveMarker = true;
    $reject = null;

    $maxAccuracy = (int)setting('tracking_max_accuracy_m', 250);
    if ($accuracy !== null && $accuracy > $maxAccuracy) {
        $moveMarker = false;
        $reject = 'accuracy';
    }

    // Out of order. A phone coming out of a tunnel flushes its buffer, and the
    // network does not promise to deliver that burst in the order it was
    // recorded. An older fix must never overwrite a newer one — the marker
    // would jump backwards down the road it already drove, which reads to the
    // customer as the driver turning around and leaving.
    if ($moveMarker && $call['truck_updated_at']
        && $recordedAt < strtotime($call['truck_updated_at'])) {
        $moveMarker = false;
        $reject = 'out_of_order';
    }

    if ($moveMarker && $call['truck_lat'] !== null && $call['truck_updated_at']) {
        $elapsed = max(1, $recordedAt - strtotime($call['truck_updated_at']));
        $jump = haversineMiles($lat, $lng, (float)$call['truck_lat'], (float)$call['truck_lng']);
        $implied = $jump / ($elapsed / 3600);
        if ($implied > (float)setting('tracking_max_speed_mph', 120)) {
            // A GPS glitch that throws the marker ten miles and back reads to a
            // customer as the driver going the wrong way. Keep the crumb, keep
            // the marker still.
            $moveMarker = false;
            $reject = 'implausible_jump';
        }
    }

    $pdo->prepare(
        "INSERT INTO call_locations
            (call_id, account_id, user_id, lat, lng, accuracy_m, heading, speed_mph, recorded_at)
         VALUES (:c, :a, :u, :lat, :lng, :acc, :hd, :sp, FROM_UNIXTIME(:rec))"
    )->execute([
        ':c' => $call['id'], ':a' => $accountId, ':u' => $userId,
        ':lat' => $lat, ':lng' => $lng, ':acc' => $accuracy,
        ':hd' => $heading, ':sp' => $speed !== null ? number_format($speed, 1, '.', '') : null,
        ':rec' => $recordedAt,
    ]);

    // ── The same fix, for matching the NEXT job ─────────────────────────────
    //
    // A driver dropping a car sixty miles from his yard is exactly the person
    // who should be offered the job on the next street, and while he is on a
    // job this endpoint already knows precisely where he is. Reusing it costs
    // nothing: no extra permission, no extra battery, no extra request.
    //
    // Only the devices belonging to the driver actually doing the job, and only
    // where the operator has left the switch on. Written after the crumb above
    // so a failure here can never lose the trail the customer is watching.
    if ($moveMarker && $userId) {
        try {
            $pdo->prepare(
                "UPDATE push_subscriptions
                    SET last_lat = :lat, last_lng = :lng, last_location_at = NOW(),
                        location_accuracy_m = :acc
                  WHERE account_id = :a AND user_id = :u
                    AND is_active = 1 AND use_device_location = 1"
            )->execute([
                ':lat' => $lat, ':lng' => $lng, ':acc' => $accuracy,
                ':a' => $accountId, ':u' => $userId,
            ]);
        } catch (Throwable $e) {
            error_log('[tracking] could not carry position to push device: ' . $e->getMessage());
        }
    }

    if (!$moveMarker) {
        return ['ok' => true, 'moved' => false, 'ignored' => $reject, 'eta' => null];
    }

    // ── Where is he heading? ────────────────────────────────────────────────
    // Before pickup, toward the customer. On scene or already loaded, toward
    // the drop-off if there is one. Computing the ETA to the pickup while the
    // truck is driving away from it with the car on the back is the kind of
    // detail that makes a product feel broken.
    $target = ['lat' => (float)$call['pickup_lat'], 'lng' => (float)$call['pickup_lng']];
    if ($call['status'] === 'in_progress'
        && $call['dropoff_lat'] !== null && $call['dropoff_lng'] !== null) {
        $target = ['lat' => (float)$call['dropoff_lat'], 'lng' => (float)$call['dropoff_lng']];
    }

    $eta = null;
    if ($target['lat'] || $target['lng']) {
        $eta = estimateEta($lat, $lng, $target['lat'], $target['lng'], $speed);
    }

    $pdo->prepare(
        "UPDATE calls
            SET truck_lat = :lat, truck_lng = :lng, truck_heading = :hd,
                truck_speed_mph = :sp, truck_updated_at = FROM_UNIXTIME(:rec),
                eta_live_minutes = :eta, eta_live_meters = :m, eta_live_at = NOW()
          WHERE id = :id"
    )->execute([
        ':lat' => $lat, ':lng' => $lng, ':hd' => $heading,
        ':sp' => $speed !== null ? number_format($speed, 1, '.', '') : null,
        ':rec' => $recordedAt,
        ':eta' => $eta ? $eta['minutes'] : null,
        ':m'   => $eta ? $eta['meters'] : null,
        ':id'  => $call['id'],
    ]);

    return ['ok' => true, 'moved' => true, 'eta' => $eta];
}

/**
 * What the customer's screen is allowed to see.
 *
 * Returns null when there is nothing honest to show — no truck assigned yet,
 * job already closed, tracking switched off, or the last fix is old enough
 * that drawing it would be a lie.
 */
function customerTrackingView(array $call): ?array {
    if (!trackingEnabled())                                     return null;
    if (!in_array($call['status'], TRACKABLE_STATUSES, true))   return null;

    // ── Second best, when the driver's app is not sending per-job pings ─────
    //
    // The per-second trail comes from the app calling /tracking/ping. A driver
    // on the web dashboard, or on an app build older than that feature, sends
    // nothing here at all — and the customer got a map with no truck on it,
    // which reads as "nobody is coming".
    //
    // His phone is nevertheless reporting a coarse position for job matching,
    // roughly every couple of minutes. It is the same truck. Showing it, with
    // its real age attached and clearly labelled approximate, is far better
    // than an empty map — and the moment a real ping arrives, the branch above
    // wins and this is never consulted again.
    //
    // Deliberately NOT merged into one "best position": the two differ by an
    // order of magnitude in both accuracy and freshness, and averaging them
    // would produce a number that is true of neither. One or the other, whole.
    //
    // WHICH one is decided by age, not by rank. Preferring the precise source
    // whenever it exists at all is the trap: a driver whose app is killed, or
    // who only granted When In Use and has switched to Maps, stops pinging
    // while his phone carries on reporting the coarse position perfectly well.
    // Ranking would pin the customer's map to a precise fix from nine minutes
    // ago and never look at the fresh one sitting beside it.
    $coarse = coarseTrackingView($call);

    if ($call['truck_lat'] === null || $call['truck_updated_at'] === null) {
        return $coarse;
    }

    $age = time() - strtotime($call['truck_updated_at']);
    $stale = $age > (int)setting('tracking_stale_seconds', 90);

    // Fall back only once the precise channel has actually GONE QUIET, never
    // on a plain "which timestamp is larger".
    //
    // A raw age comparison flaps, and it flaps on one GPS reading: recording a
    // ping also refreshes the coarse row (see recordTruckLocation, which reuses
    // the fix for matching the next job), so the coarse copy is a second newer
    // than the precise one it was cloned from and wins. The customer's map then
    // flickers "Approximate position" on and off every few seconds while the
    // truck is being tracked perfectly.
    //
    // Requiring the precise fix to be stale first makes the handover need a
    // real 90-second silence, which is a driver who has genuinely stopped
    // pinging rather than arithmetic noise.
    if ($stale && $coarse !== null && $coarse['age_seconds'] < $age) {
        return $coarse;
    }

    return [
        'source'      => 'live',
        'approximate' => false,
        'lat'         => (float)$call['truck_lat'],
        'lng'         => (float)$call['truck_lng'],
        'heading'     => $call['truck_heading'] !== null ? (int)$call['truck_heading'] : null,
        'speed_mph'   => $call['truck_speed_mph'] !== null ? (float)$call['truck_speed_mph'] : null,
        'age_seconds' => $age,
        // The screen shows "updating" rather than a confident marker. Saying so
        // costs nothing; being caught showing a frozen truck as live costs the
        // customer's trust in every number on the page.
        'stale'       => $stale,
        'eta_minutes' => $stale ? null : ($call['eta_live_minutes'] !== null ? (int)$call['eta_live_minutes'] : null),
        'eta_meters'  => $stale ? null : ($call['eta_live_meters'] !== null ? (int)$call['eta_live_meters'] : null),
        // Which way the marker should be heading, so the map can label it.
        'target'      => ($call['status'] === 'in_progress'
                          && $call['dropoff_lat'] !== null) ? 'dropoff' : 'pickup',
    ];
}

/**
 * How far along the driver is between where he accepted from and the customer,
 * 0-100, or null when there is nothing honest to draw.
 *
 * Straight-line at BOTH ends on purpose. The baseline written at accept is a
 * straight line, and eta_meters is that same distance multiplied by a road
 * factor — mixing them starts the bar at minus thirty-five percent, which
 * clamps to zero and then sits there while the truck drives the first third of
 * the way.
 *
 * Targets the PICKUP, never the drop-off. This bar answers one question — "how
 * long until somebody is standing here with me" — and it is finished the moment
 * he arrives. What happens to the car afterwards is a different screen.
 */
function arrivalProgress(array $call, ?float $lat, ?float $lng): ?int {
    // Arrived is arrived. Leaving the bar at 96% while the driver is out of the
    // cab in front of them is the sort of detail that makes a screen feel like
    // it is not really connected to anything.
    if (in_array($call['status'], ['on_scene', 'in_progress', 'completed'], true)) return 100;

    $start = isset($call['track_start_meters']) ? (int)$call['track_start_meters'] : 0;
    // No baseline: every job accepted before this shipped, and any accept where
    // we did not know where the truck was. The screen falls back to the ETA
    // line, which is why it must not be built to assume a bar.
    if ($start <= 0 || $lat === null || $lng === null) return null;
    if ($call['pickup_lat'] === null || $call['pickup_lng'] === null) return null;

    $now = haversineMiles($lat, $lng, (float)$call['pickup_lat'], (float)$call['pickup_lng']) * 1609.344;

    $pct = (int)round((1 - ($now / $start)) * 100);
    // A driver who starts by going the wrong way, or who was closer at accept
    // than the yard-based baseline suggested, must not push the bar backwards
    // off either end.
    return max(0, min(100, $pct));
}

/**
 * The coarse position of the phone belonging to the company that took this job.
 *
 * Only ever reached from customerTrackingView(), which has already established
 * that this job is live and that this is its customer. Read the rule at the top
 * of this file before calling it from anywhere else — the answer is don't.
 */
function coarseTrackingView(array $call): ?array {
    if ((string)setting('tracking_coarse_fallback', '1') !== '1') return null;
    $tower = (int)($call['awarded_tower_account_id'] ?? 0);
    if (!$tower) return null;

    // Significant-change fires roughly every 500m or 5 minutes, and the app
    // throttles to one send every 2 minutes on top of that. Judging those
    // against the 90-second threshold meant for per-second pings would mark
    // every single one stale, so the marker would never once look live.
    $maxAge = max(60, (int)setting('tracking_coarse_max_age_seconds', 900));

    $stmt = getDB()->prepare(
        "SELECT last_lat, last_lng, location_accuracy_m,
                TIMESTAMPDIFF(SECOND, last_location_at, NOW()) AS age_seconds
           FROM push_subscriptions
          WHERE account_id = :a
            AND is_active = 1
            -- The driver's own switch. Turning device location off must stop
            -- this too, or the switch is a lie.
            AND use_device_location = 1
            AND last_lat IS NOT NULL AND last_lng IS NOT NULL
            AND last_location_at > DATE_SUB(NOW(), INTERVAL :age SECOND)
          ORDER BY last_location_at DESC
          LIMIT 1"
    );
    $stmt->execute([':a' => $tower, ':age' => $maxAge]);
    $row = $stmt->fetch();
    if (!$row) return null;

    $lat = (float)$row['last_lat'];
    $lng = (float)$row['last_lng'];
    $age = (int)$row['age_seconds'];

    $target = ['lat' => (float)$call['pickup_lat'], 'lng' => (float)$call['pickup_lng']];
    if ($call['status'] === 'in_progress'
        && $call['dropoff_lat'] !== null && $call['dropoff_lng'] !== null) {
        $target = ['lat' => (float)$call['dropoff_lat'], 'lng' => (float)$call['dropoff_lng']];
    }
    // No instantaneous speed to blend — a coarse fix carries none — so this is
    // the assumed-average ETA and nothing better. Marked approximate for that
    // reason as much as for the position.
    $eta = ($target['lat'] || $target['lng'])
             ? estimateEta($lat, $lng, $target['lat'], $target['lng'], null)
             : null;

    $stale = $age > (int)setting('tracking_coarse_stale_seconds', 420);

    return [
        'source'      => 'device',
        // The page says "approximate" on the strength of this. A customer told
        // the position is rough will forgive it being rough; one shown a
        // confident marker that is 400m out will not.
        'approximate' => true,
        'lat'         => $lat,
        'lng'         => $lng,
        'heading'     => null,
        'speed_mph'   => null,
        'accuracy_m'  => $row['location_accuracy_m'] !== null ? (int)$row['location_accuracy_m'] : null,
        'age_seconds' => $age,
        'stale'       => $stale,
        'eta_minutes' => $stale ? null : ($eta['minutes'] ?? null),
        'eta_meters'  => $stale ? null : ($eta['meters'] ?? null),
        'target'      => ($call['status'] === 'in_progress'
                          && $call['dropoff_lat'] !== null) ? 'dropoff' : 'pickup',
    ];
}

/**
 * The trail for one job. Operator and admin only — this is the dispute record,
 * not something the customer needs.
 */
function callTrail(int $callId, int $limit = 500): array {
    $stmt = getDB()->prepare(
        "SELECT lat, lng, heading, speed_mph, accuracy_m, recorded_at
           FROM call_locations
          WHERE call_id = :c
          ORDER BY recorded_at ASC
          LIMIT " . max(1, min(5000, $limit))
    );
    $stmt->execute([':c' => $callId]);

    $out = [];
    foreach ($stmt as $r) {
        $out[] = [
            'lat' => (float)$r['lat'], 'lng' => (float)$r['lng'],
            'heading' => $r['heading'] !== null ? (int)$r['heading'] : null,
            'speed_mph' => $r['speed_mph'] !== null ? (float)$r['speed_mph'] : null,
            'accuracy_m' => $r['accuracy_m'] !== null ? (int)$r['accuracy_m'] : null,
            'at' => $r['recorded_at'],
        ];
    }
    return $out;
}

/**
 * Delete breadcrumbs past the retention window.
 *
 * This is the difference between keeping evidence for a disputed job and
 * running a location database on people who never agreed to one. Called
 * opportunistically rather than on a cron, because this host has no reliable
 * scheduler — bounded per call so it can never become the slow part of a
 * request.
 */
function purgeOldLocations(int $limit = 2000): int {
    $days = max(1, (int)setting('tracking_retain_days', 30));
    $stmt = getDB()->prepare(
        "DELETE FROM call_locations
          WHERE created_at < DATE_SUB(NOW(), INTERVAL :d DAY)
          LIMIT " . max(1, min(10000, $limit))
    );
    $stmt->execute([':d' => $days]);
    return $stmt->rowCount();
}
