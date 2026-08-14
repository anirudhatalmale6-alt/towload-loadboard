-- ═══════════════════════════════════════════════════════════════════════════
--  003 — One middleman only
--
--  Ricardo's call: motor clubs and towing providers are out. The platform is
--  the only party between the stranded motorist and the tow truck, exactly
--  like Uber. Jobs come from customers, the platform prices them, towers get
--  an alert and accept, and they're paid the job amount minus 10%.
--
--  The provider code is switched OFF rather than deleted. Big motor clubs pay
--  net-30 and are a plausible phase two; when that day comes this is one
--  setting, not a rebuild.
-- ═══════════════════════════════════════════════════════════════════════════

-- His fee, as stated: "the towers will be paid what the job pays minus my
-- small 10% fee". Same 10% everywhere now — there is only one kind of job.
UPDATE platform_settings SET setting_value = '10.0' WHERE setting_key = 'consumer_fee_percent';
UPDATE platform_settings SET setting_value = '5.00' WHERE setting_key = 'consumer_fee_minimum';

INSERT INTO platform_settings (setting_key, setting_value, description) VALUES
-- Master switch for the whole broker/motor-club side: signup, posting, bidding.
('providers_enabled', '0', 'Allow provider/motor-club accounts to register and post jobs'),
-- With the platform setting the price there is nothing to bid on.
('bidding_enabled',   '0', 'Allow towers to counter-offer instead of accepting a fixed price')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- A consumer account has no email — the phone number is the contact and the
-- tracking token is the identity. The column was NOT NULL from the days when
-- every account was a company that logged in, and it hard-failed the request at
-- the very last step for any customer who left the optional email box empty.
ALTER TABLE accounts MODIFY email VARCHAR(255) NULL;

-- Deactivate provider accounts. They can no longer log in or post.
UPDATE accounts SET is_active = 0
 WHERE account_type = 'provider';

-- NOTE: open provider jobs are NOT cancelled here on purpose. Cancelling a call
-- in raw SQL would leave its escrow hold at 'held' and the money stranded in
-- provider_balances.held forever — escrow_holds and provider_balances may only
-- be written by the escrow engine. Run migrations/003_retire_board_calls.php,
-- which refunds each hold properly and then closes the call.
