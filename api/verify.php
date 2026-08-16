<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/matching.php';

// ═══════════════════════════════════════════════════════════════════════════
//  EMAIL + PHONE VERIFICATION
//
//  A towing company confirms both before it can be dispatched a job. The phone
//  number is the one handed to a stranded customer at the roadside, so this is
//  not paperwork — an unreachable number is a customer standing on a hard
//  shoulder with a name and no way to use it.
//
//    POST /api/verify/send     {channel}          -> sends a 6-digit code
//    POST /api/verify/confirm  {channel, code}    -> marks the channel verified
//    GET  /api/verify/status                      -> what is left to do
// ═══════════════════════════════════════════════════════════════════════════

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

const CODE_TTL_MINUTES   = 15;
const MAX_ATTEMPTS       = 5;   // per code, before it is burned
const MAX_SENDS_PER_HOUR = 5;   // per account per channel

/**
 * The destination for a channel, read live from the account rather than from
 * the request. A caller must never get to choose where a verification code is
 * delivered — that turns this endpoint into a way to send texts to strangers.
 */
function verifyDestination(array $account, string $channel): string {
    return $channel === 'email'
        ? trim((string)$account['email'])
        : trim((string)$account['phone']);
}

function loadAccount(int $accountId): array {
    $stmt = getDB()->prepare(
        "SELECT id, email, phone, email_verified_at, email_verified_value,
                phone_verified_at, phone_verified_value
           FROM accounts WHERE id = :a"
    );
    $stmt->execute([':a' => $accountId]);
    $a = $stmt->fetch();
    if (!$a) errorResponse(t('err.account_not_found'), 404);
    return $a;
}

// ═══ SEND ════════════════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'send') {
    $user = requireAuth();
    requireAccountType($user, 'tower');
    requireRole($user, ['owner', 'dispatcher']);

    $in      = jsonInput();
    $channel = $in['channel'] ?? '';
    if (!in_array($channel, ['email', 'phone'], true)) errorResponse(t('err.bad_channel'));

    $account = loadAccount((int)$user['account_id']);
    $dest    = verifyDestination($account, $channel);
    if ($dest === '') errorResponse(t('err.no_' . $channel . '_on_file'));

    if (verifiedFor($account, $channel)) {
        successResponse(['already' => true], t('ok.already_verified'));
    }
    if ($channel === 'phone' && !smsConfigured()) {
        errorResponse(t('err.sms_unavailable'), 503);
    }

    $pdo = getDB();

    // Counted on sends, not on successes. A limit that only increments when
    // delivery works is no limit at all the moment the carrier starts failing —
    // that is exactly when something would hammer it hardest.
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) n FROM verification_codes
          WHERE account_id = :a AND channel = :c AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
    );
    $stmt->execute([':a' => $account['id'], ':c' => $channel]);
    if ((int)$stmt->fetch()['n'] >= MAX_SENDS_PER_HOUR) {
        errorResponse(t('err.too_many_codes'), 429);
    }

    // Any earlier live code for this channel stops working now. Two valid codes
    // at once means the one that arrives second looks broken.
    $pdo->prepare(
        "UPDATE verification_codes SET consumed_at = NOW()
          WHERE account_id = :a AND channel = :c AND consumed_at IS NULL"
    )->execute([':a' => $account['id'], ':c' => $channel]);

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $pdo->prepare(
        "INSERT INTO verification_codes (account_id, channel, destination, code_hash, expires_at)
         VALUES (:a, :c, :d, :h, DATE_ADD(NOW(), INTERVAL :m MINUTE))"
    )->execute([
        ':a' => $account['id'], ':c' => $channel, ':d' => $dest,
        ':h' => password_hash($code, PASSWORD_DEFAULT),
        ':m' => CODE_TTL_MINUTES,
    ]);

    if ($channel === 'phone') {
        $res = sendSms($dest, t('sms.code', ['code' => $code, 'mins' => CODE_TTL_MINUTES]));
    } else {
        $res = sendMail(
            $dest,
            t('mail.code_subject', ['code' => $code]),
            t('mail.code_body', ['code' => $code, 'mins' => CODE_TTL_MINUTES])
        );
    }

    if (!$res['ok']) {
        // The row stays. It already counted against the hourly limit and
        // deleting it would let a failing channel be retried without bound.
        errorResponse(t('err.send_failed_' . $channel), 502);
    }

    successResponse([
        'channel'    => $channel,
        // Masked so the operator can tell WHICH address or number it went to
        // without this being a way to read the record back out.
        'sent_to'    => maskDestination($dest, $channel),
        'expires_in' => CODE_TTL_MINUTES * 60,
    ], t('ok.code_sent_' . $channel));
}

