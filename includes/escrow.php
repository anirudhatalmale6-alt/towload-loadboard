<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/pricing.php';

// ═══════════════════════════════════════════════════════════════════════════
//  ESCROW ENGINE
//
//  Every dollar that moves does so through exactly one of these four functions.
//  Nothing else in the codebase is allowed to write provider_balances or
//  escrow_holds directly — if you need a new money path, add it here.
//
//  Lifecycle:
//    escrowHold()          call awarded  -> available -> held
//    escrowRelease()       completed     -> held -> tower payout + platform fee
//    escrowRefund()        canceled/expired -> held -> available
//    escrowPartialRelease() GOA/dispute  -> held -> split between both sides
//
//  A hold is backed by one of two things:
//    funding_source 'balance' — a provider's prepaid balance (board jobs)
//    funding_source 'card'    — an authorisation on a customer's card
//                               (direct-from-consumer jobs)
//  Everything downstream is identical; only the provider_balances bookkeeping
//  is skipped for card-funded holds, because there is no balance to move.
// ═══════════════════════════════════════════════════════════════════════════

// Board jobs and consumer jobs carry different cuts. One lookup so the fee can
// never diverge between what was quoted and what is actually taken.
function feeForCall(int $callId, float $amount): float {
    $stmt = getDB()->prepare("SELECT source FROM calls WHERE id = :c");
    $stmt->execute([':c' => $callId]);
    $source = $stmt->fetch()['source'] ?? 'board';
    return $source === 'consumer' ? consumerFee($amount) : platformFee($amount);
}

/**
 * Move funds from a provider's available balance into escrow for a call.
 * Throws if the provider can't cover it — the caller must treat that as a
 * hard failure and not award the call.
 */
