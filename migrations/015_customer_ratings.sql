-- ═══════════════════════════════════════════════════════════════════════════
--  015 — Let the customer rate the towing company
--
--  The `ratings` table has existed since the first schema and has never had a
--  single row written to it. accounts.rating_avg is READ in seven places — the
--  job board, the bid list, the admin company list, the customer's tracking
--  page — so every company on the platform has been showing 0.00 to customers
--  deciding whether to hand over their car.
--
--  The blocker was structural: rater_account_id is NOT NULL, and a stranded
--  motorist has no account. They are identified by the tracking token in the
--  link texted to them, and nothing else.
-- ═══════════════════════════════════════════════════════════════════════════

-- ─── who left it ───────────────────────────────────────────────────────────
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ratings'
      AND COLUMN_NAME = 'rater_kind') = 0,
  "ALTER TABLE ratings ADD COLUMN rater_kind ENUM('account','customer') NOT NULL DEFAULT 'account' AFTER call_id",
  'SELECT 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- A customer has no account id, so the column has to accept NULL.
SET @sql := (SELECT IF(
  (SELECT IS_NULLABLE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ratings'
      AND COLUMN_NAME = 'rater_account_id') = 'NO',
  'ALTER TABLE ratings MODIFY COLUMN rater_account_id INT NULL',
  'SELECT 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ─── one rating per rater per job ──────────────────────────────────────────
-- The old unique key was (call_id, rater_account_id). MySQL treats every NULL
-- as distinct in a unique index, so the moment rater_account_id became nullable
-- that key stopped preventing anything for customers — one person could rate
-- the same job repeatedly and move a company's average on their own.
--
-- rater_key is that identity made explicit: 'cust' for the customer on the job,
-- 'acct:12' for an account.
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ratings'
      AND COLUMN_NAME = 'rater_key') = 0,
  "ALTER TABLE ratings ADD COLUMN rater_key VARCHAR(64) NOT NULL DEFAULT 'acct:0' AFTER rater_account_id",
  'SELECT 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Backfill before the unique index goes on, or existing rows collide on the
-- default. (There are none today, but this file must be safe to run anywhere.)
UPDATE ratings SET rater_key = CONCAT('acct:', rater_account_id)
 WHERE rater_account_id IS NOT NULL AND rater_key = 'acct:0';

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ratings'
      AND INDEX_NAME = 'uniq_call_rater') > 0,
  'ALTER TABLE ratings DROP INDEX uniq_call_rater',
  'SELECT 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ratings'
      AND INDEX_NAME = 'uniq_call_raterkey') = 0,
  'ALTER TABLE ratings ADD UNIQUE KEY uniq_call_raterkey (call_id, rater_key)',
  'SELECT 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ─── settings ──────────────────────────────────────────────────────────────
INSERT INTO platform_settings (setting_key, setting_value, description)
VALUES
  ('ratings_enabled', '1', 'Customers can rate the towing company after a completed job.'),
  ('rating_window_days', '14', 'How long after completion a customer can still leave a rating.')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
