<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/escrow.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/realtime.php';

// ═══════════════════════════════════════════════════════════════════════════
//  HANDING A JOB BACK
//
//  A towing company that has accepted and can no longer go must be able to say
//  so. The alternative is not "the job gets done anyway" — it is a customer
//  watching a countdown for a truck that was never coming, which is the worst
//  failure this product has.
//
//  This is NOT a cancellation. Cancelling ends a job; releasing hands it back.
//  The distinction runs all the way down:
//
//    • the customer is charged nothing and their card hold is left alone
//    • the job returns to 'open' and every other company nearby is woken again
//    • their tracking link keeps working — same token, same page, new search
//
//  ── Everything the old company left behind has to go ────────────────────────
//
//  This is the part that is easy to get wrong and impossible to see. A job put
//  back on the board while still carrying truck_lat, awarded_eta_minutes and
//  track_start_meters is a job whose customer watches the PREVIOUS driver's
//  truck drive away, with an arrival bar measured from a distance that no
//  longer means anything, counting down an ETA nobody has promised. Every one
//  of those columns is written by the company that took the job, so every one
//  of them is cleared when it lets go.
// ═══════════════════════════════════════════════════════════════════════════

/** Statuses a company may hand back from. */
const RELEASABLE_STATUSES = ['awarded', 'en_route', 'on_scene'];

/**
 * Give an accepted job back to the board.
 *
 * Runs inside its own transaction and returns ['ok' => bool, ...]. The caller
 * is responsible for the push fan-out afterwards — that is deliberately
 * outside the transaction, because a slow round of notifications must not be
 * able to hold a row lock on a live job.
 *
 * @param string|null $reason  what the driver typed. Not shown to the customer:
 *                             "wrong address, my mistake" told to a stranded
 *                             motorist is an argument, not information.
 */
