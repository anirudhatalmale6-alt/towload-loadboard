-- ═══════════════════════════════════════════════════════════════════════════
--  019 — "Ready for jobs" switch, and who is on the site right now
-- ═══════════════════════════════════════════════════════════════════════════

-- ─── duty status ───────────────────────────────────────────────────────────
-- DEFAULT 1 is load-bearing. Every existing company must stay exactly as it is
-- the moment this runs; a default of 0 would take the whole supply side off
-- duty at once, silently, and the first anyone would know is customers being
-- told nobody covers their area.
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tower_profiles'
      AND COLUMN_NAME='is_available')=0,
  'ALTER TABLE tower_profiles ADD COLUMN is_available TINYINT(1) NOT NULL DEFAULT 1 AFTER accepts_auto_dispatch',
  'SELECT 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tower_profiles'
      AND COLUMN_NAME='available_changed_at')=0,
  'ALTER TABLE tower_profiles ADD COLUMN available_changed_at DATETIME NULL AFTER is_available',
  'SELECT 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ─── live presence ─────────────────────────────────────────────────────────
-- One row per browser, rewritten on each heartbeat. Deliberately NOT an append
-- log: this answers "who is on the site now", and a log of every ping would be
-- millions of rows to answer a question about the last sixty seconds.
CREATE TABLE IF NOT EXISTS presence (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Random per browser, generated client-side and kept in localStorage. Not
    -- a login and not a tracking id across sites — it exists so two tabs from
    -- one person are not counted as two people.
    session_key VARCHAR(64) NOT NULL,

    -- Filled in only once somebody signs in. A stranded motorist has no login,
    -- so most rows on the customer side are anonymous by nature.
    account_id INT NULL,
    kind ENUM('customer','tower','admin','anon') NOT NULL DEFAULT 'anon',

    page VARCHAR(120) NULL,        -- which screen they are on
    label VARCHAR(150) NULL,       -- company name once known
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    referrer VARCHAR(255) NULL,

    first_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen DATETIME NOT NULL,

    UNIQUE KEY uniq_session (session_key),
    INDEX idx_last_seen (last_seen),
    INDEX idx_kind (kind, last_seen)
) ENGINE=InnoDB;

INSERT INTO platform_settings (setting_key, setting_value, description)
VALUES
  ('presence_enabled', '1', 'Track who is on the site so the admin panel can show live visitors.'),
  ('presence_window_seconds', '75',
   'A visitor counts as online if they have pinged within this many seconds. Must exceed the client heartbeat interval.'),
  ('presence_retain_hours', '24', 'Presence rows older than this are deleted.')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
