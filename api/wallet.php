<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/escrow.php';
require_once __DIR__ . '/../includes/stripe_connect.php';
setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ═══ BALANCE (provider) ══════════════════════════════════════════════════════
if ($method === 'GET' && ($action === 'balance' || $action === '')) {
    $user = requireAuth();
    requireAccountType($user, 'provider');

    $balance = getBalance((int)$user['account_id']);
    $pdo = getDB();

    // What's actually tied up right now, and behind which calls.
    $stmt = $pdo->prepare(
        "SELECT c.id, c.call_number, c.status, e.amount
           FROM escrow_holds e JOIN calls c ON e.call_id = c.id
          WHERE e.provider_account_id = :a AND e.status = 'held'
          ORDER BY c.created_at DESC"
    );
    $stmt->execute([':a' => $user['account_id']]);
    $openHolds = $stmt->fetchAll();

    successResponse([
        'available'       => (float)$balance['available'],
        'held'            => (float)$balance['held'],
        'total'           => round((float)$balance['available'] + (float)$balance['held'], 2),
        'lifetime_funded' => (float)$balance['lifetime_funded'],
        'lifetime_spent'  => (float)$balance['lifetime_spent'],
        'open_holds'      => $openHolds,
        'min_topup'       => (float)setting('min_topup_amount', 250.00),
    ]);
}

// ═══ TRANSACTIONS ════════════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'transactions') {
    $user = requireAuth();
    $limit = min((int)($_GET['limit'] ?? 100), 500);
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    $stmt = getDB()->prepare(
        "SELECT l.*, c.call_number
           FROM ledger_entries l
      LEFT JOIN calls c ON l.call_id = c.id
          WHERE l.account_id = :a
          ORDER BY l.id DESC LIMIT $limit OFFSET $offset"
    );
    $stmt->execute([':a' => $user['account_id']]);
    successResponse(['transactions' => $stmt->fetchAll()]);
}

// ═══ TOP UP (provider) ═══════════════════════════════════════════════════════
// ACH is the default on purpose: 0.8% capped at $5 against 2.9% + 30c on a
// card. On a $2,000 top-up that is $5 instead of $58.30. Cards exist only so a
// brand new account can start posting the same day.
if ($method === 'POST' && $action === 'topup') {
    $user = requireAuth();
    requireAccountType($user, 'provider');
    requireRole($user, ['owner']);
    $in = jsonInput();

    $amount = round((float)($in['amount'] ?? 0), 2);
    $methodType = in_array($in['method'] ?? 'ach', ['ach', 'card'], true) ? $in['method'] : 'ach';

    $min = (float)setting('min_topup_amount', 250.00);
    if ($amount < $min) errorResponse('Minimum top-up is $' . money($min));
    if ($amount > 100000) errorResponse('For top-ups above $100,000 please contact us directly');

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE id = :a");
    $stmt->execute([':a' => $user['account_id']]);
    $account = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT stripe_customer_id FROM provider_profiles WHERE account_id = :a");
    $stmt->execute([':a' => $user['account_id']]);
    $customerId = $stmt->fetch()['stripe_customer_id'] ?? null;

    if (!$customerId) {
        $res = stripeCreateCustomer($account);
        if (!$res['ok']) errorResponse('Could not set up billing: ' . $res['error'], 502);
        $customerId = $res['data']['id'];
        $pdo->prepare("UPDATE provider_profiles SET stripe_customer_id = :c WHERE account_id = :a")
            ->execute([':c' => $customerId, ':a' => $user['account_id']]);
    }

    $res = stripeCreateTopupIntent($account, $amount, $methodType, $customerId);
    if (!$res['ok']) errorResponse('Could not start top-up: ' . $res['error'], 502);

    $intent = $res['data'];
    $pdo->prepare(
        "INSERT INTO topups (account_id, amount, method, status, stripe_payment_intent_id)
         VALUES (:a, :amt, :m, 'pending', :pi)"
    )->execute([
        ':a' => $user['account_id'], ':amt' => money($amount),
        ':m' => $methodType, ':pi' => $intent['id'],
    ]);

    successResponse([
        'topup_id'      => (int)$pdo->lastInsertId(),
        'client_secret' => $intent['client_secret'],
        'amount'        => money($amount),
        'method'        => $methodType,
        'estimated_fee' => money(estimateTopupFee($amount, $methodType)),
        'publishable_key' => STRIPE_PUBLISHABLE_KEY,
        // ACH debits settle in a few business days; the balance only becomes
        // spendable once Stripe confirms, so say so rather than surprising them.
        'settlement_note' => $methodType === 'ach'
            ? 'Bank transfers usually clear in 3-5 business days. Your balance updates when it settles.'
            : 'Card payments are available immediately.',
    ], 'Top-up started');
}

// ═══ TOP-UP HISTORY ══════════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'topups') {
    $user = requireAuth();
    requireAccountType($user, 'provider');

    $stmt = getDB()->prepare(
        "SELECT id, amount, method, status, failure_reason, created_at, settled_at
           FROM topups WHERE account_id = :a ORDER BY id DESC LIMIT 100"
    );
    $stmt->execute([':a' => $user['account_id']]);
    successResponse(['topups' => $stmt->fetchAll()]);
}

// ═══ FEE PREVIEW ═════════════════════════════════════════════════════════════
// So a provider can see, before funding, exactly what ACH saves them.
if ($method === 'GET' && $action === 'fee-preview') {
    $amount = round((float)($_GET['amount'] ?? 0), 2);
    if ($amount <= 0) errorResponse('amount is required');

    successResponse([
        'amount' => money($amount),
        'ach'  => ['fee' => money(estimateTopupFee($amount, 'ach')),  'note' => '0.8% capped at $5.00'],
        'card' => ['fee' => money(estimateTopupFee($amount, 'card')), 'note' => '2.9% + $0.30'],
        'savings_with_ach' => money(estimateTopupFee($amount, 'card') - estimateTopupFee($amount, 'ach')),
    ]);
}

// ═══ TOWER EARNINGS ══════════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'earnings') {
    $user = requireAuth();
    requireAccountType($user, 'tower');
    $pdo = getDB();

    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN status = 'paid'    THEN net_amount END), 0) AS paid_total,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN net_amount END), 0) AS pending_total,
            COALESCE(SUM(platform_fee), 0) AS fees_total,
            COUNT(*) AS payout_count
           FROM payouts WHERE tower_account_id = :a"
    );
    $stmt->execute([':a' => $user['account_id']]);
    $totals = $stmt->fetch();

    $stmt = $pdo->prepare(
        "SELECT p.*, c.call_number, c.service_type, c.pickup_city, c.pickup_state, c.completed_at
           FROM payouts p LEFT JOIN calls c ON p.call_id = c.id
          WHERE p.tower_account_id = :a
          ORDER BY p.id DESC LIMIT 100"
    );
    $stmt->execute([':a' => $user['account_id']]);

    successResponse([
        'paid_total'    => (float)$totals['paid_total'],
        'pending_total' => (float)$totals['pending_total'],
        'fees_total'    => (float)$totals['fees_total'],
        'payout_count'  => (int)$totals['payout_count'],
        'payouts'       => $stmt->fetchAll(),
    ]);
}

errorResponse('Unknown action', 404);
