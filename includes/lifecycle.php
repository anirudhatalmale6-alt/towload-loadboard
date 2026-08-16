<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/uploads.php';

// ═══════════════════════════════════════════════════════════════════════════
//  DISABLING, DELETING AND ANONYMISING ACCOUNTS
//
//  One implementation, used by the admin panel and by the two self-service
//  "delete my account" buttons. Three copies of this logic would be three
//  chances to forget the money check.
//
//  The money check is the whole point. Nearly every table hangs off accounts
//  with ON DELETE CASCADE — ledger_entries, payouts, provider_balances, and
//  calls where the account was the provider. So deleting an account is not a
//  narrow act: MySQL will quietly take the financial record with it. Deleting
//  a company that is still owed money, or whose customer still has an
//  authorised card hold, destroys the only record that the debt existed.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Everything that would be destroyed, and every reason not to.
 * Read this before offering a delete button, and again before honouring it.
 */
function deletionImpact(int $accountId): array {
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE id = :a");
    $stmt->execute([':a' => $accountId]);
    $acct = $stmt->fetch();
    if (!$acct) return ['found' => false];

    // Two placeholders, always bound, because several of these queries have to
    // ask about the account on BOTH sides of a job.
    //
    // This connection runs with EMULATE_PREPARES = false, and real prepared
    // statements reject the same named placeholder appearing twice in one
    // statement. Reusing :a in an OR was a fatal that produced an empty
    // response body — the browser reported it as "Unexpected end of JSON
    // input", which points at the client and not at the SQL.
    $one = function (string $sql) use ($pdo, $accountId) {
        $params = [':a' => $accountId];
        // Bound only when the statement actually uses it. Passing a parameter
        // the query does not contain is itself an error under real prepares
        // ("Invalid parameter number"), so this cannot simply always bind both.
        if (strpos($sql, ':a2') !== false) $params[':a2'] = $accountId;

        $s = $pdo->prepare($sql);
        $s->execute($params);
        $r = $s->fetch();
        return (float)($r['n'] ?? 0);
    };

    $isTower = $acct['account_type'] === 'tower';

    $counts = [
        'jobs_as_tower'    => (int)$one("SELECT COUNT(*) n FROM calls WHERE awarded_tower_account_id = :a"),
        'jobs_as_customer' => (int)$one("SELECT COUNT(*) n FROM calls WHERE provider_account_id = :a"),
        'completed_jobs'   => (int)$one("SELECT COUNT(*) n FROM calls
                                          WHERE (awarded_tower_account_id = :a OR provider_account_id = :a2)
                                            AND status = 'completed'"),
        'ledger_entries'   => (int)$one("SELECT COUNT(*) n FROM ledger_entries WHERE account_id = :a"),
        'payouts'          => (int)$one("SELECT COUNT(*) n FROM payouts WHERE tower_account_id = :a"),
        'documents'        => (int)$one("SELECT COUNT(*) n FROM compliance_docs WHERE account_id = :a"),
        'ratings_received' => (int)$one("SELECT COUNT(*) n FROM ratings WHERE rated_account_id = :a"),
        'trucks'           => (int)$one("SELECT COUNT(*) n FROM tower_trucks WHERE account_id = :a"),
        'users'            => (int)$one("SELECT COUNT(*) n FROM users WHERE account_id = :a"),
    ];

    // ─── Reasons this must not happen right now ────────────────────────────
    $blockers = [];

    // A job that has not finished has a customer attached to it, and often a
    // card hold. Deleting either side mid-job strands somebody.
    $live = (int)$one("SELECT COUNT(*) n FROM calls
                        WHERE (awarded_tower_account_id = :a OR provider_account_id = :a2)
                          AND status IN ('open','awarded','en_route','on_scene','in_progress')");
    if ($live > 0) $blockers[] = t('err.del_live_jobs', ['n' => $live]);

    // Money the platform still owes them.
    $pendingPayouts = $one("SELECT COALESCE(SUM(net_amount),0) n FROM payouts
                             WHERE tower_account_id = :a AND status = 'pending'");
    if ($pendingPayouts > 0) {
        $blockers[] = t('err.del_pending_payout', ['amount' => number_format($pendingPayouts, 2)]);
    }

    // Money of theirs the platform is holding.
    $bal = $pdo->prepare("SELECT available, held FROM provider_balances WHERE account_id = :a");
    $bal->execute([':a' => $accountId]);
    $b = $bal->fetch();
    $available = (float)($b['available'] ?? 0);
    $held      = (float)($b['held'] ?? 0);
    if ($available > 0.005) $blockers[] = t('err.del_balance', ['amount' => number_format($available, 2)]);
    if ($held > 0.005)      $blockers[] = t('err.del_held', ['amount' => number_format($held, 2)]);

    // An open dispute is a live claim; the evidence must not evaporate.
    // Listed positively rather than as NOT IN (...): the resolved states are
    // resolved_provider / resolved_tower / resolved_split, so a NOT IN list of
    // 'resolved','closed' matches every row and blocks deletions that are fine.
    $disputes = (int)$one("SELECT COUNT(*) n FROM disputes d
                            JOIN calls c ON c.id = d.call_id
                           WHERE (c.awarded_tower_account_id = :a OR c.provider_account_id = :a2)
                             AND d.status IN ('open','under_review')");
    if ($disputes > 0) $blockers[] = t('err.del_disputes', ['n' => $disputes]);

    // Having any financial history at all does not block deletion, but it does
    // change which mode is the sane default.
    $hasFinancialHistory = $counts['completed_jobs'] > 0
                        || $counts['ledger_entries'] > 0
                        || $counts['payouts'] > 0;

    return [
        'found'        => true,
        'account'      => [
            'id'    => (int)$acct['id'],
            'type'  => $acct['account_type'],
            'name'  => $acct['name'],
            'email' => $acct['email'],
            'phone' => $acct['phone'],
            'status'=> $acct['verification_status'],
            'is_active' => (int)$acct['is_active'],
            'disabled_reason' => $acct['disabled_reason'] ?? null,
            'anonymized_at'   => $acct['anonymized_at'] ?? null,
        ],
        'counts'       => $counts,
        'blockers'     => $blockers,
        'can_proceed'  => count($blockers) === 0,
        'has_financial_history' => $hasFinancialHistory,
        // What the UI should offer first. Suggesting a hard delete on a company
        // with real jobs behind it is offering to shrink his own books.
        'recommended'  => $hasFinancialHistory ? 'anonymized' : 'deleted',
        'balances'     => ['available' => $available, 'held' => $held],
    ];
}

/** Remove every uploaded compliance file for an account from disk. */
function purgeComplianceFiles(int $accountId): int {
    $stmt = getDB()->prepare("SELECT stored_path FROM compliance_docs WHERE account_id = :a");
    $stmt->execute([':a' => $accountId]);
    $n = 0;
    foreach ($stmt as $row) {
        // complianceFilePath() resolves and validates; never build the path by
        // hand here or a bad stored_path becomes an arbitrary unlink.
        if ($p = complianceFilePath($row['stored_path'])) { @unlink($p); $n++; }
    }
    return $n;
}

/**
 * Strip the person, keep the rows.
 *
 * Used when an account has financial history, and always for a self-service
 * request from someone who has traded on the platform. The company stops
 * existing as far as the product is concerned — it cannot sign in, cannot be
 * matched, cannot be contacted — while the completed jobs and their money stay
 * in the books, which is both the honest accounting answer and the one that
 * keeps him able to answer a chargeback six months from now.
 */
function anonymizeAccount(int $accountId, string $by, ?int $adminId, ?string $reason): array {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE id = :a");
    $stmt->execute([':a' => $accountId]);
    $acct = $stmt->fetch();
    if (!$acct) return ['ok' => false, 'error' => t('err.account_not_found')];

    $files = purgeComplianceFiles($accountId);

    $pdo->beginTransaction();
    try {
        $tag = ($acct['account_type'] === 'tower' ? 'Deleted company #' : 'Deleted customer #') . $accountId;

        $pdo->prepare(
            "UPDATE accounts SET
                name = :n, legal_name = :n2, ein = NULL,
                email = :e, phone = NULL, address = NULL, city = city, zip = NULL,
                website = NULL, logo_url = NULL,
                is_active = 0, verification_status = 'suspended',
                anonymized_at = NOW(),
                email_verified_at = NULL, email_verified_value = NULL,
                phone_verified_at = NULL, phone_verified_value = NULL,
                disabled_reason = :r, disabled_at = NOW()
              WHERE id = :a"
        )->execute([
            ':n' => $tag, ':n2' => $tag,
            // Kept unique and obviously dead, so it can never collide with a
            // real signup or be mistaken for a working address.
            ':e' => 'deleted+' . $accountId . '@towsling.invalid',
            ':r' => $reason !== null ? mb_substr($reason, 0, 500) : t('msg.account_closed'),
            ':a' => $accountId,
        ]);

        // The login has to go, or the account is still reachable.
        $pdo->prepare("DELETE FROM users WHERE account_id = :a")->execute([':a' => $accountId]);
        $pdo->prepare("DELETE FROM compliance_docs WHERE account_id = :a")->execute([':a' => $accountId]);
        $pdo->prepare("DELETE FROM push_subscriptions WHERE account_id = :a")->execute([':a' => $accountId]);
        $pdo->prepare("DELETE FROM verification_codes WHERE account_id = :a")->execute([':a' => $accountId]);
        $pdo->prepare("DELETE FROM tower_trucks WHERE account_id = :a")->execute([':a' => $accountId]);

        // Customer PII sitting on the jobs themselves, for a motorist.
        if ($acct['account_type'] === 'consumer') {
            $pdo->prepare(
                "UPDATE calls SET customer_name = 'Deleted customer', customer_phone = NULL,
                                  customer_email = NULL
                  WHERE provider_account_id = :a"
            )->execute([':a' => $accountId]);
        }

        recordDeletion($pdo, $acct, 'anonymized', $by, $adminId, $reason,
                       ['files_removed' => $files]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[lifecycle] anonymize failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => t('err.delete_failed')];
    }

    return ['ok' => true, 'mode' => 'anonymized', 'files_removed' => $files];
}

/**
 * Actually remove it. Everything.
 *
 * Only reachable when deletionImpact() reports no blockers, and from the admin
 * panel. The cascades do most of the work; the explicit deletes below cover the
 * tables that have no foreign key back to accounts and would otherwise be left
 * pointing at an id that no longer exists.
 */
function deleteAccountCompletely(int $accountId, ?int $adminId, ?string $reason): array {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE id = :a");
    $stmt->execute([':a' => $accountId]);
    $acct = $stmt->fetch();
    if (!$acct) return ['ok' => false, 'error' => t('err.account_not_found')];

    $impact = deletionImpact($accountId);
    if (!$impact['can_proceed']) {
        return ['ok' => false, 'error' => implode(' ', $impact['blockers'])];
    }

    $files = purgeComplianceFiles($accountId);
    $counts = $impact['counts'];
    $counts['files_removed'] = $files;

    $pdo->beginTransaction();
    try {
        // Jobs this account WORKED. There is no foreign key on
        // awarded_tower_account_id, so nothing would remove these — they would
        // survive as jobs belonging to a company id that no longer resolves,
        // and every admin list joining on it would quietly lose rows.
        $ids = [];
        $s = $pdo->prepare("SELECT id FROM calls WHERE awarded_tower_account_id = :a");
        $s->execute([':a' => $accountId]);
        foreach ($s as $r) $ids[] = (int)$r['id'];

        if ($ids) {
            $in = implode(',', $ids);
            // Children first — call_events/photos/locations cascade from calls,
            // but escrow_holds and ratings are cleared explicitly so the order
            // is visible here rather than implied by the schema.
            $pdo->exec("DELETE FROM ratings WHERE call_id IN ($in)");
            $pdo->exec("DELETE FROM escrow_holds WHERE call_id IN ($in)");
            $pdo->exec("DELETE FROM calls WHERE id IN ($in)");
        }

        // Ratings this company received on jobs that were not its own.
        $pdo->prepare("DELETE FROM ratings WHERE rated_account_id = :a OR rater_account_id = :a2")
            ->execute([':a' => $accountId, ':a2' => $accountId]);

        $pdo->prepare("DELETE FROM support_tickets WHERE account_id = :a")->execute([':a' => $accountId]);
        $pdo->prepare("DELETE FROM tower_rates WHERE account_id = :a")->execute([':a' => $accountId]);
        $pdo->prepare("DELETE FROM push_subscriptions WHERE account_id = :a")->execute([':a' => $accountId]);
        $pdo->prepare("DELETE FROM verification_codes WHERE account_id = :a")->execute([':a' => $accountId]);
        $pdo->prepare("DELETE FROM tower_trucks WHERE account_id = :a")->execute([':a' => $accountId]);

        recordDeletion($pdo, $acct, 'deleted', $adminId ? 'admin' : 'self', $adminId, $reason, $counts);

        // Everything else — users, profiles, docs, ledger, payouts, balances,
        // subscriptions, and calls where this account was the provider — goes
        // with this line, via ON DELETE CASCADE.
        $pdo->prepare("DELETE FROM accounts WHERE id = :a")->execute([':a' => $accountId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[lifecycle] delete failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => t('err.delete_failed')];
    }

    return ['ok' => true, 'mode' => 'deleted', 'removed' => $counts];
}

/** The tombstone. Written inside the caller's transaction, before the delete. */
function recordDeletion(PDO $pdo, array $acct, string $mode, string $by,
                        ?int $adminId, ?string $reason, array $counts): void {
    $pdo->prepare(
        "INSERT INTO account_deletions
            (account_id, account_type, account_name, account_email, mode,
             requested_by, admin_user_id, reason, removed_counts)
         VALUES (:id, :t, :n, :e, :m, :by, :adm, :r, :c)"
    )->execute([
        ':id' => $acct['id'], ':t' => $acct['account_type'],
        ':n' => $acct['name'], ':e' => $acct['email'],
        ':m' => $mode, ':by' => $by, ':adm' => $adminId,
        ':r' => $reason !== null ? mb_substr($reason, 0, 500) : null,
        ':c' => json_encode($counts),
    ]);
}

/**
 * Disable or re-enable a company, with a reason it can be shown.
 * A suspension nobody can explain to the person suspended is just an outage.
 */
function setAccountDisabled(int $accountId, bool $disabled, ?string $reason, ?int $adminId): array {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE id = :a");
    $stmt->execute([':a' => $accountId]);
    $acct = $stmt->fetch();
    if (!$acct) return ['ok' => false, 'error' => t('err.account_not_found')];

    if (!empty($acct['anonymized_at'])) {
        return ['ok' => false, 'error' => t('err.account_closed_permanently')];
    }

    if ($disabled) {
        if ($reason === null || trim($reason) === '') {
            return ['ok' => false, 'error' => t('err.disable_reason_required')];
        }
        $pdo->prepare(
            "UPDATE accounts SET is_active = 0, verification_status = 'suspended',
                                 disabled_reason = :r, disabled_at = NOW(), disabled_by_admin_id = :adm
              WHERE id = :a"
        )->execute([':r' => mb_substr(trim($reason), 0, 500), ':adm' => $adminId, ':a' => $accountId]);
    } else {
        // Back to 'pending', never straight to 'approved'. Re-enabling is
        // undoing a suspension, not re-issuing an approval — if the suspension
        // was for expired insurance, the documents still have to be looked at.
        $pdo->prepare(
            "UPDATE accounts SET is_active = 1, verification_status = 'pending',
                                 disabled_reason = NULL, disabled_at = NULL, disabled_by_admin_id = NULL
              WHERE id = :a"
        )->execute([':a' => $accountId]);
    }

    return ['ok' => true, 'disabled' => $disabled];
}
