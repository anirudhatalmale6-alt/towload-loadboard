<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/escrow.php';
require_once __DIR__ . '/realtime.php';
require_once __DIR__ . '/stripe_connect.php';
// pushNewJob(), for the reminder pass. Both callers of the sweep happened to
// have pulled this in already, so leaving it out worked right up until
// something else called runSweep() — and then it is a fatal, inside a sweep,
// in the middle of expiring calls and releasing card authorisations.
require_once __DIR__ . '/webpush.php';

// ═══════════════════════════════════════════════════════════════════════════
//  THE SWEEP
//
//  Two jobs that must happen on a clock, on a host that has no clock:
//
//    1. Open calls nobody accepted before they expired. The customer's card
//       hold has to be released and the provider's escrow returned. Left
//       undone, a real customer's money sits blocked on their card for the
//       seven days a Stripe authorisation lives — for a truck that never came.
//
//    2. Requests abandoned at the card screen. Nobody ever saw them, but the
//       PaymentIntent is still open at Stripe.
//
//  DreamHost gives this account SFTP only — no shell, no crontab. Rather than
//  depend on something nobody can see has stopped, the sweep rides along on
//  traffic that was going to happen anyway: a tower opening the board, a
//  customer polling their tracking screen. runSweepIfDue() is cheap to call
//  and does nothing at all unless the interval has elapsed.
//
//  The endpoint at /api/calls/expire-sweep still exists, so a real cron can be
//  pointed at it later without changing any of this.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Run the sweep at most once every N minutes, whoever happens to ask.
 *
 * The claim is a conditional UPDATE, not a read-then-write: two requests
 * arriving in the same second must not both decide they are the one to run it,
 * or a call gets expired twice and the second pass throws inside a transaction
 * that has already refunded. Whoever's UPDATE reports a changed row owns this
 * round; everybody else returns immediately.
 */
function runSweepIfDue(): void {
    static $triedThisRequest = false;
    if ($triedThisRequest) return;      // once per request, whatever else calls it
    $triedThisRequest = true;

    $everyMin = max(1, (int)setting('sweep_interval_minutes', 5));

    try {
        $pdo = getDB();
        // Seed the row on first ever run. The IGNORE keeps this a no-op forever
        // after, and dates it in the past so the first request sweeps.
        $pdo->prepare(
            "INSERT IGNORE INTO platform_settings (setting_key, setting_value)
             VALUES ('sweep_last_run_at', '2000-01-01 00:00:00')"
        )->execute();

        $claim = $pdo->prepare(
            "UPDATE platform_settings
                SET setting_value = NOW()
              WHERE setting_key = 'sweep_last_run_at'
                AND setting_value < DATE_SUB(NOW(), INTERVAL :m MINUTE)"
        );
        $claim->execute([':m' => $everyMin]);
        if ($claim->rowCount() === 0) return;     // somebody else has it, or too soon
    } catch (Throwable $e) {
        error_log('[sweep] could not claim: ' . $e->getMessage());
        return;
    }

    try {
        runSweep();
    } catch (Throwable $e) {
        // A failed sweep must never surface as a failed board load. It will be
        // retried on the next request past the interval.
        error_log('[sweep] failed: ' . $e->getMessage());
    }
}

/**
 * The work itself. Safe to call directly (the cron endpoint does).
 * Returns ['expired' => n, 'abandoned' => n].
 */
