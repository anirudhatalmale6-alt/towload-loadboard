-- ═══════════════════════════════════════════════════════════════════════════
--  004 — Nationwide, surge pricing, tower verification, terms of service
--
--  Four things Ricardo asked for on 2026-08-14, and one he didn't:
--
--   1. Demand-based pricing that moves on its own, capped, overridable by hand.
--   2. Every US state, not just Miami-Dade.
--   3. Document-backed verification of towing companies with a review queue.
--   4. Terms of service accepted, and PROVABLY accepted, by both sides.
--   5. (mine) An emergency brake on surge, because raising towing prices during
--      a declared state of emergency is a criminal matter in Florida and most
--      other states. See `emergency_mode` and `surge_disabled_states` below.
-- ═══════════════════════════════════════════════════════════════════════════

-- ─── PRICING ZONES ───────────────────────────────────────────────────────────
-- A light-duty tow is not the same price in Miami and in rural Georgia, and
-- maintaining a full rate table per city would be unmanageable within a month.
--
-- So: one national base rate table (pricing_rules, zone_id = 0) and a per-market
-- MULTIPLIER here. Opening Tampa is one row, not fifteen. A zone can still carry
-- its own full rate rows when a market really is different — pricing_rules.zone_id
-- points here — but that is the exception, not the setup cost.
--
-- Matching is smallest-first: a city circle beats a state row beats national.
CREATE TABLE IF NOT EXISTS pricing_zones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    name_es VARCHAR(120) NULL,

    -- A zone is EITHER a circle (center + radius) or a whole state (state, radius 0).
    state CHAR(2) NULL,
    center_lat DECIMAL(10,7) NULL,
    center_lng DECIMAL(10,7) NULL,
    radius_miles INT NOT NULL DEFAULT 0,

    -- Scales every line of the national rate table for this market.
    rate_multiplier DECIMAL(4,2) NOT NULL DEFAULT 1.00,

    -- is_live = we have trucks here and will take money.
    -- Not live means the customer is told the truth and captured as a lead
    -- instead of having a card authorised for a job nobody can run.
    is_live TINYINT(1) NOT NULL DEFAULT 0,

    -- Surge, per market. Some states we will never surge in (see settings).
    surge_enabled TINYINT(1) NOT NULL DEFAULT 1,
    -- Admin override. ALWAYS has an expiry: a 1.8x someone set during a storm
    -- and forgot about will quietly destroy conversion for months.
    manual_surge DECIMAL(4,2) NULL,
    manual_surge_until DATETIME NULL,
    manual_surge_note VARCHAR(255) NULL,

    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_state (state, is_active),
    INDEX idx_geo (center_lat, center_lng)
) ENGINE=InnoDB;

-- The launch market, carried over from the Miami-Dade fence.
INSERT INTO pricing_zones (name, name_es, state, center_lat, center_lng, radius_miles,
                           rate_multiplier, is_live, surge_enabled)
SELECT 'Miami-Dade County', 'el Condado de Miami-Dade', 'FL', 25.6100, -80.3000, 35, 1.00, 1, 1
 WHERE NOT EXISTS (SELECT 1 FROM pricing_zones WHERE name = 'Miami-Dade County');

-- Rest of Florida: live, slightly softer rates than the Miami metro.
INSERT INTO pricing_zones (name, name_es, state, radius_miles, rate_multiplier, is_live, surge_enabled)
SELECT 'Florida', 'Florida', 'FL', 0, 0.95, 1, 1
 WHERE NOT EXISTS (SELECT 1 FROM pricing_zones WHERE name = 'Florida');

-- ─── RATE TABLE BECOMES ZONE-AWARE ───────────────────────────────────────────
-- zone_id 0 = the national default table. Not NULL, because MySQL treats every
-- NULL in a unique key as distinct, which would silently allow duplicate
-- national rows for the same service and quietly pick one at random.
ALTER TABLE pricing_rules
    ADD COLUMN zone_id INT NOT NULL DEFAULT 0 AFTER id;

ALTER TABLE pricing_rules DROP INDEX uniq_service_class;
ALTER TABLE pricing_rules ADD UNIQUE KEY uniq_zone_service_class (zone_id, service_type, vehicle_class);

