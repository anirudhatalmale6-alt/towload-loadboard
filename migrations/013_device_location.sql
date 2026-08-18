-- ════════════════════════════════════════════════════════════════════════════
--  ALERTING FROM WHERE THE TRUCK ACTUALLY IS
--
--  Until now a job was matched against the company's YARD. A driver forty miles
--  from the yard on the far side of a job got nothing, and a driver sitting at
--  the yard got everything — which is backwards on the one measure that matters,
--  who can actually get there.
--
--  Stored per DEVICE, not per company. A company is several phones in several
--  places; a single "current location" column on tower_profiles would be
--  whichever driver pinged last, which is worse than the yard because it is
--  wrong in a way nobody can see.
--
--  ─────────────────────────────────────────────────────────────────────────
--  WHAT THIS DELIBERATELY DOES NOT CHANGE
--
--  Customer-facing coverage (approvedTowersNear / surge) still reads the yard.
--  If whether a stranded motorist can request a tow at all depended on whether
--  a driver's phone happened to be awake, coverage would flicker minute to
--  minute: "no trucks near you" at 3am because a phone was asleep, from a
--  company that would happily have taken the job. Coverage is a promise about a
--  business. Alerting is a guess about a truck. Only the guess moves.
--  ─────────────────────────────────────────────────────────────────────────
--
--  use_device_location is per device and defaults ON. An owner whose phone
--  lives at his kitchen table can turn it off and go back to being matched from
--  the yard, without changing anything for his drivers.
-- ════════════════════════════════════════════════════════════════════════════
ALTER TABLE push_subscriptions
    ADD COLUMN last_lat DECIMAL(10,7) NULL,
    ADD COLUMN last_lng DECIMAL(10,7) NULL,
    ADD COLUMN last_location_at DATETIME NULL,
    ADD COLUMN location_accuracy_m INT NULL,
    ADD COLUMN use_device_location TINYINT(1) NOT NULL DEFAULT 1;

-- The alert query prefilters on a bounding box over EITHER the device position
-- or the yard, so the device columns need to be indexed the same way.
CREATE INDEX idx_push_subs_location ON push_subscriptions (last_lat, last_lng);
