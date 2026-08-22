-- App Store review demo data.
--
-- The board is empty until the platform has customers, and an empty board is an
-- automatic guideline 2.1 rejection: the reviewer signs in, sees nothing, and
-- reports that the app's main functionality could not be reviewed. These
-- settings drive includes/review_jobs.php, which keeps a handful of jobs
-- standing in California for the demo account to look at.
--
-- Switch the whole thing off with review_jobs_enabled = '0'. The demo jobs are
-- every call whose provider_account_id is review_jobs_provider_id, so removing
-- them afterwards is one delete against one id.

INSERT INTO platform_settings (setting_key, setting_value, description) VALUES
  ('review_jobs_enabled', '0',
   'Keep demo jobs on the board for App Store review. 1 = on.'),
  ('review_jobs_provider_id', '0',
   'The account id the demo jobs are posted from. Nothing else is ever written.'),
  ('review_jobs_count', '8',
   'How many demo jobs to keep standing.'),
  ('review_jobs_ttl_minutes', '90',
   'How long each demo job lives before it expires and is replaced. Kept short so the countdown on the card looks like a real job.')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- A demo job nobody takes expires, and the surge model reads an expiry as
-- evidence that no truck would take the work. Left alone, a week of App Review
-- would raise prices across California on the strength of demo data.
INSERT INTO platform_settings (setting_key, setting_value, description)
VALUES ('surge_disabled_states', 'CA', 'Two-letter states where surge pricing never applies.')
ON DUPLICATE KEY UPDATE setting_value = 'CA';
