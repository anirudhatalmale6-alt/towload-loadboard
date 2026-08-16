<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/notify.php';

// ═══════════════════════════════════════════════════════════════════════════
//  SUPPORT TICKETS
//
//  Every request is written to the database FIRST and emailed second. If the
//  mail fails, the ticket still exists and still shows up in the admin panel —
//  a support system whose only delivery mechanism is email loses the request
//  entirely on a bad day, and the person who wrote it has no way to know.
// ═══════════════════════════════════════════════════════════════════════════

function newTicketRef(): string {
    // Short, unambiguous, safe to read down a phone line. No 0/O or 1/I.
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < 6; $i++) $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return 'TS-' . $s;
}

/**
 * Create a ticket, store it, then try to email it on.
 * Returns ['ok' => bool, 'ref' => string, 'emailed' => bool, 'error' => ?string]
 */
function createSupportTicket(array $in): array {
    if ((string)setting('support_enabled', '1') !== '1') {
        return ['ok' => false, 'error' => t('err.support_off')];
    }

    $subject = trim((string)($in['subject'] ?? ''));
    $message = trim((string)($in['message'] ?? ''));
    $email   = trim((string)($in['email'] ?? ''));

    if ($subject === '')  return ['ok' => false, 'error' => t('err.support_subject')];
    if ($message === '')  return ['ok' => false, 'error' => t('err.support_message')];
    // Without a reply address the ticket is a dead end for both sides.
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => t('err.support_email')];
    }

    $pdo = getDB();

    // A ref collision is astronomically unlikely but the column is UNIQUE, so
    // a retry costs nothing and a 500 on a support form is a bad look.
    $ref = null;
    for ($try = 0; $try < 5 && $ref === null; $try++) {
        $candidate = newTicketRef();
        $s = $pdo->prepare("SELECT id FROM support_tickets WHERE ticket_ref = :r");
        $s->execute([':r' => $candidate]);
        if (!$s->fetch()) $ref = $candidate;
    }
    if ($ref === null) return ['ok' => false, 'error' => t('err.support_failed')];

    $pdo->prepare(
        "INSERT INTO support_tickets
            (ticket_ref, account_id, call_id, kind, name, email, phone, subject, message)
         VALUES (:ref, :a, :c, :k, :n, :e, :p, :s, :m)"
    )->execute([
        ':ref' => $ref,
        ':a'   => !empty($in['account_id']) ? (int)$in['account_id'] : null,
        ':c'   => !empty($in['call_id']) ? (int)$in['call_id'] : null,
        ':k'   => ($in['kind'] ?? 'customer') === 'tower' ? 'tower' : 'customer',
        ':n'   => !empty($in['name'])  ? mb_substr(trim($in['name']), 0, 150) : null,
        ':e'   => mb_substr($email, 0, 255),
        ':p'   => !empty($in['phone']) ? mb_substr(trim($in['phone']), 0, 30) : null,
        ':s'   => mb_substr($subject, 0, 200),
        ':m'   => mb_substr($message, 0, 20000),
    ]);
    $id = (int)$pdo->lastInsertId();

    $emailed = deliverTicketEmail($ref, $in, $subject, $message, $email);
    if ($emailed) {
        $pdo->prepare("UPDATE support_tickets SET emailed = 1 WHERE id = :id")->execute([':id' => $id]);
    }

    return ['ok' => true, 'ref' => $ref, 'id' => $id, 'emailed' => $emailed, 'error' => null];
}

function deliverTicketEmail(string $ref, array $in, string $subject, string $message, string $from): bool {
    $to = (string)setting('support_email', 'support@towsling.com');

    $who = ($in['kind'] ?? 'customer') === 'tower' ? 'Towing company' : 'Customer';
    $body = "New support request via TowSling\n"
          . str_repeat('-', 46) . "\n"
          . "Reference: $ref\n"
          . "From:      $who\n"
          . "Name:      " . ($in['name'] ?? '(not given)') . "\n"
          . "Email:     $from\n"
          . "Phone:     " . ($in['phone'] ?? '(not given)') . "\n"
          . (!empty($in['account_id']) ? "Account:   #" . (int)$in['account_id'] . "\n" : '')
          . (!empty($in['call_id'])    ? "Job:       #" . (int)$in['call_id'] . "\n" : '')
          . "Subject:   $subject\n"
          . str_repeat('-', 46) . "\n\n"
          . $message . "\n\n"
          . str_repeat('-', 46) . "\n"
          . "Reply to this person at: $from\n"
          . "Or answer it in the admin panel: " . APP_URL . "/admin\n";

    $res = sendMail($to, "[$ref] $subject", $body);
    if (!$res['ok']) {
        error_log('[support] could not email ticket ' . $ref . ': ' . ($res['error'] ?? '?'));
    }
    return !empty($res['ok']);
}

/** Send the admin's answer back to whoever raised it. */
function replyToTicket(int $ticketId, string $reply, int $adminId): array {
    $reply = trim($reply);
    if ($reply === '') return ['ok' => false, 'error' => t('err.support_message')];

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM support_tickets WHERE id = :id");
    $stmt->execute([':id' => $ticketId]);
    $tk = $stmt->fetch();
    if (!$tk) return ['ok' => false, 'error' => t('err.ticket_not_found')];

    $body = "Re: {$tk['subject']}\n"
          . "Reference: {$tk['ticket_ref']}\n\n"
          . $reply . "\n\n"
          . str_repeat('-', 46) . "\n"
          . "This is a reply to the support request you sent through TowSling.\n"
          . "You can reply straight to this email.\n";

    $sent = sendMail((string)$tk['email'], "Re: [{$tk['ticket_ref']}] {$tk['subject']}", $body);

    $pdo->prepare(
        "UPDATE support_tickets
            SET admin_reply = :r, replied_at = NOW(), replied_by_admin_id = :adm,
                status = 'answered'
          WHERE id = :id"
    )->execute([':r' => $reply, ':adm' => $adminId, ':id' => $ticketId]);

    // The reply is saved either way — losing what he typed because a mail
    // server was briefly down would be worse than a delivery he has to retry.
    return ['ok' => true, 'emailed' => !empty($sent['ok'])];
}
