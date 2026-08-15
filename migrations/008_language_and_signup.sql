-- ═══════════════════════════════════════════════════════════════════════════
--  008 — DEFAULT LANGUAGE BY MARKET, AND A LIGHTER SIGNUP
--
--  Spanish was hardcoded as the default everywhere, which was right when the
--  platform was Miami-only and wrong the moment it went nationwide. English is
--  now the default and Spanish is chosen for the Miami market specifically —
--  see includes/geo.php for how a visitor is placed.
--
--  Safe to re-run.
-- ═══════════════════════════════════════════════════════════════════════════

INSERT INTO platform_settings (setting_key, setting_value, description) VALUES
  ('default_language', 'en',
   'Language shown when nothing better is known. Spanish is applied per-market via spanish_regions.'),

  ('spanish_regions', 'FL:MIAMI-DADE,FL:BROWARD,FL:MONROE',
   'Comma-separated STATE:AREA where Spanish is the default. AREA may be a county or a city; STATE alone covers the whole state.'),

  -- Deliberately empty. Nothing calls out to a lookup service until a key is
  -- set, so the platform never depends on someone else's free tier.
  ('geoip_provider', '',
   'URL template for IP geolocation, e.g. https://api.example.com/{ip}?key={key}. Empty disables the lookup.'),
  ('geoip_key', '',
   'SECRET. API key for the geolocation provider above.')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- Documents are no longer demanded at signup, so nothing else would ever move
-- a new company into the review queue. They now arrive there directly and the
-- platform asks for EIN and paperwork at review, with a human looking at it.
UPDATE platform_settings
   SET setting_value = '0',
       description = 'Flip an account to Pending Review once all docs are in. Off: towers enter the queue at signup and documents are requested at review.'
 WHERE setting_key = 'auto_submit_for_review';
