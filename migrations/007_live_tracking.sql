-- ═══════════════════════════════════════════════════════════════════════════
--  007 — LIVE TRUCK TRACKING
--
--  Until now the ETA a customer sees is whatever the driver typed when he
--  accepted. It never changes. Someone watching that screen for twenty minutes
--  reads the same "18 minutes" the whole time, which is worse than showing
--  nothing — it teaches them the screen is lying.
--
--  This adds the driver's real position, a recalculated ETA, and a breadcrumb
--  trail per job.
--
--  Scope is deliberately narrow. Location is only ever accepted while a job is
--  live, and it stops the moment that job closes. There is no state in this
--  schema that can represent "where is this driver right now" outside a job,
--  because that is not a thing this platform should be able to answer.
--
--  Safe to re-run.
-- ═══════════════════════════════════════════════════════════════════════════

-- ─── Breadcrumbs ────────────────────────────────────────────────────────────
-- Append-only. The customer only ever needs the latest position (which lives on
-- `calls` for a cheap read), but the trail is what settles "the driver never
-- came" a week later, and it is the evidence behind a disputed GOA fee.
CREATE TABLE IF NOT EXISTS call_locations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    call_id INT NOT NULL,
    account_id INT NOT NULL,
    user_id INT NULL,

    lat DECIMAL(10,7) NOT NULL,
    lng DECIMAL(10,7) NOT NULL,

    -- Straight from CoreLocation. accuracy_m especially: a 3000m fix in a
    -- parking garage should not move the marker across town, and without the
    -- number there is no way to tell that from a real jump.
    accuracy_m SMALLINT UNSIGNED NULL,
    heading SMALLINT NULL,               -- degrees, 0-359, NULL when stationary
    speed_mph DECIMAL(5,1) NULL,

    -- Two clocks on purpose. recorded_at is the phone's, created_at is ours.
    -- A driver in a dead zone buffers pings and delivers them in a burst; if
    -- only the server clock were kept, that burst would look like a truck
    -- teleporting, and the ETA would be computed from the wrong moment.
    recorded_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_call_time (call_id, recorded_at),
    INDEX idx_created (created_at),
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── Latest position, denormalised onto the call ────────────────────────────
-- The customer's page polls every few seconds. Reading the newest row out of a
-- growing breadcrumb table on every poll, for every live job, is the kind of
-- query that is free at ten jobs and a problem at a thousand.
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'calls'
              AND column_name = 'truck_lat');
SET @s := IF(@c = 0,
  'ALTER TABLE calls
     ADD COLUMN truck_lat DECIMAL(10,7) NULL,
     ADD COLUMN truck_lng DECIMAL(10,7) NULL,
     ADD COLUMN truck_heading SMALLINT NULL,
     ADD COLUMN truck_speed_mph DECIMAL(5,1) NULL,
     ADD COLUMN truck_updated_at DATETIME NULL,
     -- The moving number. awarded_eta_minutes stays as it is: it is what the
     -- driver PROMISED at accept time, and keeping the two apart is the only
     -- way to ever answer "does this company hit its own ETAs".
     ADD COLUMN eta_live_minutes SMALLINT NULL,
     ADD COLUMN eta_live_meters INT NULL,
     ADD COLUMN eta_live_at DATETIME NULL',
  'SELECT "calls tracking columns already present"');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── Settings ───────────────────────────────────────────────────────────────
INSERT INTO platform_settings (setting_key, setting_value, description) VALUES
  ('tracking_enabled', '1',
   'Master switch for live truck tracking. 0 stops collection and hides the map.'),

  ('tracking_ping_seconds', '10',
   'How often the driver app should send a position while on a live job. Told to the app, not enforced by it.'),

  ('tracking_stale_seconds', '90',
   'Older than this and the customer is told the position is updating rather than shown a frozen marker pretending to be live.'),

  ('tracking_retain_days', '30',
   'Breadcrumbs older than this are deleted. Long enough to settle a dispute, short enough not to be a location database.'),

  -- Straight-line distance is not a route. 1.35 is the usual urban ratio of
  -- road distance to crow-flies. It is a stand-in for a real routing API and
  -- will be badly wrong anywhere a bay or a river forces a detour.
  ('tracking_road_factor', '1.35',
   'Multiplier turning straight-line distance into estimated road distance. Replace with a routing API for accuracy.'),

  ('tracking_avg_speed_mph', '28',
   'Assumed average speed for the ETA when the truck is not moving or has no speed reading.'),

  ('tracking_max_speed_mph', '120',
   'Sanity ceiling. A position implying more than this since the last one is rejected as GPS noise rather than moving the marker.'),

  ('tracking_max_accuracy_m', '250',
   'Fixes vaguer than this are stored but do not move the customer-facing marker.')
ON DUPLICATE KEY UPDATE description = VALUES(description);
