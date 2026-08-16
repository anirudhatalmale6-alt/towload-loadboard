<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/escrow.php';
require_once __DIR__ . '/stripe_connect.php';

// ═══════════════════════════════════════════════════════════════════════════
//  SETTLEMENT — taking the money at the end of a job
//
//  The customer's card has been held since before the job reached the board.
//  This is where that hold becomes an actual charge, and where the tower's
//  payout is created.
//
//  THE ORDER MATTERS, and it used to be wrong. The Stripe capture sat between
//  beginTransaction() and commit(), which has two failure modes that both end
//  with somebody out of pocket:
//
//    - the capture succeeds and the commit then fails. The customer has been
//      charged for a job the database still believes is open, and there is no
//      compensating refund anywhere.
//    - the capture fails and the completion commits anyway. escrowRelease()
//      has already written a pending payout, so the tower is queued to be paid
//      from platform funds for money that was never collected.
//
//  So: the job is closed first and committed, THEN the money is taken, THEN
//  the payout is written. If the process dies in the middle, the job sits
//  completed-but-unsettled — a state that is visible, honest and recoverable
//  by calling settleCall() again. Nothing is ever paid out against a charge
//  that did not happen.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Take the money for a job that has already been closed, then release escrow.
 *
 * Safe to call more than once. A capture that already happened is replayed by
 * Stripe (the idempotency key is the intent id), and a hold that has already
 * been released is left alone rather than paid twice.
 *
 * $mode: 'complete' — the whole awarded amount
 *        'goa'      — the call-out fee only; Stripe releases the remainder of
 *                     the authorisation by itself when a partial capture lands
 *
 * Returns:
 *   ['ok' => true,  'settled' => bool, 'gross','fee','net','payout_id']
 *   ['ok' => false, 'error' => string, 'stage' => 'capture'|'release']
 */
function settleCall(int $callId, string $mode, float $amount): array {
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT * FROM calls WHERE id = :id");
    $stmt->execute([':id' => $callId]);
    $call = $stmt->fetch();
    if (!$call) return ['ok' => false, 'error' => 'Job not found', 'stage' => 'capture'];

    // Already settled. Not an error — a retry, a double-tap, or a webhook
    // arriving after the fact.
    $hold = $pdo->prepare("SELECT * FROM escrow_holds WHERE call_id = :c");
    $hold->execute([':c' => $callId]);
    $hold = $hold->fetch();
    if ($hold && $hold['status'] !== 'held') {
        return ['ok' => true, 'settled' => false, 'gross' => (float)$hold['released_amount'],
                'fee' => (float)$hold['platform_fee'], 'net' => 0.0, 'payout_id' => null];
    }

    // ─── 1. The money ────────────────────────────────────────────────────────
    // Only a consumer job has a card behind it. A board job was funded from the
    // posting provider's balance when it was posted, and there is nothing to
    // charge.
    if (($call['source'] ?? 'board') === 'consumer') {
        if (empty($call['stripe_payment_intent_id'])) {
            return ['ok' => false, 'stage' => 'capture',
                    'error' => 'This job has no card payment attached to it'];
        }

        // Never try to take more than was authorised — Stripe refuses the whole
        // capture, so an over-ask does not charge a smaller amount, it charges
        // nothing at all and the job silently fails to settle.
        $authorized = (float)$call['offer_amount'];
        if ($amount > $authorized + 0.001) {
            error_log('[settle] call ' . $callId . ' asked to capture $' . money($amount)
                      . ' against a $' . money($authorized) . ' hold — capping');
            $amount = $authorized;
        }

        $cap = stripeCapturePayment($call['stripe_payment_intent_id'], $amount);
        if (empty($cap['ok'])) {
            $pdo->prepare("UPDATE calls SET payment_status = 'failed' WHERE id = :id")
                ->execute([':id' => $callId]);
            logCallEvent($callId, 'payment_failed',
                'Card capture failed: ' . substr((string)($cap['error'] ?? 'unknown'), 0, 300));
            // Nothing to notify: admins live in admin_users, not accounts, so
            // notify() cannot reach them. The state itself is the alert —
            // status is closed, escrow is still 'held' and payment_status is
            // 'failed', which is exactly what /api/admin/settlement-issues
            // looks for. A worklist beats a message nobody opens.
            return ['ok' => false, 'stage' => 'capture',
                    'error' => (string)($cap['error'] ?? 'The card could not be charged')];
        }

        // Keep the charge id. The tower's payout is transferred against THIS
        // charge (source_transaction), which is what lets it go out before the
        // card money has finished settling into the available balance.
        $chargeId = $cap['data']['latest_charge'] ?? null;
        if (is_array($chargeId)) $chargeId = $chargeId['id'] ?? null;

        $pdo->prepare("UPDATE calls SET payment_status = 'captured', stripe_charge_id = :ch WHERE id = :id")
            ->execute([':ch' => $chargeId, ':id' => $callId]);
        logCallEvent($callId, 'payment_captured', 'Charged $' . money($amount) . ' to the card');
    }

    // ─── 2. The tower's payout ───────────────────────────────────────────────
    // Only now, with the money genuinely collected.
    try {
        $result = $mode === 'goa'
            ? escrowPartialRelease($callId, $amount, 'GOA')
            : escrowRelease($callId, $amount);
    } catch (Throwable $e) {
        // The charge went through and the payout did not. Money is in, nobody
        // is out of pocket, and the tower's payment is recoverable by retrying
        // — but somebody has to know about it.
        error_log('[settle] release failed after capture on call ' . $callId . ': ' . $e->getMessage());
        logCallEvent($callId, 'payout_pending', 'Charged, but the payout did not write: ' . $e->getMessage());
        return ['ok' => false, 'stage' => 'release', 'error' => $e->getMessage()];
    }

    return [
        'ok'        => true,
        'settled'   => true,
        'gross'     => (float)($result['gross'] ?? $result['tower_gross'] ?? $amount),
        'fee'       => (float)($result['fee'] ?? 0),
        'net'       => (float)($result['net'] ?? $result['tower_net'] ?? 0),
        'payout_id' => $result['payout_id'] ?? null,
        'refund'    => (float)($result['provider_refund'] ?? 0),
    ];
}
