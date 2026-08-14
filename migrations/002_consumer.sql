-- ═══════════════════════════════════════════════════════════════════════════
--  002 — Direct-to-consumer ("Uber for towing")
--
--  Adds the stranded motorist as a third party. A consumer is NOT a provider:
--  no signup, no subscription, no prepaid balance, no vetting. They land from a
--  Google ad on a phone, get a price, pay by card, and a truck comes.
--
--  Everything downstream is unchanged — a consumer request becomes a normal
--  `calls` row, hits the same board, and settles through the same escrow
--  engine. The only real difference is where the money is held: a provider's
--  prepaid balance, or an authorisation on the customer's card.
-- ═══════════════════════════════════════════════════════════════════════════

-- ─── Consumers are a third account type ──────────────────────────────────────
ALTER TABLE accounts
    MODIFY account_type ENUM('provider','tower','consumer') NOT NULL;

-- ─── Where a call came from, and how it's paid for ───────────────────────────
ALTER TABLE calls
    ADD COLUMN source ENUM('board','consumer') NOT NULL DEFAULT 'board' AFTER call_number,
    -- Consumers never log in. This token is their receipt, their live tracking
    -- link and their proof of identity, so it must be unguessable.
    ADD COLUMN tracking_token VARCHAR(48) NULL AFTER source,
    ADD COLUMN customer_email VARCHAR(255) NULL AFTER customer_phone,
    ADD COLUMN payment_status ENUM('none','authorized','captured','refunded','failed')
        NOT NULL DEFAULT 'none' AFTER awarded_amount,
    ADD COLUMN stripe_payment_intent_id VARCHAR(255) NULL AFTER payment_status,
    -- Exactly how the quoted price was arrived at. Kept so a customer disputing
    -- a charge gets the same numbers we showed them, not a recalculation.
    ADD COLUMN price_breakdown TEXT NULL AFTER stripe_payment_intent_id,
    ADD UNIQUE KEY uniq_tracking_token (tracking_token),
    ADD INDEX idx_source (source, status);

-- ─── Escrow can now be backed by a card, not just a balance ──────────────────
ALTER TABLE escrow_holds
    ADD COLUMN funding_source ENUM('balance','card') NOT NULL DEFAULT 'balance' AFTER amount,
    ADD COLUMN stripe_payment_intent_id VARCHAR(255) NULL AFTER funding_source;

-- ─── Consumer pricing (this is the whole Uber trick) ─────────────────────────
-- A stranded motorist will not name a price. The platform has to quote one,
-- instantly, and be right often enough that towers still accept it.
CREATE TABLE IF NOT EXISTS pricing_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_type ENUM('tow','winch_recovery','lockout','jumpstart','tire_change',
                      'fuel_delivery','impound','transport') NOT NULL,
    vehicle_class ENUM('light','medium','heavy','motorcycle') NOT NULL DEFAULT 'light',

    base_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,   -- the hook-up fee
    included_miles DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    per_mile DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    minimum_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,

    -- Surcharges, as multipliers on the subtotal
    after_hours_multiplier DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    weekend_multiplier DECIMAL(4,2) NOT NULL DEFAULT 1.00,

    -- Flat add-ons for conditions that genuinely cost the tower more
    accident_surcharge DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    no_keys_surcharge DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    wheels_locked_surcharge DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    underground_surcharge DECIMAL(10,2) NOT NULL DEFAULT 0.00,

    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_service_class (service_type, vehicle_class)
) ENGINE=InnoDB;

-- Miami-Dade consent-tow market rates, mid-2026. These are STARTING numbers,
-- editable without a deploy — watch what towers actually accept and adjust.
INSERT INTO pricing_rules
 (service_type, vehicle_class, base_fee, included_miles, per_mile, minimum_total,
  after_hours_multiplier, weekend_multiplier,
  accident_surcharge, no_keys_surcharge, wheels_locked_surcharge, underground_surcharge)
VALUES
 ('tow','light',        95.00, 5, 6.00, 110.00, 1.25, 1.10,  60.00, 35.00, 45.00, 75.00),
 ('tow','medium',      165.00, 5, 9.00, 195.00, 1.25, 1.10, 110.00, 45.00, 65.00, 95.00),
 ('tow','heavy',       425.00, 5,17.00, 495.00, 1.30, 1.15, 250.00, 75.00,125.00,  0.00),
 ('tow','motorcycle',  110.00, 5, 6.00, 125.00, 1.25, 1.10,  50.00,  0.00, 40.00, 60.00),
 ('winch_recovery','light',  145.00, 0, 6.00, 175.00, 1.30, 1.15,  75.00, 35.00, 45.00, 0.00),
 ('winch_recovery','medium', 265.00, 0, 9.00, 310.00, 1.30, 1.15, 125.00, 45.00, 65.00, 0.00),
 ('winch_recovery','heavy',  650.00, 0,17.00, 750.00, 1.35, 1.20, 300.00, 75.00,125.00, 0.00),
 ('lockout','light',      75.00, 0, 0.00,  75.00, 1.20, 1.10, 0.00, 0.00, 0.00, 25.00),
 ('lockout','medium',     95.00, 0, 0.00,  95.00, 1.20, 1.10, 0.00, 0.00, 0.00, 25.00),
 ('jumpstart','light',    75.00, 0, 0.00,  75.00, 1.20, 1.10, 0.00, 0.00, 0.00, 25.00),
 ('jumpstart','medium',   95.00, 0, 0.00,  95.00, 1.20, 1.10, 0.00, 0.00, 0.00, 25.00),
 ('tire_change','light',  85.00, 0, 0.00,  85.00, 1.20, 1.10, 0.00, 0.00, 0.00, 25.00),
 ('tire_change','medium',110.00, 0, 0.00, 110.00, 1.20, 1.10, 0.00, 0.00, 0.00, 25.00),
 ('fuel_delivery','light',75.00, 0, 0.00,  75.00, 1.20, 1.10, 0.00, 0.00, 0.00, 25.00),
 ('fuel_delivery','medium',95.00,0, 0.00,  95.00, 1.20, 1.10, 0.00, 0.00, 0.00, 25.00)
ON DUPLICATE KEY UPDATE service_type = VALUES(service_type);

-- ─── Consumer-side settings ──────────────────────────────────────────────────
INSERT INTO platform_settings (setting_key, setting_value, description) VALUES
-- What the platform keeps on a consumer job. Higher than the 10% board fee
-- because the platform paid for the Google click that produced this customer.
('consumer_fee_percent',   '20.0', 'Platform cut on a direct-from-customer job'),
('consumer_fee_minimum',   '15.00','Floor on a consumer job'),
-- A stranded motorist will not sit through a 20-minute auction.
('consumer_call_expiry_min','12',  'Minutes before a consumer request expires unclaimed'),
('after_hours_start',      '20',   'Hour (24h) after-hours pricing begins'),
('after_hours_end',        '6',    'Hour (24h) after-hours pricing ends'),
('consumer_goa_amount',    '55.00','Charged if the tower arrives and the vehicle is gone')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);
