<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/adminauth.php';
// DOC_TYPES, DOCS_NEEDING_EXPIRY, requiredDocTypes(), docChecklist(). Shared so
// the dashboard banner reaches the same verdict this endpoint does.
require_once __DIR__ . '/../includes/compliance.php';

// ═══════════════════════════════════════════════════════════════════════════
//  COMPLIANCE DOCUMENTS
//
//  A towing company uploads EIN letter, state registration, certificate of
//  insurance and the owner's ID. Nothing they upload is public and nothing is
//  linked directly — see includes/uploads.php for why.
//
//  The account moves itself to Pending Review once the full set is in. Ricardo
//  should not have to notice that someone finished uploading; the queue should
//  fill on its own.
// ═══════════════════════════════════════════════════════════════════════════

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// The `file` action streams a document and must not send JSON headers first.
if ($action !== 'file') setCorsHeaders();

// ═══ UPLOAD ══════════════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'upload') {
    $user = requireAuth();
    requireAccountType($user, 'tower');
    requireRole($user, ['owner', 'dispatcher']);

    $docType = $_POST['doc_type'] ?? '';
    if (!in_array($docType, DOC_TYPES, true)) errorResponse(t('err.doc_type_unknown'));

    if (in_array($docType, DOCS_NEEDING_EXPIRY, true) && empty($_POST['expires_at'])) {
        errorResponse(t('err.doc_expiry_required'));
    }

    $stored = storeComplianceFile($_FILES['file'] ?? [], (int)$user['account_id']);
    if (empty($stored['ok'])) errorResponse($stored['error'], 400);

    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        // Re-uploading a document type supersedes the previous one rather than
        // stacking. A tower who uploads the right COI after a rejection should
        // not still be blocked by the rejected row sitting underneath it.
        $pdo->prepare(
            "UPDATE compliance_docs SET status = 'expired'
              WHERE account_id = :a AND doc_type = :t AND status IN ('pending','rejected')"
        )->execute([':a' => $user['account_id'], ':t' => $docType]);

        $pdo->prepare(
            "INSERT INTO compliance_docs
                (account_id, doc_type, file_url, stored_path, file_name, mime_type, file_size,
                 uploaded_by_user_id, policy_number, carrier_name, coverage_amount,
                 issued_at, expires_at, status)
             VALUES (:a, :t, '', :p, :fn, :m, :sz, :u, :pn, :cn, :ca, :ia, :ea, 'pending')"
        )->execute([
            ':a' => $user['account_id'], ':t' => $docType, ':p' => $stored['path'],
            ':fn' => substr((string)($_FILES['file']['name'] ?? 'document'), 0, 255),
            ':m' => $stored['mime'], ':sz' => $stored['size'], ':u' => $user['id'],
            ':pn' => $_POST['policy_number'] ?? null,
            ':cn' => $_POST['carrier_name'] ?? null,
            ':ca' => !empty($_POST['coverage_amount']) ? (float)$_POST['coverage_amount'] : null,
            ':ia' => !empty($_POST['issued_at']) ? $_POST['issued_at'] : null,
            ':ea' => !empty($_POST['expires_at']) ? $_POST['expires_at'] : null,
        ]);
        $docId = (int)$pdo->lastInsertId();

        $submitted = maybeSubmitForReview($pdo, (int)$user['account_id']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        errorResponse(t('err.upload_failed'), 500);
    }

    successResponse([
        'doc_id'             => $docId,
        'doc_type'           => $docType,
        'status'             => 'pending',
        'submitted_for_review' => $submitted,
        'checklist'          => docChecklist((int)$user['account_id']),
    ], $submitted ? t('ok.docs_submitted') : t('ok.doc_uploaded'));
}

/**
 * Flip the account into the review queue once every required document is in.
 * Returns true if this upload was the one that completed the set.
 */
function maybeSubmitForReview(PDO $pdo, int $accountId): bool {
    if ((string)setting('auto_submit_for_review', '1') !== '1') return false;

    $stmt = $pdo->prepare("SELECT verification_status FROM accounts WHERE id = :a");
    $stmt->execute([':a' => $accountId]);
    $status = $stmt->fetch()['verification_status'] ?? 'unverified';
    // Never drag an approved account backwards, and never resubmit one that is
    // already sitting in the queue.
    if (!in_array($status, ['unverified', 'rejected'], true)) return false;

    foreach (docChecklist($accountId) as $item) {
        if (!$item['uploaded']) return false;
    }

    $pdo->prepare(
        "UPDATE accounts
            SET verification_status = 'pending', docs_submitted_at = NOW(), rejection_reason = NULL
          WHERE id = :a"
    )->execute([':a' => $accountId]);
    return true;
}

