<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/withdrawals.php';

// ═══════════════════════════════════════════════════════════════════════════
//  A TOWING COMPANY'S MONEY
//
//    GET  /api/payouts/balance   — available, in transit, in escrow, lifetime
//    POST /api/payouts/withdraw  — take the available balance to their bank
//    GET  /api/payouts/history   — past withdrawals and the jobs behind them
// ═══════════════════════════════════════════════════════════════════════════

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET' && ($action === 'balance' || $action === '')) {
    $user = requireAuth();
    requireAccountType($user, 'tower');

    $bal = towerBalance((int)$user['account_id']);

    // The jobs making up the current balance, so the number is checkable
    // rather than something they have to take on faith.
    $stmt = getDB()->prepare(
        "SELECT p.id, p.net_amount, p.gross_amount, p.platform_fee, p.created_at,
                p.failure_reason, p.attempt_no,
                c.id AS call_row_id, c.call_number, c.service_type,
                c.pickup_city, c.pickup_state, c.completed_at
           FROM payouts p
      LEFT JOIN calls c ON c.id = p.call_id
          WHERE p.tower_account_id = :a AND p.status = 'pending' AND p.withdrawal_id IS NULL
          ORDER BY p.id DESC LIMIT 100"
    );
    $stmt->execute([':a' => $user['account_id']]);
    $jobs = $stmt->fetchAll();

    // A payout whose call has been deleted rendered as a bare "Job" with no
    // number and no town — indistinguishable from a real one, and counted in
    // the balance. Name it for what it is instead of leaving a blank line
    // beside an amount somebody is trying to withdraw.
    foreach ($jobs as &$j) {
        $j['orphaned'] = ($j['call_row_id'] === null);
        if ($j['orphaned'] && ($j['call_number'] ?? null) === null) {
            $j['call_number'] = t('money.job_removed');
        }
        unset($j['call_row_id']);
    }
    unset($j);

    successResponse(array_merge($bal, ['jobs' => $jobs]));
}

if ($method === 'POST' && $action === 'withdraw') {
    $user = requireAuth();
    requireAccountType($user, 'tower');
    // Only the owner moves money out of the company.
    requireRole($user, ['owner']);

    $res = requestWithdrawal((int)$user['account_id']);
    if (empty($res['ok'])) errorResponse($res['error'], 409);

    // Part of a withdrawal can succeed while the rest bounces back. Saying
    // "$49.50 is on its way" and stopping there let somebody believe a $678.19
    // balance had gone to their bank when six of seven jobs had failed and
    // were sitting right back where they started. If anything was held back,
    // the message says how much and why.
    $partial = (float)($res['held_back'] ?? 0) > 0.005;
    $message = $partial
        ? t('ok.withdrawal_partial', [
              'sent'    => number_format((float)$res['amount'], 2),
              'held'    => number_format((float)$res['held_back'], 2),
              'n'       => (int)$res['jobs_failed'],
              'reason'  => (string)($res['failure_reason'] ?? ''),
          ])
        : t('ok.withdrawal_sent', ['amount' => number_format((float)$res['amount'], 2)]);

    successResponse([
        'withdrawal_id'  => $res['withdrawal_id'],
        'amount'         => $res['amount'],
        'requested'      => $res['requested'],
        'held_back'      => $res['held_back'],
        'jobs_sent'      => $res['jobs_sent'],
        'jobs_failed'    => $res['jobs_failed'],
        'partial'        => $partial,
        'failure_reason' => $res['failure_reason'],
    ], $message);
}

if ($method === 'GET' && $action === 'history') {
    $user = requireAuth();
    requireAccountType($user, 'tower');
    successResponse(['withdrawals' => withdrawalHistory((int)$user['account_id'])]);
}

errorResponse('Unknown action', 404);
