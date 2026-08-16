-- ═══════════════════════════════════════════════════════════════════════════
--  013 — Email and phone verification for towing companies
--
--  A company must confirm both its email address and its phone number before it
--  can be dispatched a job. The phone number matters more than it looks: it is
--  the number handed to a stranded customer when the job is accepted, so a typo
--  there strands somebody twice.
--
--  Guarded on information_schema throughout — this runs against live.
-- ═══════════════════════════════════════════════════════════════════════════

-- ─── accounts: the verified state ──────────────────────────────────────────
-- Both the timestamp AND the value that was verified are stored. Verifying an
-- address and then editing it to a different one must not leave the account
-- looking verified, and the company profile screen lets them do exactly that.
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounts'
      AND COLUMN_NAME = 'email_verified_at') = 0,
  'ALTER TABLE accounts ADD COLUMN email_verified_at DATETIME NULL AFTER email',
  'SELECT 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounts'
      AND COLUMN_NAME = 'email_verified_value') = 0,
  'ALTER TABLE accounts ADD COLUMN email_verified_value VARCHAR(255) NULL AFTER email_verified_at',
  'SELECT 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounts'
      AND COLUMN_NAME = 'phone_verified_at') = 0,
  'ALTER TABLE accounts ADD COLUMN phone_verified_at DATETIME NULL AFTER phone',
  'SELECT 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounts'
      AND COLUMN_NAME = 'phone_verified_value') = 0,
  'ALTER TABLE accounts ADD COLUMN phone_verified_value VARCHAR(30) NULL AFTER phone_verified_at',
  'SELECT 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ─── the codes themselves ──────────────────────────────────────────────────
-- The code is stored hashed. It is a short-lived credential that grants a state
-- change on the account, and a readable column of live codes in the database is
-- the same mistake as a plaintext password column.
CREATE TABLE IF NOT EXISTS verification_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    channel ENUM('email','phone') NOT NULL,
    -- The address or number this code was sent to, so a code cannot be typed in
    -- after the destination has been changed underneath it.
    destination VARCHAR(255) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    attempts TINYINT NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    -- Kept even after use: this table is the send log that the hourly send limit
    -- is counted from, so rows must not be deleted on success.
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_live (account_id, channel, consumed_at, expires_at),
    INDEX idx_rate (account_id, channel, created_at),
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── settings ──────────────────────────────────────────────────────────────
INSERT INTO platform_settings (setting_key, setting_value, description)
VALUES
  ('require_verification_to_accept', '1',
   'Towing companies must verify email and phone before they can be dispatched or accept a job.'),
  ('rc_jwt', '', 'RingCentral JWT credential for sending SMS.'),
  ('rc_client_id', '', 'RingCentral app Client ID.'),
  ('rc_client_secret', '', 'RingCentral app Client Secret.'),
  ('rc_from_number', '', 'The RingCentral number verification texts are sent FROM, E.164 e.g. +13055551234.'),
  ('rc_server_url', 'https://platform.ringcentral.com', 'RingCentral API base. Sandbox is https://platform.devtest.ringcentral.com.'),
  ('mail_from', 'no-reply@towsling.com', 'From address for verification emails.'),
  ('mail_from_name', 'TowSling', 'From name for verification emails.')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- ─── grandfather the companies already approved by hand ────────────────────
-- Without this the gate takes every company Ricardo has personally vetted and
-- blocks it the moment this runs — a live marketplace goes to zero suppliers
-- with no warning and no message explaining why.
--
-- These accounts were approved by a human who read their documents, which is a
-- stronger check than an SMS code, so treating that as verification is honest
-- rather than a shortcut. Any single company can be pushed back through it by
-- setting its two *_verified_at columns back to NULL.
UPDATE accounts
   SET email_verified_at    = COALESCE(email_verified_at, NOW()),
       email_verified_value = COALESCE(email_verified_value, email),
       phone_verified_at    = COALESCE(phone_verified_at, IF(phone IS NULL, NULL, NOW())),
       phone_verified_value = COALESCE(phone_verified_value, phone)
 WHERE account_type = 'tower'
   AND verification_status = 'approved';