-- ─── SURGE HISTORY ───────────────────────────────────────────────────────────
-- Two jobs. First, answering "why was I charged $340" months later, with the
-- actual demand and supply counts at that minute rather than a re-run against
-- today's rules. Second, this IS the training data — when there's enough volume
-- to fit a real model, the inputs and the outcomes are already recorded.
--
-- One row per zone per minute at most, so a bot hammering the quote endpoint
-- cannot inflate the table.
CREATE TABLE IF NOT EXISTS surge_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    zone_id INT NOT NULL DEFAULT 0,
    minute_bucket DATETIME NOT NULL,
    open_demand INT NOT NULL DEFAULT 0,
    available_supply INT NOT NULL DEFAULT 0,
    unclaimed_recent INT NOT NULL DEFAULT 0,
    ratio DECIMAL(6,3) NOT NULL DEFAULT 0.000,
    multiplier DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    reason VARCHAR(60) NOT NULL DEFAULT 'computed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_zone_minute (zone_id, minute_bucket),
    INDEX idx_time (minute_bucket)
) ENGINE=InnoDB;

-- ─── WHAT SURGE DID TO THIS SPECIFIC JOB ─────────────────────────────────────
-- Frozen onto the call at request time. Surge NEVER applies retroactively: the
-- number the customer tapped Confirm on is the number they pay, whatever the
-- market does in the twelve minutes afterwards.
ALTER TABLE calls
    ADD COLUMN zone_id INT NOT NULL DEFAULT 0 AFTER source,
    ADD COLUMN surge_multiplier DECIMAL(4,2) NOT NULL DEFAULT 1.00 AFTER price_breakdown,
    ADD COLUMN surge_reason VARCHAR(60) NULL AFTER surge_multiplier,
    ADD COLUMN surge_demand INT NULL AFTER surge_reason,
    ADD COLUMN surge_supply INT NULL AFTER surge_demand,
    ADD INDEX idx_zone_status (zone_id, status);

-- ─── COVERAGE LEADS ──────────────────────────────────────────────────────────
-- Someone clicked the ad, needed a truck, and we had nobody within range.
--
-- The wrong answer is to take the job anyway: the card gets authorised, no one
-- accepts, it expires, and you have paid for a click to produce an angry person.
-- The right answer is to say so, keep their number, and — more valuable — keep
-- the pin. A cluster of these is a map of exactly where to go recruit trucks.
CREATE TABLE IF NOT EXISTS coverage_leads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kind ENUM('customer','tower') NOT NULL DEFAULT 'customer',
    name VARCHAR(150),
    phone VARCHAR(30),
    email VARCHAR(255),
    service_type VARCHAR(40),
    pickup_address VARCHAR(255),
    city VARCHAR(100),
    state CHAR(2),
    zip VARCHAR(20),
    lat DECIMAL(10,7),
    lng DECIMAL(10,7),
    quoted_total DECIMAL(10,2) NULL,
    trucks_nearby INT NOT NULL DEFAULT 0,
    utm_source VARCHAR(80),
    lang CHAR(2) NOT NULL DEFAULT 'es',
    notes VARCHAR(500),
    contacted TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_geo (lat, lng),
    INDEX idx_state (state, created_at),
    INDEX idx_contacted (contacted, created_at)
) ENGINE=InnoDB;

-- ─── VERIFICATION DOCUMENTS ──────────────────────────────────────────────────
-- The four Ricardo named, added to the insurance types already here.
ALTER TABLE compliance_docs
    MODIFY doc_type ENUM('coi_liability','coi_garage_keepers','coi_on_hook','w9',
                         'business_license','tow_license','dot_authority',
                         'ein_letter','state_registration','owner_id','other') NOT NULL;

-- Stored path is NOT a public URL — see includes/uploads.php. These files are
-- scans of driver's licences and insurance policies; they are served only
-- through an authenticated endpoint, never linked directly.
ALTER TABLE compliance_docs
    ADD COLUMN stored_path VARCHAR(255) NULL AFTER file_url,
    ADD COLUMN mime_type VARCHAR(100) NULL AFTER stored_path,
    ADD COLUMN file_size INT NULL AFTER mime_type,
    ADD COLUMN uploaded_by_user_id INT NULL AFTER file_size;

ALTER TABLE accounts
    ADD COLUMN legal_name VARCHAR(255) NULL AFTER name,
    ADD COLUMN ein VARCHAR(20) NULL AFTER legal_name,
    ADD COLUMN docs_submitted_at DATETIME NULL AFTER verified_at,
    ADD COLUMN reviewed_by_admin_id INT NULL AFTER docs_submitted_at;

