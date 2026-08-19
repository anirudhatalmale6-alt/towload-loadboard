<?php
require_once __DIR__ . '/helpers.php';

// ═══════════════════════════════════════════════════════════════════════════
//  THE ETA IS A PROMISE, AND THIS IS WHERE IT IS KEPT OR BROKEN
//
//  awarded_eta_minutes is the number a driver typed to win the job. Until now
//  it was decoration: it counted down on the customer's screen and then went
//  negative, and nothing anywhere cared. A promise with nothing enforcing it
//  is a marketing line, and the companies that quote 10 minutes to beat the
//  ones quoting 30 are rewarded for it.
//
//  So it now has one consequence. Past the deadline the customer may walk away
//  free — no call-out fee, no argument. That single rule is what makes an
//  honest 45 worth more to a company than a dishonest 15.
//
//  ── The clock ──────────────────────────────────────────────────────────────
//  Every comparison here happens INSIDE MySQL, or between two values that both
//  came out of MySQL. PHP's time() and the database's NOW() are two clocks in
//  two timezones unless somebody has made them agree, and a deadline computed
//  across them is wrong by the offset — which here means charging a customer
//  who was entitled to walk, or waiving a fee a company had earned.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * What was promised on this job, and whether it was kept.
 *
 * @return array{promised:?int, deadline:?string, seconds_remaining:?int,
 *               minutes_remaining:?int, passed:bool, missed:bool}
 *
 *   passed  the deadline is behind us
 *   missed  the deadline is behind us AND the truck was not on scene by then.
 *           Deliberately not the same thing: a driver who promised 20 and
 *           arrived in 25 has missed it, and stays missed even after he
 *           arrives — the customer was entitled to leave at minute 21 and
 *           that entitlement cannot be revoked by the truck turning up
 *           afterwards. Equally, a driver who arrived at minute 19 has kept
 *           his promise, and it stays kept for the rest of the job.
 */
function etaPromise(array $call): array {
    $out = ['promised' => null, 'deadline' => null, 'seconds_remaining' => null,
            'minutes_remaining' => null, 'passed' => false, 'missed' => false];

    if (empty($call['awarded_at']) || ($call['awarded_eta_minutes'] ?? null) === null) {
        return $out;
    }
    $eta = (int)$call['awarded_eta_minutes'];
    $out['promised'] = $eta;

    // Two named placeholders for the same value: with emulated prepares off,
    // PDO binds each name once and reusing one is an error, not a convenience.
    $q = getDB()->prepare(
        "SELECT DATE_ADD(:aw, INTERVAL :eta MINUTE) AS deadline,
                TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(:aw2, INTERVAL :eta2 MINUTE)) AS secs"
    );
    $q->execute([':aw' => $call['awarded_at'], ':eta' => $eta,
                 ':aw2' => $call['awarded_at'], ':eta2' => $eta]);
    $row = $q->fetch();
    if ($row === false) return $out;

    $secs = (int)$row['secs'];
    $out['deadline']          = $row['deadline'];
    $out['seconds_remaining'] = $secs;
    // Rounded up, so thirty seconds left reads as "1 minute" rather than "0".
    $out['minutes_remaining'] = (int)ceil($secs / 60);
    $out['passed']            = $secs < 0;

    // Both sides of this comparison came out of MySQL in the same timezone, so
    // it is a string comparison of two 'Y-m-d H:i:s' values and never crosses
    // a clock boundary. on_scene_at is the only honest arrival marker: status
    // moves forward and can be moved forward again, a timestamp is stamped once.
    $arrived = $call['on_scene_at'] ?? null;
    $out['missed'] = $out['passed'] && ($arrived === null || $arrived > $row['deadline']);

    return $out;
}

/**
 * What cancelling costs the customer right now, and why.
 *
 * The single source of truth for the fee. The customer's screen asks it so the
 * button can say what the button will do, and the cancel endpoint asks it again
 * before charging anything — a page can be edited, and a fee that is decided in
 * a browser is a fee that is decided by whoever opens the console.
 *
 * @return array{amount:float, free_reason:?string}
 */
function customerCancelFee(array $call): array {
    // Nobody has set off. Nothing has been spent, so nothing is owed — this is
    // the same rule the platform has always had.
    $dispatched = in_array($call['status'], ['en_route', 'on_scene', 'in_progress'], true)
                  && !empty($call['awarded_tower_account_id']);
    if (!$dispatched) return ['amount' => 0.0, 'free_reason' => 'not_dispatched'];

    // The promise was broken. The company chose this number itself, nobody
    // else was allowed to take the job once it did, and the customer has been
    // sitting on a roadside past the time they were given.
    if (etaPromise($call)['missed']) {
        return ['amount' => 0.0, 'free_reason' => 'eta_missed'];
    }

    return ['amount' => (float)$call['goa_amount'], 'free_reason' => null];
}
