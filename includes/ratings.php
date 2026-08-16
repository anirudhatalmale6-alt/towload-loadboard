<?php
require_once __DIR__ . '/helpers.php';

// ═══════════════════════════════════════════════════════════════════════════
//  RATINGS
//
//  accounts.rating_avg and rating_count are denormalised on purpose — the board
//  renders dozens of companies per request and must not aggregate the ratings
//  table each time. That makes recalculating them part of writing a rating, not
//  an optional extra: the number customers see lives in accounts, and a rating
//  that does not update it is a rating nobody will ever read.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Recompute a company's average from the ratings table.
 *
 * Recalculated from scratch rather than nudged with a running total. An
 * incremental average drifts the first time a row is edited or removed, and it
 * has no way to ever correct itself.
 */
function recalcRating(PDO $pdo, int $ratedAccountId): void {
    $pdo->prepare(
        "UPDATE accounts a
            SET a.rating_avg = COALESCE((SELECT ROUND(AVG(r.stars), 2) FROM ratings r
                                          WHERE r.rated_account_id = a.id), 0),
                a.rating_count = (SELECT COUNT(*) FROM ratings r
                                   WHERE r.rated_account_id = a.id)
          WHERE a.id = :id"
    )->execute([':id' => $ratedAccountId]);
}

/**
 * Can this completed job still be rated?
 * A window, because a rating left five months later is not about the job.
 */
function ratingWindowOpen(array $call): bool {
    if ((string)setting('ratings_enabled', '1') !== '1') return false;
    if ($call['status'] !== 'completed')                 return false;
    if (empty($call['completed_at']))                    return false;

    $days = max(1, (int)setting('rating_window_days', 14));
    return strtotime($call['completed_at']) > time() - ($days * 86400);
}

/** The rating already left by the customer on this job, if any. */
function customerRatingFor(int $callId): ?array {
    $stmt = getDB()->prepare(
        "SELECT stars, comment, created_at FROM ratings
          WHERE call_id = :c AND rater_key = 'cust' LIMIT 1"
    );
    $stmt->execute([':c' => $callId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    return ['stars' => (int)$row['stars'], 'comment' => $row['comment'], 'created_at' => $row['created_at']];
}

/**
 * Record the customer's rating of the towing company that did the job.
 * Returns ['ok' => bool, 'error' => string|null].
 */
function saveCustomerRating(array $call, int $stars, ?string $comment): array {
    if ($stars < 1 || $stars > 5)                 return ['ok' => false, 'error' => t('err.rating_range')];
    if (empty($call['awarded_tower_account_id'])) return ['ok' => false, 'error' => t('err.rating_no_tower')];
    if (!ratingWindowOpen($call))                 return ['ok' => false, 'error' => t('err.rating_closed')];

    $pdo   = getDB();
    $tower = (int)$call['awarded_tower_account_id'];

    $pdo->beginTransaction();
    try {
        // Insert-or-update on the unique (call_id, rater_key). A customer who
        // taps three stars and immediately realises they meant four should be
        // able to fix it, not be told they already rated.
        $pdo->prepare(
            "INSERT INTO ratings (call_id, rater_kind, rater_account_id, rater_key, rated_account_id, stars, comment)
             VALUES (:c, 'customer', NULL, 'cust', :rated, :s, :m)
             ON DUPLICATE KEY UPDATE stars = VALUES(stars), comment = VALUES(comment)"
        )->execute([
            ':c' => $call['id'], ':rated' => $tower, ':s' => $stars,
            ':m' => $comment !== null && $comment !== '' ? mb_substr($comment, 0, 1000) : null,
        ]);

        recalcRating($pdo, $tower);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[ratings] ' . $e->getMessage());
        return ['ok' => false, 'error' => t('err.rating_failed')];
    }

    return ['ok' => true, 'error' => null];
}
