<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/support.php';

// ═══════════════════════════════════════════════════════════════════════════
//  SUPPORT REQUESTS
//
//    POST /api/support/create   — towing company (signed in) or customer
//    GET  /api/support/mine     — a signed-in company's own tickets
//
//  A stranded motorist has no login. The only credential they hold is the
//  tracking token from the link they were texted, so a ticket raised from that
//  page carries it and gets attached to the right job; a ticket raised from
//  anywhere else is accepted on its own merits with an email address.
// ═══════════════════════════════════════════════════════════════════════════

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'POST' && $action === 'create') {
    $in = jsonInput();

    $payload = [
        'subject' => $in['subject'] ?? '',
        'message' => $in['message'] ?? '',
        'email'   => $in['email'] ?? '',
        'name'    => $in['name'] ?? null,
        'phone'   => $in['phone'] ?? null,
        'kind'    => 'customer',
    ];

    // A signed-in company: take its identity from the token, never from the
    // request body. Otherwise anyone can raise a ticket "from" any company.
    $token  = bearerToken();
    $claims = $token ? verifyJWT($token) : null;
    if ($claims && ($claims['kind'] ?? '') !== 'admin' && !empty($claims['account_id'])) {
        $stmt = getDB()->prepare(
            "SELECT a.id, a.name, a.email, a.phone, a.account_type
               FROM accounts a WHERE a.id = :a"
        );
        $stmt->execute([':a' => (int)$claims['account_id']]);
        if ($acct = $stmt->fetch()) {
            $payload['account_id'] = (int)$acct['id'];
            $payload['kind']       = $acct['account_type'] === 'tower' ? 'tower' : 'customer';
            $payload['name']       = $payload['name']  ?: $acct['name'];
            $payload['email']      = $payload['email'] ?: $acct['email'];
            $payload['phone']      = $payload['phone'] ?: $acct['phone'];
        }
    }

    // A customer writing in from their tracking page. The token proves which
    // job they are talking about, which is most of what support needs to know.
    if (!empty($in['token']) && preg_match('/^[a-f0-9]{32}$/', $in['token'])) {
        $stmt = getDB()->prepare(
            "SELECT id, call_number, customer_name, customer_phone, customer_email,
                    provider_account_id
               FROM calls WHERE tracking_token = :t"
        );
        $stmt->execute([':t' => $in['token']]);
        if ($call = $stmt->fetch()) {
            $payload['call_id']    = (int)$call['id'];
            $payload['kind']       = 'customer';
            $payload['account_id'] = $payload['account_id'] ?? (int)$call['provider_account_id'];
            $payload['name']       = $payload['name']  ?: $call['customer_name'];
            $payload['phone']      = $payload['phone'] ?: $call['customer_phone'];
            $payload['email']      = $payload['email'] ?: ($call['customer_email'] ?? '');
            $payload['subject']    = $payload['subject'] ?: ('Job ' . $call['call_number']);
        }
    }

    $res = createSupportTicket($payload);
    if (!$res['ok']) errorResponse($res['error']);

    successResponse(
        ['ref' => $res['ref'], 'emailed' => $res['emailed']],
        t('ok.support_sent', ['ref' => $res['ref']])
    );
}

if ($method === 'GET' && ($action === 'mine' || $action === '')) {
    $user = requireAuth();
    $stmt = getDB()->prepare(
        "SELECT ticket_ref, subject, message, status, admin_reply, replied_at, created_at
           FROM support_tickets
          WHERE account_id = :a
          ORDER BY id DESC LIMIT 50"
    );
    $stmt->execute([':a' => $user['account_id']]);
    successResponse(['tickets' => $stmt->fetchAll()]);
}

errorResponse('Unknown action', 404);
