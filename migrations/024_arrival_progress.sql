-- ═══════════════════════════════════════════════════════════════════════════
--  024 — HOW FAR AWAY THE TRUCK WAS WHEN IT ACCEPTED
--
--  The customer's screen showed a spinner from the moment they booked until
--  the driver arrived. A spinner says "working on it" — which is honest while
--  we are still looking for a truck, and a lie afterwards. Once a company has
--  accepted, the thing the customer wants to know is not "is something
--  happening" but "how much longer", and a spinner cannot express that.
--
--  A progress bar can, but only against a fixed starting point. Progress
--  measured against "the furthest away he has been so far" moves backwards
--  every time he takes a detour, and progress measured against the promised
--  ETA turns into a bar that finishes while the truck is still coming.
--
--  So this records the distance between truck and customer at the moment of
--  accept, once, and never updates it. Everything after is
--  1 - (current / start), clamped.
--
--  NULL for every job accepted before this shipped, and for a company whose
--  position we did not know at accept time. The screen must therefore work
--  without it — it falls back to the ETA line alone.
--
--  Safe to re-run.
-- ═══════════════════════════════════════════════════════════════════════════

SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'calls'
              AND column_name = 'track_start_meters');
SET @s := IF(@c = 0,
  'ALTER TABLE calls ADD COLUMN track_start_meters INT NULL AFTER eta_live_at',
  'SELECT "calls.track_start_meters already present"');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'migration 024 complete' AS status;
