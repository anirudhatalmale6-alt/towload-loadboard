<?php
/**
 * Companion to 003_consumer_only.sql.
 *
 * Closes any still-open provider/board job and returns its escrow hold through
 * the escrow engine, so provider_balances and ledger_entries stay consistent.
 * Doing this in SQL would strand the money in `held`.
 *
 * Safe to run more than once.
 */
require_once __DIR__ . '/../includes/escrow.php';

$pdo = getDB();
$rows = $pdo->query(
    "SELECT id, call_number FROM calls
      WHERE source = 'board' AND status IN ('open','awarded')"
)->fetchAll();

$closed = 0;
foreach ($rows as $call) {
    try {
        $pdo->beginTransaction();
        escrowRefund((int)$call['id'], 'Board jobs retired — platform is now customer-direct');
        $pdo->prepare(
            "UPDATE calls SET status = 'canceled', canceled_at = NOW(),
                    cancel_reason = 'Retired: platform is now customer-direct'
              WHERE id = :id"
        )->execute([':id' => $call['id']]);
        $pdo->prepare("UPDATE bids SET status = 'rejected' WHERE call_id = :c AND status = 'pending'")
            ->execute([':c' => $call['id']]);
        logCallEvent((int)$call['id'], 'canceled', 'Board jobs retired');
        $pdo->commit();
        $closed++;
        echo "closed {$call['call_number']}\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo "FAILED {$call['call_number']}: {$e->getMessage()}\n";
    }
}
echo "closed $closed board call(s)\n";

// Every provider balance must now be fully unheld.
foreach ($pdo->query("SELECT account_id, available, held FROM provider_balances") as $b) {
    echo "account {$b['account_id']}: available {$b['available']}, held {$b['held']}\n";
}
