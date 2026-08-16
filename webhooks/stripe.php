<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/escrow.php';
require_once __DIR__ . '/../includes/stripe_connect.php';
require_once __DIR__ . '/../includes/adminauth.php';   // adminLog()

// ═══════════════════════════════════════════════════════════════════════════
//  STRIPE WEBHOOK
//
//  Everything here must be safe to run twice — Stripe retries on any non-2xx
//  and will happily redeliver an event days later. Balance credits go through
//  creditTopup(), which is idempotent on the PaymentIntent id.
// ═══════════════════════════════════════════════════════════════════════════

header('Content-Type: application/json');

$payload = file_get_contents('php://input');
$sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (!stripeVerifyWebhook($payload, $sig, STRIPE_WEBHOOK_SECRET)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$event = json_decode($payload, true);
$type = $event['type'] ?? '';
$object = $event['data']['object'] ?? [];

$logLine = date('Y-m-d H:i:s') . ' ' . $type . ' ' . ($object['id'] ?? '') . "\n";
@file_put_contents(__DIR__ . '/stripe-events.log', $logLine, FILE_APPEND);

try {
    switch ($type) {

        // ── Provider funded their balance ────────────────────────────────────
        case 'payment_intent.succeeded':
            if (($object['metadata']['kind'] ?? '') === 'balance_topup') {
                creditTopup($object['id']);
            }
            break;

        case 'payment_intent.processing':
            // ACH in flight. Show it as processing so the provider knows the
            // money is coming but isn't spendable yet.
            getDB()->prepare(
                "UPDATE topups SET status = 'processing' WHERE stripe_payment_intent_id = :pi AND status = 'pending'"
            )->execute([':pi' => $object['id']]);
            break;

        case 'payment_intent.payment_failed':
            $reason = $object['last_payment_error']['message'] ?? 'Payment failed';
            $stmt = getDB()->prepare("SELECT account_id, amount FROM topups WHERE stripe_payment_intent_id = :pi");
            $stmt->execute([':pi' => $object['id']]);
            $topup = $stmt->fetch();

            getDB()->prepare(
                "UPDATE topups SET status = 'failed', failure_reason = :r
                  WHERE stripe_payment_intent_id = :pi AND status <> 'succeeded'"
            )->execute([':r' => substr($reason, 0, 255), ':pi' => $object['id']]);

            if ($topup) {
                notify((int)$topup['account_id'], 'topup_failed',
                    'Top-up failed',
                    'Your $' . money($topup['amount']) . ' top-up did not go through: ' . $reason);
            }
            break;

        // ── An ACH debit was reversed after we already credited it ───────────
        // Rare but real, and the one case that can put a provider negative.
        // Claw the balance back and flag it rather than pretending it settled.
        case 'charge.dispute.created':
        case 'payment_intent.canceled':
            $stmt = getDB()->prepare(
                "SELECT * FROM topups WHERE stripe_payment_intent_id = :pi AND status = 'succeeded'"
            );
            $pi = $object['payment_intent'] ?? $object['id'];
            $stmt->execute([':pi' => $pi]);
            if ($topup = $stmt->fetch()) {
                $pdo = getDB();
                $pdo->beginTransaction();
                try {
                    $pdo->prepare(
                        "UPDATE provider_balances SET available = available - :amt WHERE account_id = :a"
                    )->execute([':amt' => money($topup['amount']), ':a' => $topup['account_id']]);
                    $pdo->prepare("UPDATE topups SET status = 'failed', failure_reason = 'Reversed by bank' WHERE id = :id")
                        ->execute([':id' => $topup['id']]);
                    ledgerWrite((int)$topup['account_id'], 'adjustment', -(float)$topup['amount'],
                        'Top-up reversed by the bank', null, $pi);
                    notify((int)$topup['account_id'], 'topup_reversed',
                        'Top-up reversed',
                        'Your bank reversed a $' . money($topup['amount']) . ' transfer. Your balance has been adjusted.');
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
            }
            break;

        // ── Tower's Connect account changed ──────────────────────────────────
        case 'account.updated':
            $accountId = (int)($object['metadata']['towload_account_id'] ?? 0);
            if (!$accountId) {
                $stmt = getDB()->prepare("SELECT account_id FROM tower_profiles WHERE stripe_account_id = :s");
                $stmt->execute([':s' => $object['id']]);
                $accountId = (int)($stmt->fetch()['account_id'] ?? 0);
            }
            if ($accountId) {
                $wasEnabled = false;
                $stmt = getDB()->prepare("SELECT stripe_payouts_enabled FROM tower_profiles WHERE account_id = :a");
                $stmt->execute([':a' => $accountId]);
                $wasEnabled = (bool)($stmt->fetch()['stripe_payouts_enabled'] ?? false);

                syncConnectStatus($accountId, $object['id']);

                if (!$wasEnabled && !empty($object['payouts_enabled'])) {
                    notify($accountId, 'payouts_enabled',
                        'You can get paid now',
                        'Your bank account is verified. Completed calls will pay out automatically.');
                }
            }
            break;

        // ── Payout to a tower failed at the bank ─────────────────────────────
        // `transfer.failed` no longer exists at Stripe — it is kept here only
        // so an old endpoint still subscribed to it does not fall through to
        // the default. `transfer.reversed` is the live one.
        case 'transfer.failed':
        case 'transfer.reversed':
            // A withdrawal covers many jobs in one transfer and carries its own
            // metadata key. Without this branch a reversed withdrawal was
            // invisible: the company would be told the money was sent, Stripe
            // would have taken it back, and the balance would still read zero.
            $withdrawalId = (int)($object['metadata']['towsling_withdrawal_id'] ?? 0);
            if ($withdrawalId) {
                $pdo = getDB();
                $pdo->prepare(
                    "UPDATE withdrawals SET status = 'failed',
                            failure_reason = 'Reversed at Stripe'
                      WHERE id = :id"
                )->execute([':id' => $withdrawalId]);

                // Hand the jobs back to the available balance so the company can
                // try again once the bank details are fixed.
                $pdo->prepare(
                    "UPDATE payouts SET status = 'pending', withdrawal_id = NULL,
                            paid_at = NULL, failure_reason = 'Withdrawal reversed at Stripe'
                      WHERE withdrawal_id = :id"
                )->execute([':id' => $withdrawalId]);

                $stmt = $pdo->prepare("SELECT account_id, amount FROM withdrawals WHERE id = :id");
                $stmt->execute([':id' => $withdrawalId]);
                if ($w = $stmt->fetch()) {
                    notify((int)$w['account_id'], 'payout_failed',
                        'Withdrawal could not be sent',
                        'Your $' . money($w['amount']) . ' withdrawal was returned. '
                        . 'Check your bank details — the money is back in your available balance.');
                }
                break;
            }

            $payoutId = (int)($object['metadata']['towload_payout_id'] ?? 0);
            if ($payoutId) {
                getDB()->prepare(
                    "UPDATE payouts SET status = 'failed', failure_reason = 'Transfer reversed at Stripe' WHERE id = :id"
                )->execute([':id' => $payoutId]);

                $stmt = getDB()->prepare("SELECT tower_account_id, net_amount FROM payouts WHERE id = :id");
                $stmt->execute([':id' => $payoutId]);
                if ($p = $stmt->fetch()) {
                    notify((int)$p['tower_account_id'], 'payout_failed',
                        'Payout could not be sent',
                        'Your $' . money($p['net_amount']) . ' payout failed. Check your bank details in your payout settings.');
                }
            }
            break;

        // ── The platform's own money reaching Ricardo's bank ─────────────────
        // Different from a transfer: a `payout` is Stripe moving the platform
        // balance to the platform's own bank account. Without these two he
        // would press "send to my bank" and never learn whether it landed.
        case 'payout.paid':
        case 'payout.failed':
            $amount = number_format(((int)($object['amount'] ?? 0)) / 100, 2);
            $ok     = $type === 'payout.paid';
            // admin_id 0 — this was Stripe reporting back, not a person acting.
            adminLog(0,
                $ok ? 'platform_payout_paid' : 'platform_payout_failed',
                ($ok ? 'Payout of $' . $amount . ' arrived.'
                     : 'Payout of $' . $amount . ' FAILED: '
                       . ($object['failure_message'] ?? $object['failure_code'] ?? 'no reason given'))
                . ' (' . ($object['id'] ?? '?') . ')'
            );
            break;

        // ── Tower subscription lifecycle ─────────────────────────────────────
        case 'customer.subscription.updated':
        case 'customer.subscription.deleted':
            $subId = $object['id'] ?? '';
            $status = $object['status'] ?? '';
            $map = [
                'active' => 'active', 'trialing' => 'trialing', 'past_due' => 'past_due',
                'canceled' => 'canceled', 'unpaid' => 'past_due', 'incomplete' => 'incomplete',
            ];
            if ($subId && isset($map[$status])) {
                getDB()->prepare(
                    "UPDATE subscriptions
                        SET status = :s,
                            current_period_end = FROM_UNIXTIME(:pe),
                            canceled_at = IF(:s2 = 'canceled', NOW(), canceled_at)
                      WHERE stripe_subscription_id = :sub"
                )->execute([
                    ':s' => $map[$status], ':s2' => $map[$status],
                    ':pe' => $object['current_period_end'] ?? time(),
                    ':sub' => $subId,
                ]);
            }
            break;
    }

    http_response_code(200);
    echo json_encode(['received' => true]);

} catch (Throwable $e) {
    @file_put_contents(__DIR__ . '/stripe-events.log',
        date('Y-m-d H:i:s') . ' ERROR ' . $type . ': ' . $e->getMessage() . "\n", FILE_APPEND);
    // 500 so Stripe retries rather than dropping the event on the floor.
    http_response_code(500);
    echo json_encode(['error' => 'Handler failed']);
}
