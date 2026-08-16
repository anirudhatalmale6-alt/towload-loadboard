-- ═══════════════════════════════════════════════════════════════════════════
--  017 — Account lifecycle (disable / delete) + customer support
--
--  Worth reading before touching the delete code: almost every table hangs off
--  accounts with ON DELETE CASCADE, including ledger_entries, payouts,
--  provider_balances and calls-where-they-were-the-provider. So a bare
--  `DELETE FROM accounts WHERE id = ?` already destroys the financial record
--  silently — no error, no warning, the books simply get smaller.
--
--  That is why deletion is gated on money being settled, and why there is an
--  anonymise path that keeps the rows and removes the person.
-- ═══════════════════════════════════════════════════════════════════════════

-- ─── disabling, with a reason the company can actually be told ─────────────
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accounts' AND COLUMN_NAME='disabled_reason')=0,
  'ALTER TABLE accounts ADD COLUMN disabled_reason VARCHAR(500) NULL AFTER rejection_reason',
  'SELECT 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accounts' AND COLUMN_NAME='disabled_at')=0,
  'ALTER TABLE accounts ADD COLUMN disabled_at DATETIME NULL AFTER disabled_reason',
  'SELECT 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accounts' AND COLUMN_NAME='disabled_by_admin_id')=0,
  'ALTER TABLE accounts ADD COLUMN disabled_by_admin_id INT NULL AFTER disabled_at',
  'SELECT 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ─── anonymised accounts ───────────────────────────────────────────────────
-- Set when the person has been removed but the rows they are attached to have
-- to stay. Such an account can never be signed into again.
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accounts' AND COLUMN_NAME='anonymized_at')=0,
  'ALTER TABLE accounts ADD COLUMN anonymized_at DATETIME NULL AFTER disabled_by_admin_id',
  'SELECT 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ─── what was removed, and by whom ─────────────────────────────────────────
-- Deliberately NOT foreign-keyed to accounts: the whole point is that it
-- outlives the row it describes. Without this, a deleted company leaves no
-- trace at all and "what happened to Kauffs?" has no answer.
CREATE TABLE IF NOT EXISTS account_deletions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    account_type VARCHAR(20) NOT NULL,
    account_name VARCHAR(255) NOT NULL,
    account_email VARCHAR(255) NULL,
    mode ENUM('deleted','anonymized') NOT NULL,
    -- 'admin' or 'self'
    requested_by ENUM('admin','self') NOT NULL,
    admin_user_id INT NULL,
    reason VARCHAR(500) NULL,
    -- A JSON count of what went with it, so the scale of a deletion is
    -- recoverable even though the rows are not.
    removed_counts TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_when (created_at)
) ENGINE=InnoDB;

-- ─── support ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS support_tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_ref VARCHAR(16) NOT NULL UNIQUE,

    -- Nullable on purpose: a stranded motorist has no login, and the ticket
    -- they raise from a tracking link is still a real ticket.
    account_id INT NULL,
    call_id INT NULL,
    kind ENUM('tower','customer') NOT NULL,

    -- Captured at submit time rather than joined later, so a ticket still reads
    -- correctly after the account behind it is deleted.
    name VARCHAR(150) NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(30) NULL,

    subject VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('open','answered','closed') NOT NULL DEFAULT 'open',

    admin_reply TEXT NULL,
    replied_at DATETIME NULL,
    replied_by_admin_id INT NULL,

    -- Whether the copy to support@ actually left the building. A ticket that
    -- only ever existed in an inbox that never received it is the failure mode
    -- worth being able to see.
    emailed TINYINT(1) NOT NULL DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_status (status, created_at),
    INDEX idx_account (account_id)
) ENGINE=InnoDB;

INSERT INTO platform_settings (setting_key, setting_value, description)
VALUES
  ('support_email', 'support@towsling.com', 'Where support requests are emailed.'),
  ('support_enabled', '1', 'Customers and towing companies can raise support requests.')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
