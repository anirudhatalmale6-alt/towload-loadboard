-- ═══════════════════════════════════════════════════════════════════════════
--  011 — HOOK FEE, AND A MILEAGE ALLOWANCE ON ROADSIDE SERVICES
--
--  1. A separate hook-up fee on tows. Towing companies quote a hook fee and a
--     per-mile rate as two different numbers, so asking for one combined
--     figure meant everyone had to do arithmetic before they could answer.
--
--     It defaults to 0 and is ADDED to the base fee. That default is the
--     whole safety of this migration: every rate already entered keeps
--     charging exactly what it charges today. A company that folded hook-up
--     into its base fee — which is what the form asked for until now — must
--     leave this blank, or it pays itself for the hook twice.
--
--  2. Lockout, tyre change and fuel delivery can now state a mileage
--     allowance and a per-mile rate, the same way a tow does. Until now their
--     flat price covered any distance, which is fine in a city and wrong the
--     moment somebody is 40 miles out on the turnpike.
--
--     Both stay 0 unless set, and pricing only bills mileage when per_mile is
--     above 0, so an untouched rate sheet behaves exactly as before.
--
--  Safe to re-run.
-- ═══════════════════════════════════════════════════════════════════════════

-- ─── What a company says it charges to hook the vehicle ─────────────────────
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tower_rates' AND COLUMN_NAME = 'hook_fee') > 0,
  'SELECT 1',
  'ALTER TABLE tower_rates ADD COLUMN hook_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER base_fee');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;


-- ─── The same field on the price a market actually charges ──────────────────
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pricing_rules' AND COLUMN_NAME = 'hook_fee') > 0,
  'SELECT 1',
  'ALTER TABLE pricing_rules ADD COLUMN hook_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER base_fee');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
