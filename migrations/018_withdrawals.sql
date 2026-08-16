-- ═══════════════════════════════════════════════════════════════════════════
--  018 — Withdrawals
--
--  Until now a completed job queued a payout row and a cron sweep pushed it to
--  Stripe on its own. Ricardo asked for the other model: the money accumulates
--  as a balance the towing company can see, and they choose when to take it.
--
--  Both models are kept, because they are one setting apart and the automatic
--  one is the better answer for a busy company that never wants to think about
--  it. `payout_mode` decides. Default is manual, which is what was asked for.
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS withdrawals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,

    amount DECIMAL(12,2) NOT NULL,
    status ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',

    -- One Stripe transfer per withdrawal, not one per job. A company that did
    -- forty jobs this week should see one line on its statement, not forty.
    stripe_transfer_id VARCHAR(255) NULL,
    failure_reason VARCHAR(255) NULL,

    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    paid_at DATETIME NULL,

    INDEX idx_account (account_id, status),
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Which withdrawal a given job's payout was swept into. NULL means the money
-- is still sitting in the company's available balance.
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payouts' AND COLUMN_NAME='withdrawal_id')=0,
  'ALTER TABLE payouts ADD COLUMN withdrawal_id INT NULL AFTER escrow_hold_id, ADD INDEX idx_withdrawal (withdrawal_id)',
  'SELECT 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO platform_settings (setting_key, setting_value, description)
VALUES
  ('payout_mode', 'manual',
   'manual = towing companies withdraw their balance themselves. auto = a sweep pays every completed job out automatically.'),
  ('min_withdrawal', '25.00',
   'Smallest amount a towing company can withdraw. Each transfer costs the platform, so tiny withdrawals are not free.'),
  ('platform_payout_enabled', '1',
   'The owner can pay the platform balance out to his own bank from the admin panel.')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
