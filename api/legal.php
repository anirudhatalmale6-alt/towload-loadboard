<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/legal.php';
setCorsHeaders();

// ═══════════════════════════════════════════════════════════════════════════
//  PUBLIC LEGAL DOCUMENTS
//  Read-only. The terms have to be reachable without logging in — a tower
//  deciding whether to sign up, and a customer deciding whether to confirm,
//  both need to read them before they have an account.
// ═══════════════════════════════════════════════════════════════════════════

$action = $_GET['action'] ?? 'doc';

if ($action === 'doc') {
    $map = [
        'customer' => 'terms_customer',
        'tower'    => 'terms_tower',
        'privacy'  => 'privacy',
    ];
    $key = $map[$_GET['key'] ?? 'customer'] ?? 'terms_customer';

    $doc = getLegalDoc($key);
    if (!$doc) errorResponse('Not found', 404);

    successResponse([
        'doc_key'      => $doc['doc_key'],
        'version'      => $doc['version'],
        'locale'       => $doc['locale'],
        'title'        => $doc['title'],
        'body'         => $doc['body'],
        'effective_at' => $doc['effective_at'],
    ]);
}

// The version a signup or a request must record. Kept separate so the front end
// can show "you are accepting v1.2" without pulling the whole document.
if ($action === 'version') {
    successResponse([
        'version'  => currentTermsVersion(),
        'required' => termsRequired(),
    ]);
}

errorResponse('Unknown action', 404);