function escrowHold(int $callId, int $providerAccountId, ?int $towerAccountId, float $amount,
                    string $fundingSource = 'balance', ?string $paymentIntentId = null): int {
    $pdo = getDB();
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();

    try {
        // Card-funded: the money is authorised on the customer's card, not sitting
        // in a balance we control. Record the hold and stop — there is nothing to
        // debit, and touching provider_balances here would invent money.
        if ($fundingSource === 'card') {
            $pdo->prepare(
                "INSERT INTO escrow_holds (call_id, provider_account_id, tower_account_id,
                                           amount, funding_source, stripe_payment_intent_id, status)
                 VALUES (:c, :p, :t, :amt, 'card', :pi, 'held')
                 ON DUPLICATE KEY UPDATE tower_account_id = VALUES(tower_account_id),
                                         amount = VALUES(amount),
                                         stripe_payment_intent_id = VALUES(stripe_payment_intent_id),
                                         status = 'held'"
            )->execute([
                ':c' => $callId, ':p' => $providerAccountId, ':t' => $towerAccountId,
                ':amt' => money($amount), ':pi' => $paymentIntentId,
            ]);
            $holdId = (int)$pdo->lastInsertId();
            if ($ownTx) $pdo->commit();
            return $holdId;
        }

        // Lock the balance row so two dispatchers awarding at once can't both
        // pass the funds check against the same dollars.
        $stmt = $pdo->prepare("SELECT * FROM provider_balances WHERE account_id = :a FOR UPDATE");
        $stmt->execute([':a' => $providerAccountId]);
        $bal = $stmt->fetch();

        if (!$bal) {
            $pdo->prepare("INSERT INTO provider_balances (account_id) VALUES (:a)")
                ->execute([':a' => $providerAccountId]);
            $stmt->execute([':a' => $providerAccountId]);
            $bal = $stmt->fetch();
        }

        // Invoice-terms providers (the big motor clubs, phase 2) don't prepay;
        // they're held against a credit limit instead.
        $pStmt = $pdo->prepare("SELECT billing_mode, credit_limit FROM provider_profiles WHERE account_id = :a");
        $pStmt->execute([':a' => $providerAccountId]);
        $profile = $pStmt->fetch() ?: ['billing_mode' => 'escrow', 'credit_limit' => 0];

        $available = (float)$bal['available'];
        if ($profile['billing_mode'] === 'invoice') {
            $available += (float)$profile['credit_limit'];
        }

        if ($available + 0.001 < $amount) {
            throw new RuntimeException(
                'Insufficient balance. Available $' . money($bal['available']) .
                ', this call needs $' . money($amount) . '.'
            );
        }

        $pdo->prepare(
            "UPDATE provider_balances
                SET available = available - :amt1, held = held + :amt2
              WHERE account_id = :a"
        )->execute([':amt1' => money($amount), ':amt2' => money($amount), ':a' => $providerAccountId]);

        $pdo->prepare(
            "INSERT INTO escrow_holds (call_id, provider_account_id, tower_account_id, amount, status)
             VALUES (:c, :p, :t, :amt, 'held')
             ON DUPLICATE KEY UPDATE tower_account_id = VALUES(tower_account_id),
                                     amount = VALUES(amount), status = 'held'"
        )->execute([':c' => $callId, ':p' => $providerAccountId, ':t' => $towerAccountId, ':amt' => money($amount)]);

        $holdId = (int)$pdo->lastInsertId();

        ledgerWrite($providerAccountId, 'hold', -$amount,
            'Funds held for call #' . $callId, $callId);

        if ($ownTx) $pdo->commit();
        return $holdId;
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Attach the winning tower to an existing hold.
 *
 * Funds are held when the call is POSTED, not when it's awarded — that way
 * every call on the board is visibly funded, which is the entire point of
 * running escrow. The tower is only known later, so it's filled in here.
 */
function escrowAssignTower(int $callId, int $towerAccountId): void {
    getDB()->prepare(
        "UPDATE escrow_holds SET tower_account_id = :t WHERE call_id = :c AND status = 'held'"
    )->execute([':t' => $towerAccountId, ':c' => $callId]);
}

/**
 * The tower let the job go. Detach it and leave the money exactly where it is.
 *
 * The hold is NOT released. It belongs to the customer's card and the job it
 * was taken for is still live — it has simply gone back on the board looking
 * for somebody else. Refunding here and re-authorising on the next accept
 * would put a second hold on a stranded person's card within minutes of the
 * first, which is precisely the pattern a bank blocks.
 *
 * Leaving tower_account_id pointing at the company that walked away is the
 * quiet failure this prevents: every payout and settlement query keys off it,
 * so the next completion would credit the wrong company.
 */
function escrowUnassignTower(int $callId): void {
    getDB()->prepare(
        "UPDATE escrow_holds SET tower_account_id = NULL WHERE call_id = :c AND status = 'held'"
    )->execute([':c' => $callId]);
}

/**
 * Job completed. Held funds leave the provider for good; the tower is credited
 * the amount minus our fee, and a payout row is queued for Stripe transfer.
 */
function escrowRelease(int $callId, float $awardedAmount): array {
    $pdo = getDB();
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("SELECT * FROM escrow_holds WHERE call_id = :c FOR UPDATE");
        $stmt->execute([':c' => $callId]);
        $hold = $stmt->fetch();
        if (!$hold) throw new RuntimeException('No escrow hold found for this call');
        if ($hold['status'] !== 'held') {
            throw new RuntimeException('Escrow already ' . $hold['status']);
        }

        $held = (float)$hold['amount'];
        // Never release more than was held. If the final amount came in lower
        // (shorter tow than quoted), the difference goes back to the provider.
        $release = min($awardedAmount, $held);
        $refund  = round($held - $release, 2);

        $fee = feeForCall($callId, $release);
        $net = round($release - $fee, 2);

        if ($hold['funding_source'] === 'balance') {
            $pdo->prepare(
                "UPDATE provider_balances
                    SET held = held - :held,
                        available = available + :refund,
                        lifetime_spent = lifetime_spent + :spent
                  WHERE account_id = :a"
            )->execute([
                ':held' => money($held), ':refund' => money($refund),
                ':spent' => money($release), ':a' => $hold['provider_account_id'],
            ]);
        }

        $pdo->prepare(
            "UPDATE escrow_holds
                SET status = 'released', released_amount = :rel, platform_fee = :fee,
                    refunded_amount = :ref, released_at = NOW()
              WHERE id = :id"
        )->execute([
            ':rel' => money($release), ':fee' => money($fee),
            ':ref' => money($refund), ':id' => $hold['id'],
        ]);

        $pdo->prepare(
            "INSERT INTO payouts (tower_account_id, call_id, escrow_hold_id,
                                  gross_amount, platform_fee, net_amount, status)
             VALUES (:t, :c, :h, :g, :f, :n, 'pending')"
        )->execute([
            ':t' => $hold['tower_account_id'], ':c' => $callId, ':h' => $hold['id'],
            ':g' => money($release), ':f' => money($fee), ':n' => money($net),
        ]);
        $payoutId = (int)$pdo->lastInsertId();

        if ($hold['funding_source'] === 'balance') {
            ledgerWrite((int)$hold['provider_account_id'], 'hold_release', 0.0,
                'Released $' . money($release) . ' for call #' . $callId, $callId);
            if ($refund > 0) {
                ledgerWrite((int)$hold['provider_account_id'], 'hold_refund', $refund,
                    'Unused hold returned for call #' . $callId, $callId);
            }
        }
        ledgerWrite((int)$hold['tower_account_id'], 'payout', $net,
            'Payout for call #' . $callId . ' (fee $' . money($fee) . ')', $callId);

        if ($ownTx) $pdo->commit();
        return ['payout_id' => $payoutId, 'gross' => $release, 'fee' => $fee,
                'net' => $net, 'refunded' => $refund];
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Call canceled or expired before completion. Everything goes back.
 */
function escrowRefund(int $callId, string $reason = 'Call canceled'): void {
    $pdo = getDB();
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("SELECT * FROM escrow_holds WHERE call_id = :c FOR UPDATE");
        $stmt->execute([':c' => $callId]);
        $hold = $stmt->fetch();
        if (!$hold) { if ($ownTx) $pdo->commit(); return; }   // nothing was ever held
        if ($hold['status'] !== 'held') {
            throw new RuntimeException('Escrow already ' . $hold['status']);
        }

        $amount = (float)$hold['amount'];

        if ($hold['funding_source'] === 'balance') {
            $pdo->prepare(
                "UPDATE provider_balances
                    SET held = held - :amt1, available = available + :amt2
                  WHERE account_id = :a"
            )->execute([':amt1' => money($amount), ':amt2' => money($amount), ':a' => $hold['provider_account_id']]);
        }

        $pdo->prepare(
            "UPDATE escrow_holds
                SET status = 'refunded', refunded_amount = :amt, released_at = NOW()
              WHERE id = :id"
        )->execute([':amt' => money($amount), ':id' => $hold['id']]);

        if ($hold['funding_source'] === 'balance') {
            ledgerWrite((int)$hold['provider_account_id'], 'hold_refund', $amount,
                $reason . ' — call #' . $callId, $callId);
        }

        if ($ownTx) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * GOA (gone on arrival) and dispute settlements.
 *
 * The tower drove out and the vehicle wasn't there — they earned something,
 * but not the full tow. Split the hold: tower gets $towerAmount (minus fee),
 * the provider gets the rest back.
 */
function escrowPartialRelease(int $callId, float $towerAmount, string $reason = 'GOA'): array {
    $pdo = getDB();
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("SELECT * FROM escrow_holds WHERE call_id = :c FOR UPDATE");
        $stmt->execute([':c' => $callId]);
        $hold = $stmt->fetch();
        if (!$hold) throw new RuntimeException('No escrow hold found for this call');
        if ($hold['status'] !== 'held') {
            throw new RuntimeException('Escrow already ' . $hold['status']);
        }

        $held  = (float)$hold['amount'];
        $toTower = max(0.0, min($towerAmount, $held));
        $toProvider = round($held - $toTower, 2);

        // On a GOA the fee is charged on what the tower actually receives, so a
        // $45 GOA doesn't get eaten alive by a percentage meant for a full tow.
        $fee = $toTower > 0 ? feeForCall($callId, $toTower) : 0.0;
        $net = round($toTower - $fee, 2);

        if ($hold['funding_source'] === 'balance') {
            $pdo->prepare(
                "UPDATE provider_balances
                    SET held = held - :held,
                        available = available + :back,
                        lifetime_spent = lifetime_spent + :spent
                  WHERE account_id = :a"
            )->execute([
                ':held' => money($held), ':back' => money($toProvider),
                ':spent' => money($toTower), ':a' => $hold['provider_account_id'],
            ]);
        }

        $pdo->prepare(
            "UPDATE escrow_holds
                SET status = 'partial', released_amount = :rel, platform_fee = :fee,
                    refunded_amount = :ref, released_at = NOW()
              WHERE id = :id"
        )->execute([
            ':rel' => money($toTower), ':fee' => money($fee),
            ':ref' => money($toProvider), ':id' => $hold['id'],
        ]);

        $payoutId = null;
        if ($net > 0) {
            $pdo->prepare(
                "INSERT INTO payouts (tower_account_id, call_id, escrow_hold_id,
                                      gross_amount, platform_fee, net_amount, status)
                 VALUES (:t, :c, :h, :g, :f, :n, 'pending')"
            )->execute([
                ':t' => $hold['tower_account_id'], ':c' => $callId, ':h' => $hold['id'],
                ':g' => money($toTower), ':f' => money($fee), ':n' => money($net),
            ]);
            $payoutId = (int)$pdo->lastInsertId();
            ledgerWrite((int)$hold['tower_account_id'], 'payout', $net,
                $reason . ' payout for call #' . $callId, $callId);
        }

        if ($toProvider > 0 && $hold['funding_source'] === 'balance') {
            ledgerWrite((int)$hold['provider_account_id'], 'hold_refund', $toProvider,
                $reason . ' — balance returned for call #' . $callId, $callId);
        }

        if ($ownTx) $pdo->commit();
        return ['payout_id' => $payoutId, 'tower_gross' => $toTower, 'fee' => $fee,
                'tower_net' => $net, 'provider_refund' => $toProvider];
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Credit a completed top-up into the provider's spendable balance.
 * Idempotent on the Stripe PaymentIntent id — webhooks retry, and double
 * crediting a balance is the worst bug this system could have.
 */
function creditTopup(string $paymentIntentId): bool {
    $pdo = getDB();
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("SELECT * FROM topups WHERE stripe_payment_intent_id = :pi FOR UPDATE");
        $stmt->execute([':pi' => $paymentIntentId]);
        $topup = $stmt->fetch();

        if (!$topup) { if ($ownTx) $pdo->commit(); return false; }
        if ($topup['status'] === 'succeeded') { if ($ownTx) $pdo->commit(); return false; }

        $amount = (float)$topup['amount'];

        getBalance((int)$topup['account_id']);   // ensure the row exists
        $pdo->prepare(
            "UPDATE provider_balances
                SET available = available + :amt1, lifetime_funded = lifetime_funded + :amt2
              WHERE account_id = :a"
        )->execute([':amt1' => money($amount), ':amt2' => money($amount), ':a' => $topup['account_id']]);

        $pdo->prepare(
            "UPDATE topups SET status = 'succeeded', settled_at = NOW() WHERE id = :id"
        )->execute([':id' => $topup['id']]);

        ledgerWrite((int)$topup['account_id'], 'topup', $amount,
            'Balance top-up (' . $topup['method'] . ')', null, $paymentIntentId);

        if ($ownTx) $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
