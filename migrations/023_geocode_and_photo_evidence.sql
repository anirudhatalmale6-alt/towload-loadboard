-- ═══════════════════════════════════════════════════════════════════════════
--  023 — GEOCODE CACHE, AND EVIDENCE ON A PHOTO
--
--  geocode_cache: the booking form can now turn a typed address into a
--  position server-side, so a customer who types a real address and never taps
--  the dropdown is quoted where they actually are. Google bills per lookup and
--  the same addresses get typed repeatedly, so answers are kept for 90 days.
--
--  call_photos.ip_address / accuracy_m: a photograph offered as proof that a
--  vehicle was not there needs to say where and from what connection it was
--  taken. Coordinates already had columns; the IP did not.
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS geocode_cache (
    query_hash CHAR(64) PRIMARY KEY,
    query_text VARCHAR(255) NOT NULL,
    lat DECIMAL(10,7) NOT NULL,
    lng DECIMAL(10,7) NOT NULL,
    formatted VARCHAR(255) NULL,
    city VARCHAR(120) NULL,
    state CHAR(2) NULL,
    zip VARCHAR(12) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'call_photos'
              AND column_name = 'ip_address');
SET @s := IF(@c = 0,
  'ALTER TABLE call_photos
     ADD COLUMN ip_address VARCHAR(45) NULL AFTER lng,
     ADD COLUMN accuracy_m INT NULL AFTER ip_address',
  'SELECT "call_photos evidence columns already present"');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
