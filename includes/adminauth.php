<?php
require_once __DIR__ . '/helpers.php';

// ═══════════════════════════════════════════════════════════════════════════
//  PLATFORM ADMIN AUTH
//
//  Deliberately a separate token type from the marketplace one. Both are
//  signed with the same secret, so the `kind` claim is what stops a towing
//  company's perfectly valid login token from being replayed against the
//  admin API — without it, "is this a real signature" and "is this an admin"
//  become the same question, and they must not be.
// ═══════════════════════════════════════════════════════════════════════════

function generateAdminJWT(int $adminId, string $role): string {
    return generateJWT(['kind' => 'admin', 'admin_id' => $adminId, 'admin_role' => $role]);
}

function requireAdmin(array $roles = []): array {
    $token = bearerToken();
    if (!$token) errorResponse(t('err.auth_required'), 401);

    $claims = verifyJWT($token);
    if (!$claims || ($claims['kind'] ?? '') !== 'admin') {
        errorResponse(t('err.token_invalid'), 401);
    }

    $stmt = getDB()->prepare("SELECT * FROM admin_users WHERE id = :id AND is_active = 1");
    $stmt->execute([':id' => $claims['admin_id'] ?? 0]);
    $admin = $stmt->fetch();
    if (!$admin) errorResponse(t('err.account_disabled'), 401);

    // Role is re-read from the row, never trusted from the token — demoting
    // someone has to take effect immediately, not when their token expires.
    if ($roles && !in_array($admin['role'], $roles, true)) {
        errorResponse(t('err.no_permission'), 403);
    }

    unset($admin['password_hash']);
    return $admin;
}

/** True when the caller holds a valid admin token. Never errors. */
function isAdminRequest(): bool {
    $token = bearerToken();
    if (!$token) return false;
    $claims = verifyJWT($token);
    if (!$claims || ($claims['kind'] ?? '') !== 'admin') return false;
    $stmt = getDB()->prepare("SELECT id FROM admin_users WHERE id = :id AND is_active = 1");
    $stmt->execute([':id' => $claims['admin_id'] ?? 0]);
    return (bool)$stmt->fetch();
}

function adminLog(int $adminId, string $action, string $detail): void {
    // Piggybacks on call_events' sibling table rather than adding another:
    // notifications is the wrong home, so this writes a plain audit row.
    try {
        getDB()->prepare(
            "INSERT INTO admin_audit (admin_id, action, detail, ip_address)
             VALUES (:a, :ac, :d, :ip)"
        )->execute([
            ':a' => $adminId, ':ac' => $action, ':d' => substr($detail, 0, 500),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        // Never let audit logging break the action it is describing.
    }
}