function releaseCallToBoard(int $callId, int $towerAccountId, ?int $userId,
                            ?string $reason): array {
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM calls WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $callId]);
        $call = $stmt->fetch();

        if (!$call)                                          throw new RuntimeException(t('err.job_not_found'));
        if ((int)$call['awarded_tower_account_id'] !== $towerAccountId) {
            throw new RuntimeException('This job is not assigned to you');
        }
        if (!in_array($call['status'], RELEASABLE_STATUSES, true)) {
            // in_progress means the vehicle is on the truck. There is no
            // handing that back — the next company would arrive to find
            // nothing there, and the customer's car is driving away.
            throw new RuntimeException($call['status'] === 'in_progress'
                ? 'The vehicle is already loaded. Finish the job or call support.'
                : t('err.job_closed'));
        }

        // How long they sat on it. A release forty seconds after accepting and
        // one twenty-five minutes in cost the customer very different amounts
        // of time, and only this number tells them apart later.
        $held = $pdo->prepare(
            "SELECT TIMESTAMPDIFF(MINUTE, :aw, NOW()) AS mins"
        );
        $held->execute([':aw' => $call['awarded_at']]);
        $minutesHeld = $call['awarded_at'] ? (int)($held->fetch()['mins'] ?? 0) : null;

        // Written before the reset, because the reset destroys both inputs.
        $pdo->prepare(
            "INSERT INTO call_releases
                (call_id, tower_account_id, released_by_user_id, reason, released_from, minutes_held)
             VALUES (:c, :t, :u, :r, :from, :held)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason),
                                     released_from = VALUES(released_from),
                                     minutes_held = VALUES(minutes_held)"
        )->execute([
            ':c' => $callId, ':t' => $towerAccountId, ':u' => $userId,
            ':r' => $reason !== null ? mb_substr($reason, 0, 300) : null,
            ':from' => $call['status'], ':held' => $minutesHeld,
        ]);

        // Back on the board, wiped clean of the company that let go.
        //
        // expires_at is extended rather than replaced: GREATEST keeps a
        // generous original expiry intact, and rescues the far more common
        // case of a job released with two minutes left, which would otherwise
        // reappear on the board just long enough to expire.
        $extra = max(5, (int)setting('release_extends_minutes', 25));
        $pdo->prepare(
            "UPDATE calls SET
                status = 'open',
                awarded_tower_account_id = NULL,
                awarded_at = NULL,
                awarded_eta_minutes = NULL,
                awarded_amount = NULL,
                en_route_at = NULL,
                on_scene_at = NULL,
                track_start_meters = NULL,
                truck_lat = NULL, truck_lng = NULL, truck_heading = NULL,
                truck_speed_mph = NULL, truck_updated_at = NULL,
                eta_live_minutes = NULL, eta_live_meters = NULL, eta_live_at = NULL,
                released_count = released_count + 1,
                released_at = NOW(),
                expires_at = GREATEST(expires_at, DATE_ADD(NOW(), INTERVAL :extra MINUTE)),
                -- So the reminder sweep treats this as a job nobody has been
                -- woken for, rather than one alerted about twenty minutes ago.
                last_alert_at = NULL
              WHERE id = :id"
        )->execute([':extra' => $extra, ':id' => $callId]);

        // The money stays exactly where it is — still held, still the
        // customer's, just no longer earmarked for anybody. Refunding here and
        // re-charging on the next accept would put a second authorisation on a
        // stranded person's card, and the first release would be the moment
        // their bank decided the pattern looked like fraud.
        escrowUnassignTower($callId);

        logCallEvent($callId, 'released',
            'Handed back to the board by the towing company'
            . ($minutesHeld !== null ? ' after ' . $minutesHeld . ' min' : '')
            . ($reason ? ' — ' . mb_substr($reason, 0, 200) : ''),
            $towerAccountId, $userId);

        $pdo->commit();
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => $e->getMessage(), 'code' => 409];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'Could not release the job: ' . $e->getMessage(), 'code' => 500];
    }

    // ─── Outside the transaction ────────────────────────────────────────────

    // The customer first. Their screen is the one with a person in front of it.
    if (!empty($call['tracking_token'])) {
        rtJobChanged($callId, $call['tracking_token'], $towerAccountId, 'released');
    }
    // And back onto every other operator's board, live.
    rtJobPosted($callId, $call['pickup_city'] ?? null, $call['pickup_state'] ?? null);

    if (($call['source'] ?? 'board') !== 'consumer') {
        notify((int)$call['provider_account_id'], 'call_released',
            $call['call_number'] . ' — back on the board',
            'The towing company handed the job back. It is being offered to other companies now.',
            $callId);
    }

    return [
        'ok' => true,
        'call' => $call,
        'minutes_held' => $minutesHeld ?? null,
        'release_count' => (int)$call['released_count'] + 1,
    ];
}

/**
 * Has this company already let go of this job?
 *
 * Checked on accept, not only hidden on the board. Hiding is not refusing —
 * the board endpoint takes a lat/lng and a radius, and nothing stopped a POST
 * straight at /calls/accept for a job that was never in the response.
 */
function towerHasReleased(int $callId, int $towerAccountId): bool {
    $stmt = getDB()->prepare(
        "SELECT 1 FROM call_releases WHERE call_id = :c AND tower_account_id = :t LIMIT 1"
    );
    $stmt->execute([':c' => $callId, ':t' => $towerAccountId]);
    return (bool)$stmt->fetchColumn();
}

/** Releases by this company in the last 30 days — the abuse signal. */
function towerReleaseCount(int $towerAccountId, int $days = 30): int {
    $stmt = getDB()->prepare(
        "SELECT COUNT(*) FROM call_releases
          WHERE tower_account_id = :t AND created_at > DATE_SUB(NOW(), INTERVAL :d DAY)"
    );
    $stmt->execute([':t' => $towerAccountId, ':d' => $days]);
    return (int)$stmt->fetchColumn();
}
