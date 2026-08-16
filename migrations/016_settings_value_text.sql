-- ═══════════════════════════════════════════════════════════════════════════
--  016 — platform_settings.setting_value must hold a credential
--
--  It was VARCHAR(500). A RingCentral JWT is 685 characters, so storing one
--  silently truncated it to 500 and MySQL raised a warning nobody reads. The
--  symptom was not "value too long" — it was RingCentral answering
--  "Invalid assertion signature", because the signature really had been cut in
--  half. Every credential looked present and every call failed.
--
--  TEXT rather than a bigger VARCHAR: the next API key to arrive will be
--  whatever length it is, and this column already holds VAPID PEMs, Stripe
--  keys and OAuth tokens.
-- ═══════════════════════════════════════════════════════════════════════════

SET @sql := (SELECT IF(
  (SELECT DATA_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'platform_settings'
      AND COLUMN_NAME = 'setting_value') <> 'text',
  'ALTER TABLE platform_settings MODIFY COLUMN setting_value TEXT NOT NULL',
  'SELECT 1'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
