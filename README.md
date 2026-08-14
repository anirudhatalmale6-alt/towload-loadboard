# TowLoad — Towing Loadboard / Marketplace

Connects motor clubs and towing providers (demand) with towing companies (supply).
DAT loadboard for freight, but built for towing: calls die in minutes, insurance
has to be current at the moment of dispatch, and GOA is a real outcome that has
to settle fairly.

Separate product from TowMasters, designed to integrate with it.

---

## Live

**https://bot24.io/towload/** — deployed and working against a real database.

Demo logins:
- Tower — `demo.tower@towload.test` / `TowLoad2026!`
- Provider — `demo.provider@towload.test` / `TowLoad2026!`

Launch market is fenced to **Miami-Dade County** (35-mile radius from 25.61, -80.30).
Signups and postings outside it are refused.

> **Temporary database.** The app currently lives in the pre-existing empty
> `towmasters` database because a new one can only be created from the DreamHost
> panel. Move it to a dedicated `towload` database when one exists: mysqldump,
> import, change four values in `config/env.php`. No code changes.

## Status

Foundation is built and tested end to end against a real MySQL instance.

| Area | State |
|---|---|
| Schema (22 tables) | Done |
| Auth — provider + tower accounts, teams, roles | Done |
| Posting a call, funded escrow hold | Done |
| The board — radius + capability matching, PII masking | Done |
| Accept flow (fixed price) | Done |
| Bid flow (submit / withdraw / award) | Done |
| Status timeline, proof-of-service photos | Done |
| Completion, GOA, cancellation, expiry sweep | Done |
| Escrow engine + append-only ledger | Done, verified |
| Stripe Connect onboarding + payouts | Built, needs live keys |
| Provider funding (ACH + card) | Built, needs live keys |
| Stripe webhooks | Built, needs live keys |
| Clean URLs (no `.php` anywhere) | Done |
| Miami-Dade launch geofence | Done |
| Compliance doc review UI | Not started |
| TowMasters dispatch push | Schema in place, sync not written |
| Push notifications | Table in place, delivery not wired |

---

## Money model

- **Towers** pay a monthly subscription.
- **Providers** prepay a balance. Posting a call **holds** the offer amount, so
  every call on the board is visibly funded.
- On completion the hold releases to the tower minus the platform fee; anything
  unspent returns to the provider.
- **GOA** splits the hold — tower gets the GOA fee, provider gets the rest back.
- Fee is `platform_fee_percent` with a `platform_fee_minimum` floor, both in
  `platform_settings` so they change without a deploy.

### Why Stripe Connect and not our own bank account

Connect makes **Stripe** the money transmitter. Holding customer funds in our own
account would require state money-transmitter licensing — bonding, audits, six
figures. Do not "simplify" this into direct bank transfers.

### Why ACH is the default funding method

| Top-up | ACH (0.8%, $5 cap) | Card (2.9% + $0.30) |
|---|---|---|
| $500 | $4.00 | $14.80 |
| $2,000 | $5.00 | $58.30 |
| $10,000 | $5.00 | $290.30 |

Cards exist only so a brand new account can start the same day.

---

## Escrow invariants

Every dollar moves through one of four functions in `includes/escrow.php`.
Nothing else may write `provider_balances` or `escrow_holds`.

```
escrowHold()            available -> held        (call posted)
escrowRelease()         held -> payout + fee     (completed)
escrowRefund()          held -> available        (canceled / expired)
escrowPartialRelease()  held -> split both ways  (GOA / dispute)
```

**Invariant:** for any account, the sum of `ledger_entries.amount` equals
`provider_balances.available`. Money in `held` is money that has already left
available and is recorded as a negative `hold` entry.

`ledger_entries` is append-only. Never UPDATE a row — write a reversing entry.

`creditTopup()` is idempotent on the Stripe PaymentIntent id. Stripe retries
webhooks; double-crediting a balance is the worst bug this system could have.

---

## URLs

No `.php` appears anywhere. `.htaccess` maps `/api/{file}/{action}` onto
`api/{file}.php?action={action}`, and any direct `.php` URL 301s to its clean
form so the extension cannot leak into a link or a search index.

    /                        the app
    /api/auth/login          api/auth.php?action=login
    /api/calls/board?radius= api/calls.php?action=board&radius=
    /webhooks/stripe         webhooks/stripe.php

Two gotchas that cost real debugging time here, both handled in `.htaccess`:

1. **PHP runs as CGI/FPM on this host and drops the `Authorization` header.**
   Every authenticated call returns "Authorization required" until it is passed
   through explicitly. The API also accepts `X-Auth-Token` as a fallback.
2. **`RewriteRule ^$` loses to mod_dir**, so the folder root 403s. Use
   `DirectoryIndex`, not a rewrite, to serve the app.

## Layout

```
config/     app, database, stripe, env.php (server-only, gitignored)
includes/   helpers, escrow engine, matching rules, stripe wrapper
api/        auth, calls, bids, wallet, connect
webhooks/   stripe
index.html  the loadboard UI
.htaccess   clean URLs + hardening
schema.sql
```

## Endpoints

**auth.php** — `register`, `login`, `me`, `update-profile`, `change-password`, `team`, `invite`
**calls.php** — `create`, `board`, `detail`, `accept`, `award`, `status`, `complete`, `goa`, `cancel`, `my-calls`, `expire-sweep`
**bids.php** — `create`, `withdraw`, `for-call`, `mine`
**wallet.php** — `balance`, `transactions`, `topup`, `topups`, `fee-preview`, `earnings`
**connect.php** — `onboard`, `status`, `dashboard`, `process-payouts`

## Cron

```
* * * * *   curl -s https://bot24.io/towload/api/calls/expire-sweep
*/5 * * * * curl -s https://bot24.io/towload/api/connect/process-payouts
```

The expiry sweep matters: without it a provider's money sits frozen behind dead
calls nobody took.

## Config

All secrets come from the environment; the committed defaults are placeholders.

```
TL_DB_HOST TL_DB_NAME TL_DB_USER TL_DB_PASS   (or TL_DB_SOCKET locally)
TL_JWT_SECRET
TL_STRIPE_SECRET TL_STRIPE_PUBLISHABLE TL_STRIPE_WEBHOOK
TL_APP_NAME TL_APP_URL
```

---

## Design decisions worth keeping

**Funds are held at posting, not at award.** Every call on the board is funded.
That is the reason towers show up, and it's the whole point of running escrow.

**Customer PII is masked until awarded.** Board rows show `Robert C.` and
`(•••) •••-1234`. Without this both sides go around the platform and the
marketplace dies.

**Insurance is checked at accept time, not at upload time.** A certificate that
expired last week must stop dispatch today.

**Accept locks the call row.** Two towers hitting Accept in the same second is
the most likely race in the system; it is handled with `SELECT ... FOR UPDATE`.

**Cancelling on a rolling tower isn't free.** They get the GOA amount. Without
that rule nobody would ever accept a call.

**Bids can't exceed the funded amount.** A bid above the hold would leave the job
underfunded, so it's rejected at submission rather than at award.

---

## Phase 2

- Big motor clubs (Agero, Quest, Allied, AAA) will not prefund an escrow balance;
  they pay net 30/45. `provider_profiles.billing_mode = 'invoice'` and
  `credit_limit` already support this — the invoicing itself isn't built.
- TowMasters dispatch push: `tower_integrations` + `call_sync_map` are in place.
  An awarded call should create the dispatch record in the tower's own TowMasters
  account and pull status back. This is the moat — no other loadboard can put the
  job inside the tower's software.