// ═══ MY DOCUMENTS ════════════════════════════════════════════════════════════
if ($method === 'GET' && ($action === 'mine' || $action === '')) {
    $user = requireAuth();

    $stmt = getDB()->prepare(
        "SELECT id, doc_type, file_name, mime_type, file_size, policy_number, carrier_name,
                coverage_amount, issued_at, expires_at, status, review_notes, reviewed_at, created_at
           FROM compliance_docs
          WHERE account_id = :a AND status <> 'expired'
          ORDER BY created_at DESC"
    );
    $stmt->execute([':a' => $user['account_id']]);
    $docs = $stmt->fetchAll();
    foreach ($docs as &$d) $d['label'] = t('doc.' . $d['doc_type']);
    unset($d);

    successResponse([
        'documents'           => $docs,
        'checklist'           => docChecklist((int)$user['account_id']),
        'verification_status' => $user['verification_status'],
        // What the DOCUMENTS actually say, which is a different question from
        // what the ACCOUNT says.
        //
        // A new tower account is created with verification_status = 'pending'
        // (api/auth.php register), and maybeSubmitForReview() also sets
        // 'pending' once the last document lands. So that one word covers both
        // "brand new, nothing uploaded" and "everything is in, waiting on a
        // human" — and a client that keys its banner off it tells a company
        // which has uploaded nothing that nothing more is needed from them.
        // They then stop, never get approved, and blame the platform when no
        // work arrives.
        //
        // docsState() answers from the checklist itself:
        // missing | rejected | expired | pending | approved.
        'docs_state'          => docsState((int)$user['account_id']),
        'rejection_reason'    => (function () use ($user) {
            $s = getDB()->prepare("SELECT rejection_reason FROM accounts WHERE id = :a");
            $s->execute([':a' => $user['account_id']]);
            return $s->fetch()['rejection_reason'] ?? null;
        })(),
    ]);
}

// ═══ SERVE A FILE ════════════════════════════════════════════════════════════
// The only route to a stored document. Either you are an admin, or you are the
// account that uploaded it. There is no third case and no public URL.
if ($method === 'GET' && $action === 'file') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) { http_response_code(404); exit; }

    $stmt = getDB()->prepare("SELECT * FROM compliance_docs WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $doc = $stmt->fetch();
    if (!$doc) { http_response_code(404); exit; }

    $allowed = false;
    if (isAdminRequest()) {
        $allowed = true;
    } else {
        $token = bearerToken();
        $claims = $token ? verifyJWT($token) : null;
        if ($claims && ($claims['kind'] ?? '') !== 'admin'
            && (int)($claims['account_id'] ?? 0) === (int)$doc['account_id']) {
            $allowed = true;
        }
    }
    if (!$allowed) { http_response_code(403); exit; }

    $path = complianceFilePath($doc['stored_path']);
    if (!$path) { http_response_code(404); exit; }

    header('Content-Type: ' . ($doc['mime_type'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    // inline so an admin can eyeball a licence without downloading it, but
    // never cached by a shared proxy.
    header('Content-Disposition: inline; filename="' .
           preg_replace('/[^A-Za-z0-9._-]/', '_', $doc['file_name'] ?: 'document') . '"');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

// ═══ DELETE ══════════════════════════════════════════════════════════════════
if (($method === 'DELETE' || $action === 'delete') && $method !== 'GET') {
    $user = requireAuth();
    requireRole($user, ['owner']);
    $in = jsonInput();
    $id = (int)($in['id'] ?? $_GET['id'] ?? 0);

    $stmt = getDB()->prepare("SELECT * FROM compliance_docs WHERE id = :id AND account_id = :a");
    $stmt->execute([':id' => $id, ':a' => $user['account_id']]);
    $doc = $stmt->fetch();
    if (!$doc) errorResponse(t('err.doc_not_found'), 404);

    // An approved document is evidence that the account was vetted. It stays.
    if ($doc['status'] === 'approved') errorResponse(t('err.doc_approved_locked'), 409);

    if ($path = complianceFilePath($doc['stored_path'])) @unlink($path);
    getDB()->prepare("DELETE FROM compliance_docs WHERE id = :id")->execute([':id' => $id]);

    successResponse(['checklist' => docChecklist((int)$user['account_id'])], t('ok.doc_deleted'));
}

errorResponse('Unknown action', 404);
