-- ═══════════════════════════════════════════════════════════════════════════
--  025 — A TOWING COMPANY CAN HAND A JOB BACK
--
--  Until now, accepting was final. A truck that broke down, a driver who
--  misread the address, a company that took a job it cannot actually run —
--  all of them had exactly two ways out: drive it anyway, or leave a stranded
--  customer watching a countdown that would never end. Neither is a feature.
--
--  So this is a RELEASE, not a cancellation. The customer's job goes back on
--  the board and every other company nearby is woken for it again. Their card
--  hold is untouched, they are charged nothing, and the screen tells them the
--  truth: the company cancelled and we are finding another one.
--
--  Three things are recorded, and each of them exists because of a specific
--  way this goes wrong:
--
--  released_count  — a company that accepts and releases repeatedly is not
--                    unlucky, it is taking jobs off the board to stop
--                    competitors seeing them. The count is what makes that
--                    visible instead of invisible.
--
--  released_at     — the customer's screen has to say something different from
--                    "finding you a truck", because for them this is not the
--                    beginning of a search, it is the collapse of one that had
--                    already succeeded.
--
--  call_releases   — who let go of what. Without it the same company is woken
--                    for the job it just dropped, sees it at the top of its
--                    own board, and can take it again — which is how an
--                    accept/release loop parks a customer forever.
--
--  Safe to re-run.
-- ═══════════════════════════════════════════════════════════════════════════

SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'calls'
              AND column_name = 'released_count');
SET @s := IF(@c = 0,
  'ALTER TABLE calls
     ADD COLUMN released_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER track_start_meters,
     ADD COLUMN released_at DATETIME NULL AFTER released_count',
  'SELECT "calls.released_count already present"');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS call_releases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    call_id INT NOT NULL,
    tower_account_id INT NOT NULL,
    released_by_user_id INT NULL,
    -- What the driver typed. Shown to nobody outside the company and the
    -- admin panel: it is the difference between "truck broke down" and a
    -- company gaming the board, and only the reasons make that readable.
    reason VARCHAR(300) NULL,
    -- The status it was released FROM. Handing a job back before setting off
    -- and abandoning a customer you are already parked next to are not the
    -- same act, and a single count would average them into meaninglessness.
    released_from VARCHAR(20) NOT NULL DEFAULT 'awarded',
    minutes_held SMALLINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- One row per company per job. The re-accept block reads this, so a
    -- duplicate would be a company holding a job twice in the same history.
    UNIQUE KEY uniq_call_tower (call_id, tower_account_id),
    INDEX idx_tower (tower_account_id, created_at),
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO platform_settings (setting_key, setting_value, description) VALUES
  ('tower_release_enabled', '1',
   'Lets a towing company hand an accepted job back to the board. 0 removes the button.'),

  -- A job released at 11:58 with a 12:00 expiry is a job nobody can accept.
  -- Re-opening without extending would put it back on the board for two
  -- minutes and then expire it, and the customer would be sent home believing
  -- no company wanted them.
  ('release_extends_minutes', '25',
   'Minutes added to a released job expiry, so the second search has as long as the first.'),

  -- Beyond this the release is still allowed — refusing would strand the
  -- customer, which is the outcome this whole feature exists to prevent — but
  -- it is flagged for review.
  ('release_review_threshold', '3',
   'Releases by one company in 30 days before it is flagged in the admin panel.')
ON DUPLICATE KEY UPDATE description = VALUES(description);

SELECT 'migration 025 complete' AS status;
