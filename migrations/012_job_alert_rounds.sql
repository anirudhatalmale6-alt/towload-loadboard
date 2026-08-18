-- ════════════════════════════════════════════════════════════════════════════
--  REMINDING TOWERS ABOUT A JOB NOBODY HAS TAKEN
--
--  One alert is one chance. A driver under a truck, on the phone, or three
--  minutes from his van misses it and the job quietly expires with trucks in
--  range that would have taken it.
--
--  Two columns rather than counting call_events: this is read by a WHERE clause
--  on every sweep, and a correlated count over an events table to answer "has
--  this one had enough reminders" gets expensive precisely when the board is
--  busy.
--
--  last_alert_at is NULL for calls that predate this and for any call whose
--  first alert has not been recorded yet — the sweep falls back to created_at,
--  so the first reminder is still timed from something real.
-- ════════════════════════════════════════════════════════════════════════════
ALTER TABLE calls
    ADD COLUMN alert_rounds TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN last_alert_at DATETIME NULL;

CREATE INDEX idx_calls_open_alerts ON calls (status, expires_at, alert_rounds);
