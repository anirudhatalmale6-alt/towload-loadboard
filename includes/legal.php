<?php
require_once __DIR__ . '/helpers.php';

// ═══════════════════════════════════════════════════════════════════════════
//  TERMS OF SERVICE — acceptance you can actually prove
//
//  "They ticked the box" is worth nothing six months later. What survives a
//  chargeback, a small-claims filing or a regulator's question is: which
//  version of which document, at what time, from which address, in which
//  language. So an acceptance is an immutable row, never an updated flag on
//  the account.
//
//  Versioning matters for the same reason. When the terms change, old
//  acceptances still point at the text that was actually agreed to — the
//  document rows are kept, not overwritten.
// ═══════════════════════════════════════════════════════════════════════════

function currentTermsVersion(): string {
    return (string)setting('terms_version', '1.0');
}

function termsRequired(): bool {
    return (string)setting('require_terms_accept', '1') === '1';
}

/**
 * Record an acceptance. Called at the exact moment the box was ticked and the
 * form submitted — never backfilled, never inferred.
 */
function recordAcceptance(string $docKey, ?int $accountId = null, ?int $userId = null,
                          ?int $callId = null): void {
    getDB()->prepare(
        "INSERT INTO agreement_acceptances
            (account_id, user_id, call_id, doc_key, version, locale, ip_address, user_agent)
         VALUES (:a, :u, :c, :k, :v, :l, :ip, :ua)"
    )->execute([
        ':a' => $accountId, ':u' => $userId, ':c' => $callId,
        ':k' => $docKey, ':v' => currentTermsVersion(), ':l' => currentLang(),
        ':ip' => clientIp(),
        // Truncated to the column width rather than left to be silently cut by
        // MySQL, which in strict mode would reject the whole insert and fail a
        // signup over a long browser string.
        ':ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400),
    ]);
}

function hasAccepted(int $accountId, string $docKey, ?string $version = null): bool {
    $version = $version ?? currentTermsVersion();
    $stmt = getDB()->prepare(
        "SELECT id FROM agreement_acceptances
          WHERE account_id = :a AND doc_key = :k AND version = :v LIMIT 1"
    );
    $stmt->execute([':a' => $accountId, ':k' => $docKey, ':v' => $version]);
    return (bool)$stmt->fetch();
}

/**
 * The live text of a document, in the requested language, falling back to
 * Spanish and then to any locale rather than returning nothing. An empty terms
 * page is worse than one in the wrong language.
 */
function getLegalDoc(string $docKey, ?string $locale = null): ?array {
    $locale = $locale ?: currentLang();
    $pdo = getDB();

    $stmt = $pdo->prepare(
        "SELECT * FROM legal_documents
          WHERE doc_key = :k AND locale = :l AND is_current = 1
          ORDER BY effective_at DESC LIMIT 1"
    );
    $stmt->execute([':k' => $docKey, ':l' => $locale]);
    if ($doc = $stmt->fetch()) return $doc;

    $stmt->execute([':k' => $docKey, ':l' => 'es']);
    if ($doc = $stmt->fetch()) return $doc;

    $stmt = $pdo->prepare(
        "SELECT * FROM legal_documents WHERE doc_key = :k AND is_current = 1
          ORDER BY effective_at DESC LIMIT 1"
    );
    $stmt->execute([':k' => $docKey]);
    return $stmt->fetch() ?: null;
}

/**
 * The real client address, taking the proxy header only when it is present —
 * DreamHost sits behind a load balancer and REMOTE_ADDR alone would record the
 * same internal address for every acceptance, which is exactly as useless as
 * recording nothing.
 */
function clientIp(): string {
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        isset($_SERVER['HTTP_X_FORWARDED_FOR'])
            ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]) : null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ];
    foreach ($candidates as $ip) {
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return '0.0.0.0';
}
