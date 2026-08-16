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
                c.call_number, c.service_type, c.pickup_city, c.pickup_state, c.completed_at
           FROM payouts p
      LEFT JOIN calls c ON c.id = p.call_id
          WHERE p.tower_account_id = :a AND p.status = 'pending' AND p.withdrawal_id IS NULL
          ORDER BY p.id DESC LIMIT 100"
    );
    $stmt->execute([':a' => $user['account_id']]);

    successResponse(array_merge($bal, ['jobs' => $stmt->fetchAll()]));
}

if ($method === 'POST' && $action === 'withdraw') {
    $user = requireAuth();
    requireAccountType($user, 'tower');
    // Only the owner moves money out of the company.
    requireRole($user, ['owner']);

    $res = requestWithdrawal((int)$user['account_id']);
    if (empty($res['ok'])) errorResponse($res['error'], 409);

    successResponse(
        ['withdrawal_id' => $res['withdrawal_id'], 'amount' => $res['amount']],
        t('ok.withdrawal_sent', ['amount' => number_format($res['amount'], 2)])
    );
}

if ($method === 'GET' && $action === 'history') {
    $user = requireAuth();
    requireAccountType($user, 'tower');
    successResponse(['withdrawals' => withdrawalHistory((int)$user['account_id'])]);
}

errorResponse('Unknown action', 404);
