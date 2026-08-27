-- ═══════════════════════════════════════════════════════════════════════════
--  Rate-limiting the one write endpoint a stranger can reach
--
--  /api/consumer?action=lead takes a name, a phone and an email from anybody,
--  with no account and no payment, and stores them. That is the shape of thing
--  form-spam bots find on their own, and one had already turned up: a lead from
--  Queens NY carrying an email at a throwaway mail domain.
--
--  One junk row is not a problem. Ten thousand is, because the leads list is
--  what decides which city gets opened next, and a poisoned list argues for
--  opening the wrong one.
--
--  The IP is stored so the limit can be counted from the leads themselves
--  rather than adding a second table to keep in step. NULL for every row that
--  predates this, which the count treats as "not this IP" — correct, because we
--  genuinely do not know.
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE coverage_leads
    ADD COLUMN ip VARCHAR(45) NULL AFTER lang;

CREATE INDEX idx_leads_ip_time ON coverage_leads (ip, created_at);
