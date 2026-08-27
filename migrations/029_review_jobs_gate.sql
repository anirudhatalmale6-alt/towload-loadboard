-- ═══════════════════════════════════════════════════════════════════════════
--  Create the App Store demo jobs only for the reviewer's own account
--
--  The demo jobs were already invisible to every real company — they sit in
--  California and the board filters by distance from each company's yard. But
--  invisible on the BOARD is not the same as absent from the DATABASE, and the
--  platform owner sees every row on his site whether the board shows it or not.
--
--  Creating them on any board load meant demo data existed continuously, for
--  the whole stretch between submitting the app and someone actually reviewing
--  it. Gating creation on the reviewer's account means the owner's job list
--  stays empty until a reviewer signs in, and the jobs are there the moment one
--  does — which is the only moment they were ever for.
--
--  0 disables the gate and restores the old create-for-anyone behaviour.
-- ═══════════════════════════════════════════════════════════════════════════

INSERT INTO platform_settings (setting_key, setting_value, description)
VALUES ('review_jobs_tower_id', '35',
        'Only this tower account triggers creation of App Store demo jobs. 0 = any.')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
