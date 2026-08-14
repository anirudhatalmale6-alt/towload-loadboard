-- ═══════════════════════════════════════════════════════════════════════════
--  006 — PUSH NOTIFICATIONS FOR TOWING COMPANIES
--
--  A towing job is dead in about 20 minutes. Everything else on this platform
--  can be a page someone chooses to visit; this cannot. The alert has to reach
--  a phone in a truck, on a lock screen, with the app closed.
--
--  Standard Web Push (RFC 8030 / 8291), which Apple has supported on iPhone
--  since iOS 16.4 for home-screen-installed web apps and delivers over the same
--  APNs pipe their native apps use. The identical subscription records work
--  unchanged when this is wrapped in a native shell later, so nothing here is
--  throwaway.
--
--  Safe to re-run.
-- ═══════════════════════════════════════════════════════════════════════════

-- ─── Devices ────────────────────────────────────────────────────────────────
-- One row per browser-on-a-device. A company with a dispatcher and three
-- drivers is four rows against one account, and all four should buzz.
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    user_id INT NULL,

    -- Endpoints run past 500 chars on some services, and MySQL cannot put a
    -- unique index on a column that long. The hash is the real key.
    endpoint TEXT NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,

    -- RFC 8291 client keys. p256dh is the device's public key, auth is the
    -- shared secret; both are needed to encrypt a payload the push service
    -- itself cannot read.
    p256dh VARCHAR(255) NOT NULL,
    auth_secret VARCHAR(64) NOT NULL,

    platform ENUM('ios','android','desktop','unknown') NOT NULL DEFAULT 'unknown',
    -- iOS only permits push from a home-screen install. Knowing whether this
    -- subscription came from one turns "I never got the alert" from a mystery
    -- into a one-line answer.
    is_standalone TINYINT(1) NOT NULL DEFAULT 0,
    user_agent VARCHAR(255) NULL,
    label VARCHAR(60) NULL,               -- "Mike's iPhone", set by the owner

    is_active TINYINT(1) NOT NULL DEFAULT 1,
    fail_count INT NOT NULL DEFAULT 0,
    last_success_at DATETIME NULL,
    last_failure_at DATETIME NULL,
    last_error VARCHAR(255) NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NULL,

    UNIQUE KEY uniq_endpoint (endpoint_hash),
    INDEX idx_account_active (account_id, is_active),
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── Delivery log ───────────────────────────────────────────────────────────
-- Kept because the single most common support call on any alerting product is
-- "I didn't get it", and the only useful answer names the moment it was sent,
-- the device, and what the push service said back.
CREATE TABLE IF NOT EXISTS push_deliveries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subscription_id INT NULL,
    account_id INT NULL,
    call_id INT NULL,
    kind VARCHAR(40) NOT NULL DEFAULT 'new_job',
    http_code INT NULL,
    ok TINYINT(1) NOT NULL DEFAULT 0,
    error VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_call (call_id),
    INDEX idx_account_time (account_id, created_at),
    INDEX idx_sub_time (subscription_id, created_at)
) ENGINE=InnoDB;

-- ─── Per-company alert preferences ──────────────────────────────────────────
-- A one-truck operator who works days does not want a 3am buzz for a $45
-- jumpstart 30 miles away. Without these the first thing an operator does is
-- turn notifications off at the OS level, and then they are gone for good.
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'tower_profiles'
              AND column_name = 'push_enabled');
SET @s := IF(@c = 0,
  'ALTER TABLE tower_profiles
     ADD COLUMN push_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER accepts_auto_dispatch,
     ADD COLUMN push_radius_miles INT NULL AFTER push_enabled,
     ADD COLUMN push_min_payout DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER push_radius_miles,
     ADD COLUMN push_quiet_start TIME NULL AFTER push_min_payout,
     ADD COLUMN push_quiet_end TIME NULL AFTER push_quiet_start,
     ADD COLUMN push_timezone VARCHAR(40) NOT NULL DEFAULT ''America/New_York'' AFTER push_quiet_end',
  'SELECT "tower_profiles push columns already present"');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- push_radius_miles is deliberately NULL by default: alert radius then follows
-- service_radius_miles and there is only one number to keep right. It exists
-- because the two genuinely differ — an operator will happily *take* a job 40
-- miles out that he does not want to be *woken* for.

-- ─── Settings ───────────────────────────────────────────────────────────────
INSERT INTO platform_settings (setting_key, setting_value, description) VALUES
  ('push_enabled', '1',
   'Master switch for outbound push. 0 stops every notification platform-wide.'),

  -- Signing keypair for VAPID. Generated once by api/push.php on first use, so
  -- there is no key to copy between environments by hand and none in the repo.
  -- The private key is filtered out of every admin response; see EDITABLE_SETTINGS.
  ('vapid_public_key',  '', 'VAPID public key (base64url). Safe to publish — the browser needs it.'),
  ('vapid_private_key', '', 'SECRET. VAPID private key PEM. Never returned by any endpoint.'),
  ('vapid_subject', 'https://bot24.io/towload',
   'VAPID "sub" claim. Apple and Google want a contact URL or mailto: for the sender.'),

  ('push_ttl_seconds', '900',
   'How long a push service should keep retrying. 900 = 15 min; past that the job is stale and a late buzz is worse than none.'),

  ('push_max_failures', '5',
   'Consecutive failures before a device is deactivated. Protects the send loop from a dead endpoint.'),

  ('push_fanout_limit', '60',
   'Most devices alerted for one job. A guard against a wide-radius job in a dense market stalling the request.')
ON DUPLICATE KEY UPDATE description = VALUES(description);
