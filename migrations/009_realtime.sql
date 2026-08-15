-- ═══════════════════════════════════════════════════════════════════════════
--  009 — REALTIME SETTINGS
--
--  The realtime server is a NUDGE channel: it tells a browser that something
--  changed and the browser refetches through the normal API. It holds no data,
--  touches no database and sees no customer information, so the settings here
--  are plumbing rather than policy.
--
--  realtime_public_url stays EMPTY until the dedicated domain exists. Empty
--  means the whole feature is off and every screen polls exactly as it does
--  today — which is why this can ship before the domain does.
--
--  Safe to re-run.
-- ═══════════════════════════════════════════════════════════════════════════

INSERT INTO platform_settings (setting_key, setting_value, description) VALUES
  ('realtime_enabled', '1',
   'Master switch. Off, or with no URL set, every screen falls back to polling.'),

  ('realtime_public_url', '',
   'Where the BROWSER connects, e.g. https://ws.example.com. Empty disables realtime everywhere.'),

  ('realtime_internal_url', 'http://127.0.0.1:3003',
   'Where PHP publishes events. Localhost only — this port is never exposed.'),

  ('realtime_internal_secret', '',
   'SECRET. Shared between PHP and the realtime server. Generated on first use if empty.')
ON DUPLICATE KEY UPDATE description = VALUES(description);
