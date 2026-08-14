<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/stripe_connect.php';
setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ═══ START / RESUME ONBOARDING (tower) ═══════════════════════════════════════
// Creates the Express account if needed and hands back a Stripe-hosted link.
// Stripe collects EIN, bank details and ID docs — we never see or store them.
if ($method === 'POST' && ($action === 'onboard' || $action === '')) {
    $user = requireAuth();
    requireAccountType($user, 'tower');
    requireRole($user, ['owner']);

    $pdo = getDB();
    $stmt = $pdo->prepare(
        "SELECT a.*, tp.stripe_account_id
           FROM accounts a LEFT JOIN tower_profiles tp ON tp.account_id = a.id
          WHERE a.id = :a"
    );
    $stmt->execute([':a' => $user['account_id']]);
    $account = $stmt->fetch();
    if (!$account) errorResponse('Account not found', 404);

    $stripeAccountId = $account['stripe_account_id'];

    if (!$stripeAccountId) {
        $res = stripeCreateConnectAccount($account);
        if (!$res['ok']) errorResponse('Could not create payout account: ' . $res['error'], 502);
        $stripeAccountId = $res['data']['id'];
        $pdo->prepare("UPDATE tower_profiles SET stripe_account_id = :s WHERE account_id = :a")
            ->execute([':s' => $stripeAccountId, ':a' => $user['account_id']]);
    }

    // Account links are single-use and short-lived — always mint a fresh one.
    $link = stripeCreateAccountLink($stripeAccountId);
    if (!$link['ok']) errorResponse('Could not create onboarding link: ' . $link['error'], 502);

    successResponse([
        'onboarding_url' => $link['data']['url'],
        'expires_at'     => $link['data']['expires_at'] ?? null,
    ], 'Open this link to finish setting up payouts');
}

// ═══ STATUS ══════════════════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'status') {
    $user = requireAuth();
    requireAccountType($user, 'tower');

    $stmt = getDB()->prepare("SELECT * FROM tower_profiles WHERE account_id = :a");
    $stmt->execute([':a' => $user['account_id']]);
    $profile = $stmt->fetch();

    if (!$profile || !$profile['stripe_account_id']) {
        successResponse([
            'connected'        => false,
            'payouts_enabled'  => false,
            'next_step'        => 'Connect your bank account so you can get paid for completed calls.',
        ]);
    }

    // Ask Stripe rather than trusting our own cached copy — requirements can
    // change on their side (expired ID, new bank verification) without a webhook
    // we happened to catch.
    $sync = syncConnectStatus((int)$user['account_id'], $profile['stripe_account_id']);
    if (!$sync['ok']) {
        successResponse([
            'connected'       => true,
            'payouts_enabled' => (bool)$profile['stripe_payouts_enabled'],
            'stale'           => true,
            'error'           => $sync['error'],
        ]);
    }

    $d = $sync['data'];
    successResponse([
        'connected'         => true,
        'payouts_enabled'   => $d['payouts_enabled'],
        'details_submitted' => $d['details_submitted'],
        'requirements_due'  => $d['requirements_due'],
        'next_step'         => $d['payouts_enabled']
            ? null
            : ($d['requirements_due']
                ? 'Stripe still needs: ' . implode(', ', $d['requirements_due'])
                : 'Finish your payout setup to start accepting calls.'),
    ]);
}

// ═══ EXPRESS DASHBOARD LINK ══════════════════════════════════════════════════
// Lets a tower see their own payouts, bank account and tax docs on Stripe.
if ($method === 'POST' && $action === 'dashboard') {
    $user = requireAuth();
    requireAccountType($user, 'tower');
    requireRole($user, ['owner']);

    $stmt = getDB()->prepare("SELECT stripe_account_id FROM tower_profiles WHERE account_id = :a");
    $stmt->execute([':a' => $user['account_id']]);
    $stripeAccountId = $stmt->fetch()['stripe_account_id'] ?? null;
    if (!$stripeAccountId) errorResponse('Set up payouts first', 400);

    $res = stripeCreateLoginLink($stripeAccountId);
    if (!$res['ok']) errorResponse('Could not open dashboard: ' . $res['error'], 502);

    successResponse(['dashboard_url' => $res['data']['url']]);
}

// ═══ PROCESS PENDING PAYOUTS (cron) ══════════════════════════════════════════
// Completed calls queue a payout row; this pushes them to Stripe. Kept as a
// separate sweep so a Stripe outage never blocks a driver closing a job.
if ($action === 'process-payouts') {
    $pdo = getDB();
    $stmt = $pdo->query(
        "SELECT p.*, tp.stripe_account_id
           FROM payouts p
           JOIN tower_profiles tp ON tp.account_id = p.tower_account_id
          WHERE p.status = 'pending'
            AND tp.stripe_payouts_enabled = 1
            AND tp.stripe_account_id IS NOT NULL
          ORDER BY p.id ASC LIMIT 100"
    );

    $sent = 0; $failed = 0;
    foreach ($stmt->fetchAll() as $payout) {
        $res = stripeTransferToTower(
            (int)$payout['id'],
            $payout['stripe_account_id'],
            (float)$payout['net_amount'],
            (int)$payout['call_id']
        );

        if ($res['ok']) {
            $pdo->prepare(
                "UPDATE payouts SET status = 'paid', stripe_transfer_id = :t, paid_at = NOW() WHERE id = :id"
            )->execute([':t' => $res['data']['id'], ':id' => $payout['id']]);

            notify((int)$payout['tower_account_id'], 'payout_sent',
                'Payment sent — $' . money($payout['net_amount']),
                'Your payout for call #' . $payout['call_id'] . ' is on its way to your bank.',
                (int)$payout['call_id']);
            $sent++;
        } else {
            // Leave it pending so the next sweep retries; the idempotency key
            // means a retry can never double-pay.
            $pdo->prepare("UPDATE payouts SET failure_reason = :r WHERE id = :id")
                ->execute([':r' => substr($res['error'], 0, 255), ':id' => $payout['id']]);
            $failed++;
        }
    }

    successResponse(['sent' => $sent, 'failed' => $failed]);
}

errorResponse('Unknown action', 404);
