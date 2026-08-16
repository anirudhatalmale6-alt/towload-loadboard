-- ═══════════════════════════════════════════════════════════════════════════
--  010 — MARKET RATES AND SELF-OPENING CITIES
--
--  Two changes that go together.
--
--  1. A city opens by itself. Until now a market only went live when someone
--     switched it on by hand, so an approved company in Tampa could sit there
--     receiving nothing while nobody knew they were waiting. Approving a
--     company now creates the zone around them.
--
--  2. What that city charges comes from the companies working in it. Each
--     company states its own rates; the zone's rates are the average of the
--     approved companies inside it, recomputed whenever one of them changes.
--
--  The manual control survives all of it. A pricing_rules row marked 'manual'
--  is never touched by the recompute — that is the override, and it is per
--  zone and per service, so one stubborn price does not force the whole city
--  off automatic.
--
--  Safe to re-run.
-- ═══════════════════════════════════════════════════════════════════════════

-- ─── What each company says it charges ──────────────────────────────────────
-- Never shown to another company. It exists to inform one price that the
-- platform sets; it is not a rate card that gets published or circulated.
CREATE TABLE IF NOT EXISTS tower_rates (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    account_id      INT NOT NULL,
    service_type    ENUM('tow','winch_recovery','lockout','jumpstart','tire_change','fuel_delivery') NOT NULL,
    vehicle_class   ENUM('light','medium','heavy','motorcycle') NOT NULL DEFAULT 'light',

    -- The hook-up / call price. The only figure asked for on most services.
    base_fee        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    -- Tow only. A company that includes 5 miles at $95 and one that includes
    -- nothing at $75 are not comparable until both are spelled out.
    included_miles  DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
    per_mile        DECIMAL(10,2) NOT NULL DEFAULT 0.00,

    updated_at      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_account_service_class (account_id, service_type, vehicle_class),
    KEY idx_service (service_type, vehicle_class),
    CONSTRAINT fk_tower_rates_account FOREIGN KEY (account_id)
        REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Which zone rates are computed and which are held by hand ───────────────
-- Existing rows default to 'manual' on purpose. Everything priced before this
-- migration was set deliberately, and a migration that silently hands those
-- numbers to an averaging job is a migration that changes prices.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pricing_rules' AND COLUMN_NAME = 'rate_source') > 0,
  'SELECT 1',
  'ALTER TABLE pricing_rules ADD COLUMN rate_source ENUM(\'manual\',\'auto\') NOT NULL DEFAULT \'manual\' AFTER zone_id');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;


-- How many companies fed an automatic rate. An average of one is a single
-- company's price wearing the word "average", and the admin screen should be
-- able to say so rather than implying a market consensus that does not exist.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pricing_rules' AND COLUMN_NAME = 'sample_size') > 0,
  'SELECT 1',
  'ALTER TABLE pricing_rules ADD COLUMN sample_size INT NOT NULL DEFAULT 0 AFTER rate_source');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;


SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pricing_rules' AND COLUMN_NAME = 'computed_at') > 0,
  'SELECT 1',
  'ALTER TABLE pricing_rules ADD COLUMN computed_at DATETIME NULL DEFAULT NULL AFTER sample_size');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;


-- ─── Zones that opened themselves ───────────────────────────────────────────
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pricing_zones' AND COLUMN_NAME = 'auto_created') > 0,
  'SELECT 1',
  'ALTER TABLE pricing_zones ADD COLUMN auto_created TINYINT(1) NOT NULL DEFAULT 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;


SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pricing_zones' AND COLUMN_NAME = 'opened_by_account_id') > 0,
  'SELECT 1',
  'ALTER TABLE pricing_zones ADD COLUMN opened_by_account_id INT NULL DEFAULT NULL');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;


INSERT INTO platform_settings (setting_key, setting_value, description) VALUES
  ('auto_open_markets', '1',
   'Approving a company with no live zone around it opens one. Off means markets are switched on by hand only.'),

  ('auto_rates_enabled', '1',
   'Zone rates are averaged from the rates the approved companies in that zone state. Rules marked manual are never touched.'),

  ('auto_rates_min_companies', '1',
   'How many companies must have stated a rate before it is used. At 1 the "average" is one company.'),

  ('rate_basis', 'tower_net',
   'tower_net: a company stating $110 NETS $110, so the customer pays that plus our fee. customer_total: $110 is what the customer pays and the company nets less our cut.'),

  ('auto_open_radius_miles', '35',
   'Radius of a zone opened automatically around a newly approved company.')
ON DUPLICATE KEY UPDATE description = VALUES(description);
