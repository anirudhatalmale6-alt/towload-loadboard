-- ═══════════════════════════════════════════════════════════════════════════
--  Payout retries
--
--  The Stripe idempotency key was 'payout_<id>' while the request carried a
--  withdrawal id that changed on every attempt. Stripe refuses a reused key
--  whose parameters have moved, so the SECOND attempt at any payout failed
--  with a key error — permanently, and with the original failure overwritten.
--
--  The key now names the attempt. This column is what counts them.
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE payouts
    ADD COLUMN attempt_no SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER status;

-- Anything that already failed has had one attempt, whatever the row says.
UPDATE payouts SET attempt_no = 1
 WHERE attempt_no = 0 AND (failure_reason IS NOT NULL OR status <> 'pending');
