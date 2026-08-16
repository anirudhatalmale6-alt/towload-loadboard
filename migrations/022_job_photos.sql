-- ═══════════════════════════════════════════════════════════════════════════
--  022 — JOB PHOTOS
--
--  call_photos has existed since the first schema and nothing could ever write
--  to it. There was no upload endpoint and no screen — so `goa_requires_photo`,
--  which defaults to ON, demanded a photograph that could not physically be
--  taken. A driver who turned out to an empty parking space could not claim the
--  call-out fee he had earned. That is fixed by there finally being a way in.
--
--  Two things change here:
--
--   1. Storage columns to match how compliance documents are already handled.
--      A photo of somebody's licence plate and VIN is not something to drop in
--      a public folder and hand out the URL for: filenames are random, the
--      directory is denied at the web server, and the file is served by an
--      endpoint that checks who is asking. file_url stays for the old rows.
--
--   2. The photo types the job actually needs. Four corners rather than
--      "front/back" because the corners are what an insurer asks for — every
--      panel appears in two shots, so a dent cannot be argued into the gap
--      between them. Plate and VIN identify the vehicle beyond dispute.
--
--  Safe to re-run.
-- ═══════════════════════════════════════════════════════════════════════════

SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'call_photos'
              AND column_name = 'stored_path');
SET @s := IF(@c = 0,
  'ALTER TABLE call_photos
     ADD COLUMN account_id INT NULL AFTER call_id,
     ADD COLUMN stored_path VARCHAR(255) NULL AFTER file_url,
     ADD COLUMN mime_type VARCHAR(100) NULL AFTER stored_path,
     ADD COLUMN file_size INT NULL AFTER mime_type,
     ADD COLUMN note VARCHAR(255) NULL AFTER file_size',
  'SELECT "call_photos storage columns already present"');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- file_url was NOT NULL with no default, which would reject every insert now
-- that the real location lives in stored_path.
ALTER TABLE call_photos MODIFY COLUMN file_url VARCHAR(500) NOT NULL DEFAULT '';

-- The shot list. Existing values are kept — old rows must keep meaning what
-- they meant.
ALTER TABLE call_photos MODIFY COLUMN photo_type
  ENUM('arrival','pre_tow','hookup','damage','dropoff','signature','goa','other',
       'corner_fl','corner_fr','corner_rl','corner_rr','plate','vin')
  NOT NULL DEFAULT 'other';

-- Whether the driver had every required shot when he closed the job.
--
-- Recorded rather than enforced. Refusing to let a driver finish at 2am over a
-- missing photograph strands his payout and earns a phone call, so he is warned
-- hard, and if he goes ahead anyway THAT is written down. A damage claim three
-- weeks later is then answerable either way: here are the photographs, or here
-- is the record that he was told and chose not to take them.
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'calls'
              AND column_name = 'photos_complete');
SET @s := IF(@c = 0,
  'ALTER TABLE calls ADD COLUMN photos_complete TINYINT(1) NULL AFTER problem',
  'SELECT "calls.photos_complete already present"');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO platform_settings (setting_key, setting_value, description) VALUES
  ('require_job_photos', '1',
   'Ask towing companies for the four corners, plate and VIN at pickup and a shot at drop-off. Warns and records rather than blocking, so a missing photo never strands a payout.')
ON DUPLICATE KEY UPDATE description = VALUES(description);
