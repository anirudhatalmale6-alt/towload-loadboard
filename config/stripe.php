<?php
// ─── STRIPE CONNECT CONFIGURATION ────────────────────────────────────────────
// Marketplace model:
//   - Providers fund a prepaid balance (ACH preferred, card fallback).
//   - Funds sit in the PLATFORM Stripe balance while a call is live.
//   - On completion we Transfer to the tower's Connect account, minus our fee.
//
// Using Connect matters legally: Stripe is the money transmitter, not us.
// Holding funds in our own bank account would require state money-transmitter
// licensing. Do not "simplify" this into direct bank transfers.

define('STRIPE_SECRET_KEY',      getenv('TL_STRIPE_SECRET')      ?: '');
define('STRIPE_PUBLISHABLE_KEY', getenv('TL_STRIPE_PUBLISHABLE') ?: '');
define('STRIPE_WEBHOOK_SECRET',  getenv('TL_STRIPE_WEBHOOK')     ?: '');

define('STRIPE_API_BASE', 'https://api.stripe.com/v1');

// Connect account type for towers. Express = Stripe hosts onboarding, KYC,
// bank details and the 1099-K. We never store any of it.
define('STRIPE_CONNECT_TYPE', 'express');

// Where Stripe sends the tower back after onboarding
define('STRIPE_CONNECT_REFRESH_URL', APP_URL . '/connect/refresh');
define('STRIPE_CONNECT_RETURN_URL',  APP_URL . '/connect/return');

// Fee reference (informational — used by the UI to explain top-up costs):
//   ACH  0.8% capped at $5.00
//   Card 2.9% + $0.30
define('ACH_FEE_PERCENT', 0.8);
define('ACH_FEE_CAP', 5.00);
define('CARD_FEE_PERCENT', 2.9);
define('CARD_FEE_FIXED', 0.30);
