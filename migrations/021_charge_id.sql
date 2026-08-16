-- ═══════════════════════════════════════════════════════════════════════════
--  The charge behind each captured job.
--
--  A tower's payout is transferred against the specific charge that paid for
--  that job (Stripe's source_transaction). Without it Stripe refuses the
--  transfer with "you have insufficient available funds" — correctly, because
--  a card capture lands in the PENDING balance and takes about two business
--  days to become available. Money genuinely collected from a customer could
--  not be sent on to the company that earned it.
--
--  Separate from stripe_payment_intent_id: an intent is the authorisation, the
--  charge is the money. Only the charge can be named as a transfer's source.
-- ═══════════════════════════════════════════════════════════════════════════

SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'calls'
              AND column_name = 'stripe_charge_id');
SET @s := IF(@c = 0,
  'ALTER TABLE calls ADD COLUMN stripe_charge_id VARCHAR(255) NULL AFTER stripe_payment_intent_id',
  'SELECT "calls.stripe_charge_id already present"');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Rows captured before this column existed have to be backfilled by asking
-- Stripe for each intent's latest_charge; that runs in PHP, not here.
