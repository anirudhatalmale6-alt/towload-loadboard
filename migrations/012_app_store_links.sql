-- ═══════════════════════════════════════════════════════════════════════════
--  012 — APP STORE LINKS
--
--  Where the native apps live, so the dashboard can offer a download button
--  instead of walking an operator through adding a web page to their Home
--  Screen.
--
--  Both start EMPTY, and empty is load-bearing. On iPhone, web push only
--  works once the site has been added to the Home Screen — that is Apple's
--  rule, not a design choice — so until there is a real App Store listing to
--  send people to, the Home Screen instructions are the only thing standing
--  between an operator and no job alerts at all. The button appears the
--  moment a URL is pasted in here, and not before.
--
--  Safe to re-run.
-- ═══════════════════════════════════════════════════════════════════════════

INSERT INTO platform_settings (setting_key, setting_value, description) VALUES
  ('ios_app_url', '',
   'App Store link for the iPhone app. Empty until the app is published — while empty, operators are shown the Add to Home Screen steps instead, because that is the only way alerts reach an iPhone.'),

  ('android_app_url', '',
   'Google Play link for the Android app. Empty until published. Android gets alerts from the browser without installing anything, so this is optional.')
ON DUPLICATE KEY UPDATE description = VALUES(description);
