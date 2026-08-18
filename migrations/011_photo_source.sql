-- ════════════════════════════════════════════════════════════════════════════
--  WHERE A JOB PHOTO CAME FROM
--
--  The app now lets a driver attach an existing picture as well as take one.
--  Both are useful — a driver whose camera permission is off, or who shot the
--  damage before the job was accepted, should not be locked out — but they are
--  not worth the same in a dispute. A photo taken at the vehicle carries a time
--  and a coordinate from the moment of the tow; one chosen from the library
--  carries no promise about either.
--
--  Recorded rather than inferred. Existing rows predate the choice and are
--  honestly marked 'unknown' instead of being backfilled as 'camera', which
--  would be inventing evidence about photographs already in the record.
-- ════════════════════════════════════════════════════════════════════════════
ALTER TABLE call_photos
    ADD COLUMN source ENUM('camera','library','unknown')
        NOT NULL DEFAULT 'unknown' AFTER photo_type;