function runSweep(): array {
    $pdo = getDB();

    // ─── Open calls nobody took ──────────────────────────────────────────────
    $stmt = $pdo->query(
        "SELECT id, call_number, provider_account_id, source, stripe_payment_intent_id
           FROM calls
          WHERE status = 'open' AND expires_at < NOW() LIMIT 500"
    );
    $expired = 0;
    foreach ($stmt->fetchAll() as $call) {
        try {
            $pdo->beginTransaction();
            escrowRefund((int)$call['id'], 'Call expired with no taker');
            // Nobody came, so the customer must not be charged. Release the
            // authorisation rather than leaving it sitting on their card.
            if ($call['source'] === 'consumer' && $call['stripe_payment_intent_id']) {
                stripeCancelPayment($call['stripe_payment_intent_id']);
                $pdo->prepare("UPDATE calls SET payment_status = 'refunded' WHERE id = :id")
                    ->execute([':id' => $call['id']]);
            }
            $pdo->prepare("UPDATE calls SET status = 'expired' WHERE id = :id")
                ->execute([':id' => $call['id']]);
            $pdo->prepare("UPDATE bids SET status = 'expired' WHERE call_id = :c AND status = 'pending'")
                ->execute([':c' => $call['id']]);
            logCallEvent((int)$call['id'], 'expired', 'No tower accepted before expiry');
            rtJobClosed((int)$call['id'], 'expired');

            // Only a provider-posted call has somebody to tell. On a consumer
            // job the "provider" account IS the stranded motorist, and posting
            // "your funds have been returned to your balance" to someone who
            // has no balance and was never charged is nonsense.
            if (($call['source'] ?? 'board') !== 'consumer') {
                notify((int)$call['provider_account_id'], 'call_expired',
                    $call['call_number'] . ' expired',
                    'No tower accepted this call. Your funds have been returned to your balance.',
                    (int)$call['id']);
            }
            $pdo->commit();
            $expired++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[sweep] could not expire call ' . $call['id'] . ': ' . $e->getMessage());
        }
    }

    // ─── Abandoned at the card screen ────────────────────────────────────────
    // A draft is a request whose customer never finished paying. No operator
    // ever saw it, so there is nothing to release and nobody to tell — but the
    // PaymentIntent is still open at Stripe.
    $mins = max(10, (int)setting('draft_abandon_minutes', 60));
    $stmt = $pdo->prepare(
        "SELECT id, call_number, stripe_payment_intent_id
           FROM calls
          WHERE status = 'draft' AND created_at < DATE_SUB(NOW(), INTERVAL :m MINUTE)
          LIMIT 200"
    );
    $stmt->execute([':m' => $mins]);
    $abandoned = 0;
    foreach ($stmt->fetchAll() as $call) {
        try {
            if ($call['stripe_payment_intent_id']) {
                stripeCancelPayment($call['stripe_payment_intent_id']);
            }
            // 'canceled', not 'expired'. Surge reads 'expired' as "a real job no
            // truck would take" and raises prices in that area on the strength
            // of it. Somebody who closed the tab before entering a card is not
            // evidence of a shortage of trucks, and must not make the next
            // customer pay more.
            $pdo->prepare("UPDATE calls SET status = 'canceled' WHERE id = :id AND status = 'draft'")
                ->execute([':id' => $call['id']]);
            logCallEvent((int)$call['id'], 'canceled', 'Request abandoned before payment');
            $abandoned++;
        } catch (Throwable $e) {
            error_log('[sweep] could not close draft ' . $call['id'] . ': ' . $e->getMessage());
        }
    }

    return ['expired'   => $expired,
            'abandoned' => $abandoned,
            'reminded'  => remindOpenJobs()];
}

/**
 * Alert again about jobs still sitting open with nobody on them.
 *
 * One notification is one chance. A driver under a truck, on the phone, or
 * three minutes from his van misses it and the job expires with trucks in range
 * that would happily have taken it.
 *
 * Bounded on purpose. Reminders that keep coming are how an operator turns
 * notifications off for good, and then he misses every job rather than one.
 * Two by default, and only while the job is genuinely still available.
 *
 * Timing granularity is the sweep interval (default 5 minutes), so
 * push_repeat_after_seconds is a floor rather than a schedule.
 */
function remindOpenJobs(): int {
    if ((string)setting('push_enabled', '1') !== '1') return 0;
    if ((string)setting('push_repeat_enabled', '1') !== '1') return 0;

    $maxRounds = max(0, (int)setting('push_repeat_max', 2));
    if ($maxRounds === 0) return 0;
    $afterSec  = max(60, (int)setting('push_repeat_after_seconds', 240));

    $pdo = getDB();
    $stmt = $pdo->prepare(
        // expires_at > NOW() matters as much as status: a job in its last few
        // seconds does not need waking anybody up, because nobody could get
        // there and the sweep above is about to expire it anyway.
        "SELECT id FROM calls
          WHERE status = 'open'
            AND expires_at > NOW()
            AND alert_rounds < :max
            AND COALESCE(last_alert_at, created_at) < DATE_SUB(NOW(), INTERVAL :sec SECOND)
          ORDER BY id ASC
          LIMIT 50"
    );
    $stmt->execute([':max' => $maxRounds, ':sec' => $afterSec]);

    $reminded = 0;
    foreach ($stmt->fetchAll() as $row) {
        $callId = (int)$row['id'];
        try {
            // Counted BEFORE sending. If the push half-fails and throws, the
            // round is still spent — the alternative is a job that retries the
            // same broken send on every sweep for the rest of its life.
            $pdo->prepare("UPDATE calls SET alert_rounds = alert_rounds + 1 WHERE id = :id")
                ->execute([':id' => $callId]);

            // pushNewJob re-reads the call and refuses if it is no longer open,
            // so a job taken between the SELECT and here sends nothing.
            $r = pushNewJob($callId, true);
            if (($r['sent'] ?? 0) > 0) $reminded++;
        } catch (Throwable $e) {
            error_log('[sweep] could not remind on call ' . $callId . ': ' . $e->getMessage());
        }
    }
    return $reminded;
}
