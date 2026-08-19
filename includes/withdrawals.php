<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/stripe_connect.php';

// ═══════════════════════════════════════════════════════════════════════════
//  MONEY OUT
//
//  A towing company's available balance is the sum of the payouts it has
//  earned and not yet taken — payouts.status = 'pending' with no withdrawal
//  attached. Withdrawing sweeps those rows into one withdrawal and sends a
//  single Stripe transfer to the company's Connect account, from where Stripe
//  moves it to their bank on their payout schedule.
//
//  One transfer per withdrawal rather than one per job: a company that did
//  forty jobs this week wants one line on its bank statement, and forty Stripe
//  API calls is forty chances to half-fail.
// ═══════════════════════════════════════════════════════════════════════════

/** What a towing company can see and take right now. */
function towerBalance(int $accountId): array {
    $pdo = getDB();

    $q = function (string $sql) use ($pdo, $accountId) {
        $s = $pdo->prepare($sql);
        $s->execute([':a' => $accountId]);
        return (float)($s->fetch()['n'] ?? 0);
    };

    // Earned, not yet swept into a withdrawal.
    $available = $q("SELECT COALESCE(SUM(net_amount),0) n FROM payouts
                      WHERE tower_account_id = :a AND status = 'pending' AND withdrawal_id IS NULL");

    // Swept and sent, not yet confirmed by Stripe.
    $inTransit = $q("SELECT COALESCE(SUM(amount),0) n FROM withdrawals
                      WHERE account_id = :a AND status = 'pending'");

    $lifetime  = $q("SELECT COALESCE(SUM(net_amount),0) n FROM payouts
                      WHERE tower_account_id = :a AND status = 'paid'");
    $fees      = $q("SELECT COALESCE(SUM(platform_fee),0) n FROM payouts
                      WHERE tower_account_id = :a");

    // Work done but not yet finished/released — visible so a company can tell
    // "nothing owed" apart from "owed, still in escrow".
    $working = $q("SELECT COALESCE(SUM(e.amount),0) n FROM escrow_holds e
                    WHERE e.tower_account_id = :a AND e.status = 'held'");

    $stmt = $pdo->prepare(
        "SELECT stripe_account_id, stripe_payouts_enabled, stripe_charges_enabled,
                stripe_details_submitted
           FROM tower_profiles WHERE account_id = :a"
    );
    $stmt->execute([':a' => $accountId]);
    $tp = $stmt->fetch() ?: [];

    $min = (float)setting('min_withdrawal', 25.00);

    // Why they cannot press the button, in the order they would hit them.
    $blockers = [];
    if (empty($tp['stripe_account_id']) || empty($tp['stripe_details_submitted'])) {
        $blockers[] = t('err.wd_no_bank');
    } elseif (empty($tp['stripe_payouts_enabled'])) {
        $blockers[] = t('err.wd_payouts_disabled');
    }
    if ($available < $min) {
        $blockers[] = t('err.wd_below_min', ['min' => number_format($min, 2)]);
    }

    return [
        'available'   => round($available, 2),
        'in_transit'  => round($inTransit, 2),
        'lifetime'    => round($lifetime, 2),
        'fees_paid'   => round($fees, 2),
        'in_escrow'   => round($working, 2),
        'min'         => $min,
        'can_withdraw'=> count($blockers) === 0,
        'blockers'    => $blockers,
        'bank_ready'  => !empty($tp['stripe_payouts_enabled']),
        'payout_mode' => (string)setting('payout_mode', 'manual'),
    ];
}

/**
 * Take the money.
 *
 * The rows are claimed in a transaction FIRST, then the transfer is attempted.
 * Doing it the other way round — transfer, then mark — means a crash between
 * the two pays the company and leaves the balance still showing as available,
 * so the next press pays them again.
 */
function requestWithdrawal(int $accountId): array {
    $pdo = getDB();
    $bal = towerBalance($accountId);
    if (!$bal['can_withdraw']) {
        return ['ok' => false, 'error' => implode(' ', $bal['blockers'])];
    }

    $stmt = $pdo->prepare("SELECT stripe_account_id FROM tower_profiles WHERE account_id = :a");
    $stmt->execute([':a' => $accountId]);
    $stripeAccountId = $stmt->fetch()['stripe_account_id'] ?? null;
    if (!$stripeAccountId) return ['ok' => false, 'error' => t('err.wd_no_bank')];

    $pdo->beginTransaction();
    try {
        // FOR UPDATE so two taps on a slow phone cannot both claim the same
        // payouts and open two withdrawals for one balance.
        // The call's charge id comes along for the ride: each job is transferred
        // against the charge that paid for it.
        $sel = $pdo->prepare(
            "SELECT p.id, p.net_amount, p.call_id, p.attempt_no, p.stripe_transfer_id,
                    c.stripe_charge_id
               FROM payouts p
               LEFT JOIN calls c ON c.id = p.call_id
              WHERE p.tower_account_id = :a AND p.status = 'pending' AND p.withdrawal_id IS NULL
              FOR UPDATE"
        );
        $sel->execute([':a' => $accountId]);
        $rows = $sel->fetchAll();
        if (!$rows) { $pdo->rollBack(); return ['ok' => false, 'error' => t('err.wd_nothing')]; }

        $total = 0.0;
        $ids   = [];
        foreach ($rows as $r) { $total += (float)$r['net_amount']; $ids[] = (int)$r['id']; }
        $total = round($total, 2);

        $pdo->prepare("INSERT INTO withdrawals (account_id, amount) VALUES (:a, :amt)")
            ->execute([':a' => $accountId, ':amt' => $total]);
        $withdrawalId = (int)$pdo->lastInsertId();

        $in = implode(',', $ids);
        $pdo->exec("UPDATE payouts SET withdrawal_id = $withdrawalId WHERE id IN ($in)");

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[withdrawals] claim failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => t('err.wd_failed')];
    }

    // Outside the transaction: Stripe calls take seconds and must not hold row
    // locks on the payouts table while they do.
    //
    // ONE TRANSFER PER JOB, not one for the batch. Each has to name the charge
    // that funded it (source_transaction), and a charge id is per job — so a
    // single batched transfer could only ever be earmarked against one of them.
    //
    // This is what fixes "you have insufficient available funds in your Stripe
    // account". A card capture lands in the pending balance and takes about two
    // business days to become available; a plain transfer can only spend what
    // is already available. Money genuinely collected from a customer therefore
    // could not be paid to the company that earned it.
    $paid = 0.0; $failed = []; $transferIds = [];

    foreach ($rows as $r) {
        // A payout that has been tried before might already be sent: Stripe can
        // have created the transfer and we can have lost the answer. Ask Stripe
        // before spending money again. Only on a retry — the first attempt has
        // nothing to find and does not deserve the extra round trip.
        if ((int)$r['attempt_no'] > 0 && empty($r['stripe_transfer_id'])) {
            $existing = payoutAlreadyTransferred((int)$r['id']);
            if ($existing === null) {
                // Could not reach Stripe to check. Refusing is the safe answer;
                // sending and hoping is how somebody gets paid twice.
                $failed[] = t('err.wd_check_failed');
                $pdo->prepare("UPDATE payouts SET withdrawal_id = NULL, failure_reason = :r WHERE id = :id")
                    ->execute([':r' => 'Could not confirm with Stripe whether this was already sent',
                               ':id' => $r['id']]);
                continue;
            }
            if ($existing !== []) {
                // Already gone. Record it as paid rather than sending twice.
                $paid += (float)$r['net_amount'];
                $transferIds[] = $existing['id'] ?? null;
                $pdo->prepare("UPDATE payouts SET status='paid', paid_at=NOW(),
                                      stripe_transfer_id=:t, failure_reason=NULL
                                WHERE id = :id")
                    ->execute([':t' => $existing['id'] ?? null, ':id' => $r['id']]);
                continue;
            }
        }

        $attempt = (int)$r['attempt_no'] + 1;
        $pdo->prepare("UPDATE payouts SET attempt_no = :n WHERE id = :id")
            ->execute([':n' => $attempt, ':id' => $r['id']]);

        $res = stripeTransferToTower(
            (int)$r['id'], $stripeAccountId, (float)$r['net_amount'],
            (int)$r['call_id'], $r['stripe_charge_id'] ?: null, $withdrawalId, $attempt
        );

        if (!empty($res['ok'])) {
            $tid = $res['data']['id'] ?? null;
            $transferIds[] = $tid;
            $paid += (float)$r['net_amount'];
            $pdo->prepare("UPDATE payouts SET status='paid', paid_at=NOW(), stripe_transfer_id=:t
                            WHERE id = :id")
                ->execute([':t' => $tid, ':id' => $r['id']]);
        } else {
            // Unclaim just this one so it returns to the available balance and
            // can be retried. The others already sent stay sent — reversing a
            // transfer that succeeded to tidy up a sibling that did not would
            // take money back off a company that has done nothing wrong.
            $failed[] = (string)($res['error'] ?? 'unknown');
            $pdo->prepare("UPDATE payouts SET withdrawal_id = NULL, failure_reason = :r WHERE id = :id")
                ->execute([':r' => mb_substr((string)($res['error'] ?? 'unknown'), 0, 255), ':id' => $r['id']]);
        }
    }

    $paid = round($paid, 2);

    // Nothing got through.
    if ($paid <= 0) {
        $pdo->prepare("UPDATE withdrawals SET status='failed', failure_reason=:r WHERE id = :id")
            ->execute([':r' => mb_substr($failed[0] ?? 'unknown', 0, 255), ':id' => $withdrawalId]);
        return ['ok' => false, 'error' => t('err.wd_stripe', ['msg' => $failed[0] ?? ''])];
    }

    // The withdrawal records what actually left, which may be less than what
    // was claimed. Saying $99 went when $49 did is the kind of number somebody
    // reconciles against their bank statement.
    $pdo->prepare("UPDATE withdrawals SET status='paid', amount=:amt, stripe_transfer_id=:t,
                          paid_at=NOW(), failure_reason=:r
                    WHERE id = :id")
        ->execute([
            ':amt' => money($paid),
            ':t'   => $transferIds[0] ?? null,
            ':r'   => $failed ? mb_substr('Some jobs could not be sent: ' . $failed[0], 0, 255) : null,
            ':id'  => $withdrawalId,
        ]);

    // 'amount' is what LEFT. 'requested' is what the button offered. When they
    // differ the caller has to say so — "$49.50 is on its way" under a balance
    // reading $678.19, with six jobs silently bounced back, is a screen that
    // reads as success and is not one.
    return [
        'ok'            => true,
        'withdrawal_id' => $withdrawalId,
        'amount'        => $paid,
        'requested'     => $total,
        'held_back'     => round($total - $paid, 2),
        'jobs_sent'     => count($transferIds),
        'jobs_failed'   => count($failed),
        'failure_reason'=> $failed[0] ?? null,
    ];
}

function withdrawalHistory(int $accountId, int $limit = 50): array {
    $stmt = getDB()->prepare(
        "SELECT w.id, w.amount, w.status, w.failure_reason, w.requested_at, w.paid_at,
                (SELECT COUNT(*) FROM payouts p WHERE p.withdrawal_id = w.id) AS job_count
           FROM withdrawals w
          WHERE w.account_id = :a
          ORDER BY w.id DESC LIMIT " . (int)$limit
    );
    $stmt->execute([':a' => $accountId]);
    return $stmt->fetchAll();
}

// ═══════════════════════════════════════════════════════════════════════════
//  THE PLATFORM'S OWN MONEY
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Money in, money out, and what is actually his.
 *
 * The Stripe balance is asked for rather than calculated. Card processing fees
 * come out of the platform balance before anything else, so a figure worked out
 * from our own tables would always overstate what he can really take — and the
 * number that matters when pressing "pay out" is the one Stripe agrees with.
 */
function platformFinance(): array {
    $pdo = getDB();
    $one = function (string $sql) use ($pdo) {
        try { return (float)($pdo->query($sql)->fetch()['n'] ?? 0); }
        catch (Throwable $e) { return 0.0; }
    };

    $in = [
        'customer_payments' => $one("SELECT COALESCE(SUM(released_amount),0) n FROM escrow_holds WHERE status='released'"),
        'topups'            => $one("SELECT COALESCE(SUM(amount),0) n FROM topups WHERE status='succeeded'"),
    ];
    $out = [
        'paid_to_towers'    => $one("SELECT COALESCE(SUM(net_amount),0) n FROM payouts WHERE status='paid'"),
        'refunded'          => $one("SELECT COALESCE(SUM(refunded_amount),0) n FROM escrow_holds"),
    ];

    // What the platform has earned, and what it still owes.
    $feesEarned  = $one("SELECT COALESCE(SUM(platform_fee),0) n FROM payouts");
    $owedToTowers= $one("SELECT COALESCE(SUM(net_amount),0) n FROM payouts WHERE status='pending'");
    $inEscrow    = $one("SELECT COALESCE(SUM(amount),0) n FROM escrow_holds WHERE status='held'");

    $stripe = ['available' => null, 'pending' => null, 'error' => null];
    $res = stripeRequest('GET', '/balance');
    if (!empty($res['ok'])) {
        $sumUsd = function (array $list) {
            $t = 0.0;
            foreach ($list as $b) if (($b['currency'] ?? '') === 'usd') $t += (float)$b['amount'] / 100;
            return round($t, 2);
        };
        $stripe['available'] = $sumUsd($res['data']['available'] ?? []);
        $stripe['pending']   = $sumUsd($res['data']['pending'] ?? []);
    } else {
        $stripe['error'] = $res['error'] ?? 'Stripe not configured';
    }

    return [
        'in'  => $in,
        'out' => $out,
        'fees_earned'    => round($feesEarned, 2),
        'owed_to_towers' => round($owedToTowers, 2),
        'held_in_escrow' => round($inEscrow, 2),
        'stripe'         => $stripe,
        // Never offer the whole Stripe balance. Money owed to towing companies
        // is sitting in the same pot, and paying it to himself by mistake is a
        // hole he would have to fill out of pocket.
        'safe_to_withdraw' => $stripe['available'] === null
            ? null
            : round(max(0, $stripe['available'] - $owedToTowers - $inEscrow), 2),
    ];
}

/** Pay the platform's own money out to the owner's bank. */
function platformPayout(float $amount, ?string $note = null): array {
    if ((string)setting('platform_payout_enabled', '1') !== '1') {
        return ['ok' => false, 'error' => t('err.pf_payout_off')];
    }
    if ($amount <= 0) return ['ok' => false, 'error' => t('err.pf_amount')];

    $fin = platformFinance();
    if ($fin['safe_to_withdraw'] === null) {
        return ['ok' => false, 'error' => $fin['stripe']['error'] ?: t('err.pf_no_stripe')];
    }
    if ($amount > $fin['safe_to_withdraw'] + 0.005) {
        return ['ok' => false, 'error' => t('err.pf_too_much', [
            'max'   => number_format($fin['safe_to_withdraw'], 2),
            'owed'  => number_format($fin['owed_to_towers'] + $fin['held_in_escrow'], 2),
        ])];
    }

    $res = stripeRequest('POST', '/payouts', [
        'amount'      => (int)round($amount * 100),
        'currency'    => 'usd',
        'description' => $note ? mb_substr($note, 0, 200) : 'TowSling platform payout',
    ]);

    if (empty($res['ok'])) return ['ok' => false, 'error' => $res['error'] ?? 'Stripe refused the payout'];

    return [
        'ok'        => true,
        'payout_id' => $res['data']['id'] ?? null,
        'amount'    => $amount,
        'arrives'   => $res['data']['arrival_date'] ?? null,
    ];
}
