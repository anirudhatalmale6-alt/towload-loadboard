<?php
require_once __DIR__ . '/../includes/helpers.php';

// ═══════════════════════════════════════════════════════════════════════════
//  WHO IS ON THE SITE RIGHT NOW
//
//    POST /api/presence/ping   {page}   — one heartbeat per browser
//
//  Public, because most of the people worth seeing are stranded motorists who
//  have no account and never will. The browser sends a random key it made up
//  and keeps; that key exists only so two tabs from one person do not read as
//  two people, and it is not tied to anything across other sites.
//
//  One row per browser, overwritten each beat. An append-only log would be
//  millions of rows to answer a question about the last sixty seconds.
// ═══════════════════════════════════════════════════════════════════════════

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'POST' && ($action === 'ping' || $action === '')) {
    if ((string)setting('presence_enabled', '1') !== '1') successResponse(['off' => true]);

    $in  = jsonInput();
    $key = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($in['key'] ?? ''));
    if (strlen($key) < 8 || strlen($key) > 64) errorResponse('bad key', 400);

    // Who they are, taken from the token if they have one — never from the body.
    $kind = 'anon';
    $accountId = null;
    $label = null;

    $token  = bearerToken();
    $claims = $token ? verifyJWT($token) : null;
    if ($claims && !empty($claims['account_id'])) {
        $stmt = getDB()->prepare("SELECT name, account_type FROM accounts WHERE id = :a");
        $stmt->execute([':a' => (int)$claims['account_id']]);
        if ($row = $stmt->fetch()) {
            $accountId = (int)$claims['account_id'];
            $label     = $row['name'];
            $kind      = $row['account_type'] === 'tower' ? 'tower'
                       : ($row['account_type'] === 'consumer' ? 'customer' : 'customer');
        }
    } elseif ($claims && ($claims['kind'] ?? '') === 'admin') {
        $kind = 'admin';
    }

    // A visitor with no login on a customer page is still a customer — that is
    // the whole point of the screen this feeds.
    $page = mb_substr((string)($in['page'] ?? ''), 0, 120);
    if ($kind === 'anon' && $page !== '' && strpos($page, 'operator') !== 0) $kind = 'customer';

    getDB()->prepare(
        "INSERT INTO presence (session_key, account_id, kind, page, label, ip, user_agent, referrer, last_seen)
         VALUES (:k, :a, :kind, :p, :l, :ip, :ua, :ref, NOW())
         ON DUPLICATE KEY UPDATE
            account_id = VALUES(account_id), kind = VALUES(kind), page = VALUES(page),
            label = VALUES(label), ip = VALUES(ip), last_seen = NOW()"
    )->execute([
        ':k'    => $key,
        ':a'    => $accountId,
        ':kind' => $kind,
        ':p'    => $page ?: null,
        ':l'    => $label,
        ':ip'   => mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        ':ua'   => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
        ':ref'  => !empty($in['ref']) ? mb_substr((string)$in['ref'], 0, 255) : null,
    ]);

    // Cheap opportunistic cleanup — roughly one request in fifty pays for it,
    // so there is no cron to forget about and the table cannot grow forever.
    if (random_int(1, 50) === 1) {
        $hours = max(1, (int)setting('presence_retain_hours', 24));
        getDB()->prepare("DELETE FROM presence WHERE last_seen < DATE_SUB(NOW(), INTERVAL :h HOUR)")
               ->execute([':h' => $hours]);
    }

    successResponse(['ok' => true]);
}

errorResponse('Unknown action', 404);