/** j***@company.com  /  (•••) •••-1234 */
function maskDestination(string $dest, string $channel): string {
    if ($channel === 'phone') {
        $digits = preg_replace('/\D+/', '', $dest);
        return '•••-•••-' . substr($digits, -4);
    }
    $at = strpos($dest, '@');
    if ($at === false || $at < 1) return '•••';
    return substr($dest, 0, 1) . str_repeat('•', max(1, $at - 1)) . substr($dest, $at);
}

// ═══ CONFIRM ═════════════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'confirm') {
    $user = requireAuth();
    requireAccountType($user, 'tower');
    requireRole($user, ['owner', 'dispatcher']);

    $in      = jsonInput();
    $channel = $in['channel'] ?? '';
    $entered = preg_replace('/\D+/', '', (string)($in['code'] ?? ''));
    if (!in_array($channel, ['email', 'phone'], true)) errorResponse(t('err.bad_channel'));
    if ($entered === '') errorResponse(t('err.code_required'));

    $account = loadAccount((int)$user['account_id']);
    $pdo     = getDB();

    $stmt = $pdo->prepare(
        "SELECT * FROM verification_codes
          WHERE account_id = :a AND channel = :c AND consumed_at IS NULL
          ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([':a' => $account['id'], ':c' => $channel]);
    $row = $stmt->fetch();

    if (!$row)                                       errorResponse(t('err.code_none'), 410);
    if (strtotime($row['expires_at']) < time())      errorResponse(t('err.code_expired'), 410);
    if ((int)$row['attempts'] >= MAX_ATTEMPTS)       errorResponse(t('err.code_burned'), 429);

    // The destination is re-read from the account, so a code sent to the old
    // number cannot be used to mark a newly-typed number as verified.
    if ($row['destination'] !== verifyDestination($account, $channel)) {
        errorResponse(t('err.destination_changed'), 409);
    }

    $pdo->prepare("UPDATE verification_codes SET attempts = attempts + 1 WHERE id = :id")
        ->execute([':id' => $row['id']]);

    if (!password_verify($entered, $row['code_hash'])) {
        $left = MAX_ATTEMPTS - ((int)$row['attempts'] + 1);
        errorResponse($left > 0 ? t('err.code_wrong', ['n' => $left]) : t('err.code_burned'), 401);
    }

    $pdo->prepare("UPDATE verification_codes SET consumed_at = NOW() WHERE id = :id")
        ->execute([':id' => $row['id']]);

    $col = $channel === 'email' ? 'email' : 'phone';
    $pdo->prepare(
        "UPDATE accounts
            SET {$col}_verified_at = NOW(), {$col}_verified_value = :d
          WHERE id = :a"
    )->execute([':d' => $row['destination'], ':a' => $account['id']]);

    successResponse([
        'channel' => $channel,
        'steps'   => towerVerificationSteps((int)$account['id']),
    ], t('ok.verified_' . $channel));
}

// ═══ STATUS ══════════════════════════════════════════════════════════════════
if ($method === 'GET' && ($action === 'status' || $action === '')) {
    $user = requireAuth();
    requireAccountType($user, 'tower');

    $account = loadAccount((int)$user['account_id']);
    successResponse([
        'steps'          => towerVerificationSteps((int)$account['id']),
        'email'          => maskDestination((string)$account['email'], 'email'),
        'phone'          => $account['phone'] ? maskDestination((string)$account['phone'], 'phone') : null,
        'sms_available'  => smsConfigured(),
    ]);
}

errorResponse('Unknown action', 404);
