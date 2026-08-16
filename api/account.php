<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/lifecycle.php';

// ═══════════════════════════════════════════════════════════════════════════
//  CLOSING YOUR OWN ACCOUNT
//
//    GET  /api/account/close-check   — what closing would mean, and what stops it
//    POST /api/account/close         — do it
//    POST /api/account/close-request — a customer, who has no login
//
//  Self-service closure ANONYMISES rather than hard-deletes. The person is
//  removed — login gone, name, email, phone, address, documents and uploaded
//  files gone, cannot be matched or contacted again — while the completed jobs
//  and their money stay in the books.
//
//  That is not a softer version of what was asked for; it is the correct one.
//  A completed job is a transaction between three parties, and one of them
//  cannot unilaterally erase the record of it: Ricardo still has to answer a
//  chargeback, file the takings, and prove what happened if somebody claims
//  their car was damaged. The company that closed its account keeps none of its
//  own data either way, which is the part that actually matters to them.
//
//  A hard delete of everything, jobs included, does exist — in the admin panel,
//  where a human can see what is about to go.
// ═══════════════════════════════════════════════════════════════════════════

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ═══ WHAT WOULD HAPPEN ═══════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'close-check') {
    $user   = requireAuth();
    $impact = deletionImpact((int)$user['account_id']);
    if (empty($impact['found'])) errorResponse(t('err.account_not_found'), 404);

    successResponse([
        'can_close' => $impact['can_proceed'],
        'blockers'  => $impact['blockers'],
        'counts'    => $impact['counts'],
        'balances'  => $impact['balances'],
        // Said plainly up front rather than discovered afterwards.
        'keeps_job_records' => $impact['has_financial_history'],
    ]);
}

// ═══ CLOSE IT ════════════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'close') {
    $user = requireAuth();
    // Only the owner. A dispatcher closing the company account would be an
    // employee deleting their employer.
    requireRole($user, ['owner']);

    $in = jsonInput();

    // Password, every time. This is irreversible and a borrowed phone should
    // not be enough to do it.
    $stmt = getDB()->prepare("SELECT password_hash FROM users WHERE id = :u");
    $stmt->execute([':u' => $user['id']]);
    $row = $stmt->fetch();
    if (!$row || empty($in['password']) || !password_verify($in['password'], $row['password_hash'])) {
        errorResponse(t('err.current_password_wrong'), 401);
    }

    // And the word, typed out. A password prompt alone is muscle memory.
    $confirm = strtoupper(trim((string)($in['confirm'] ?? '')));
    if ($confirm !== 'DELETE' && $confirm !== 'BORRAR') {
        errorResponse(t('err.type_delete'));
    }

    $impact = deletionImpact((int)$user['account_id']);
    if (!$impact['can_proceed']) {
        errorResponse(implode(' ', $impact['blockers']), 409);
    }

    $res = anonymizeAccount(
        (int)$user['account_id'], 'self', null,
        !empty($in['reason']) ? (string)$in['reason'] : null
    );
    if (empty($res['ok'])) errorResponse($res['error'] ?? t('err.delete_failed'), 500);

    successResponse(['closed' => true], t('ok.account_closed'));
}

// ═══ A CUSTOMER ASKING ═══════════════════════════════════════════════════════
// Motorists have no login — they are identified only by the tracking token in
// the link they were texted. That token proves they are the person on that job,
// which is enough to close the record it belongs to.
if ($method === 'POST' && $action === 'close-request') {
    $in    = jsonInput();
    $token = (string)($in['token'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) errorResponse(t('err.bad_tracking'), 404);

    $stmt = getDB()->prepare("SELECT id, provider_account_id, status FROM calls WHERE tracking_token = :t");
    $stmt->execute([':t' => $token]);
    $call = $stmt->fetch();
    if (!$call) errorResponse(t('err.job_not_found'), 404);

    $confirm = strtoupper(trim((string)($in['confirm'] ?? '')));
    if ($confirm !== 'DELETE' && $confirm !== 'BORRAR') errorResponse(t('err.type_delete'));

    $accountId = (int)$call['provider_account_id'];
    $impact = deletionImpact($accountId);
    if (empty($impact['found']))     errorResponse(t('err.account_not_found'), 404);
    if (!$impact['can_proceed'])     errorResponse(implode(' ', $impact['blockers']), 409);

    // Guard the account type explicitly. provider_account_id holds a motorist's
    // lightweight account on a customer-direct job, but the column is shared
    // with motor-club providers — and a tracking token must never be able to
    // close one of those.
    $chk = getDB()->prepare("SELECT account_type FROM accounts WHERE id = :a");
    $chk->execute([':a' => $accountId]);
    if (($chk->fetch()['account_type'] ?? '') !== 'consumer') {
        errorResponse(t('err.not_your_account'), 403);
    }

    $res = anonymizeAccount($accountId, 'self', null,
        !empty($in['reason']) ? (string)$in['reason'] : null);
    if (empty($res['ok'])) errorResponse($res['error'] ?? t('err.delete_failed'), 500);

    successResponse(['closed' => true], t('ok.account_closed'));
}

errorResponse('Unknown action', 404);