-- ─── TERMS OF SERVICE ────────────────────────────────────────────────────────
-- "They agreed to the terms" is worth nothing in a dispute unless you can show
-- WHICH terms, WHEN, and from where. Versioned documents, and an immutable
-- acceptance row per person per version.
CREATE TABLE IF NOT EXISTS legal_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doc_key ENUM('terms_customer','terms_tower','privacy') NOT NULL,
    version VARCHAR(20) NOT NULL,
    locale CHAR(2) NOT NULL DEFAULT 'es',
    title VARCHAR(200) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    effective_at DATETIME NOT NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_doc_version_locale (doc_key, version, locale),
    INDEX idx_current (doc_key, locale, is_current)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS agreement_acceptances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NULL,
    user_id INT NULL,
    call_id INT NULL,
    doc_key VARCHAR(30) NOT NULL,
    version VARCHAR(20) NOT NULL,
    locale CHAR(2) NOT NULL DEFAULT 'es',
    accepted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    user_agent VARCHAR(400),
    INDEX idx_account (account_id, doc_key),
    INDEX idx_call (call_id)
) ENGINE=InnoDB;

-- ─── ADMIN AUDIT ─────────────────────────────────────────────────────────────
-- Approvals, rejections, price overrides and the emergency switch are all
-- decisions someone may need to answer for later. Who, what, when, from where.
CREATE TABLE IF NOT EXISTS admin_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    action VARCHAR(60) NOT NULL,
    detail VARCHAR(500),
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_time (admin_id, created_at),
    INDEX idx_action (action, created_at)
) ENGINE=InnoDB;

-- ─── SETTINGS ────────────────────────────────────────────────────────────────
INSERT INTO platform_settings (setting_key, setting_value, description) VALUES

-- The fence comes down. Coverage is now decided per request by whether there
-- are actually approved trucks in range, which is the honest test — a state
-- code says nothing about whether anyone will answer at 2am.
('launch_radius_miles', '0', 'Legacy geofence radius. 0 = nationwide, coverage decided per request'),
('coverage_radius_miles', '35', 'How far out we look for an approved truck before calling an area uncovered'),
('min_trucks_for_coverage', '1', 'Approved trucks needed in range before we will take a card'),

-- ── Surge ──
('surge_enabled',        '1',   'Master switch for demand-based pricing'),
('surge_max_multiplier', '1.8', 'Hard ceiling. The algorithm can never exceed this'),
('surge_window_minutes', '15',  'Rolling window for counting live demand'),
('surge_unclaimed_minutes','60','Lookback for jobs that expired with nobody accepting'),
('surge_unclaimed_weight','0.5','How much an unclaimed job counts toward demand'),
('surge_min_demand',     '2',   'Below this many open jobs, do not surge — one request is noise'),
('surge_tiers',          '0.75:1.0,1.25:1.1,1.75:1.25,2.5:1.4,3.5:1.6,999:1.8',
                                'ratio:multiplier steps, ascending. Editable without a deploy'),

-- ── The emergency brake ──
-- Florida Statute 501.160 and equivalents in ~35 states make raising the price
-- of essential services during a declared emergency unlawful, and towing is
-- frequently named outright. A hurricane is simultaneously the biggest demand
-- spike this platform will ever see and the exact moment surge becomes illegal.
-- One switch, effective instantly, everywhere.
('emergency_mode',       '0',   'EMERGENCY BRAKE: forces every surge multiplier to 1.0 platform-wide'),
('surge_disabled_states','',    'Comma-separated state codes where surge never applies, e.g. FL,TX'),

-- ── Verification ──
('required_tower_docs',  'ein_letter,state_registration,coi_liability,owner_id',
                                'Documents a towing company must upload before review'),
('auto_submit_for_review','1',  'Flip an account to Pending Review automatically once all docs are in'),
('max_upload_mb',        '12',  'Per-file upload ceiling'),

-- ── Terms ──
('terms_version',        '1.0', 'Current terms version customers and towers must accept'),
('require_terms_accept', '1',   'Block signup and job requests without an explicit acceptance'),

-- ── Tower economics ──
('tower_minimum_net',    '0.00','Floor on what a tower clears after the fee. 0 = no floor')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

-- Miami-Dade is no longer THE market, it is the first one.
UPDATE platform_settings SET setting_value = '0' WHERE setting_key = 'launch_radius_miles';
