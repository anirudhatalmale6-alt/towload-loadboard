-- ═══════════════════════════════════════════════════════════════════════════
--  TowLoad - Towing Loadboard / Marketplace
--  Connects motor clubs & towing providers (demand) with towing companies (supply)
--
--  Separate product from TowMasters, but designed to integrate with it:
--  see `tower_integrations` at the bottom.
-- ═══════════════════════════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS towload CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE towload;

-- ─── ACCOUNTS (both sides of the marketplace) ────────────────────────────────
-- account_type decides which side of the board you're on.
--   provider = motor club / towing provider / dispatcher who POSTS calls
--   tower    = towing company who ACCEPTS calls
CREATE TABLE accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_type ENUM('provider','tower') NOT NULL,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(30),
    address VARCHAR(255),
    city VARCHAR(100),
    state VARCHAR(2),
    zip VARCHAR(20),
    lat DECIMAL(10,7),
    lng DECIMAL(10,7),
    logo_url VARCHAR(500),
    website VARCHAR(255),

    -- Vetting. Nobody transacts until approved.
    verification_status ENUM('unverified','pending','approved','rejected','suspended')
        NOT NULL DEFAULT 'unverified',
    verified_at DATETIME NULL,
    rejection_reason VARCHAR(500) NULL,

    -- Reputation (denormalised from ratings for fast board rendering)
    rating_avg DECIMAL(3,2) NOT NULL DEFAULT 0.00,
    rating_count INT NOT NULL DEFAULT 0,
    jobs_completed INT NOT NULL DEFAULT 0,
    jobs_goa INT NOT NULL DEFAULT 0,
    jobs_canceled_by_self INT NOT NULL DEFAULT 0,

    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_type_status (account_type, verification_status),
    INDEX idx_geo (lat, lng)
) ENGINE=InnoDB;

-- ─── USERS ───────────────────────────────────────────────────────────────────
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    phone VARCHAR(30),
    role ENUM('owner','dispatcher','driver') NOT NULL DEFAULT 'dispatcher',
    avatar_url VARCHAR(500),
    device_token VARCHAR(255),
    push_platform ENUM('ios','android','web') NULL,
    last_login_at DATETIME,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_email (email),
    INDEX idx_account (account_id),
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── TOWER PROFILE (supply-side capability + coverage) ────────────────────────
-- Drives matching. A call is only shown to towers who can actually run it.
CREATE TABLE tower_profiles (
    account_id INT PRIMARY KEY,
    dot_number VARCHAR(30),
    mc_number VARCHAR(30),

    -- Coverage: circle around a base point. Simple, fast, good enough to launch.
    -- Polygon/zip-list coverage can layer on later without touching this.
    service_radius_miles INT NOT NULL DEFAULT 25,
    base_lat DECIMAL(10,7),
    base_lng DECIMAL(10,7),

    -- Capability flags. Matching ANDs the call's requirement against these.
    has_light_duty TINYINT(1) NOT NULL DEFAULT 1,
    has_medium_duty TINYINT(1) NOT NULL DEFAULT 0,
    has_heavy_duty TINYINT(1) NOT NULL DEFAULT 0,
    has_flatbed TINYINT(1) NOT NULL DEFAULT 1,
    has_wheel_lift TINYINT(1) NOT NULL DEFAULT 0,
    has_winch_recovery TINYINT(1) NOT NULL DEFAULT 0,
    has_lockout TINYINT(1) NOT NULL DEFAULT 1,
    has_jumpstart TINYINT(1) NOT NULL DEFAULT 1,
    has_tire_change TINYINT(1) NOT NULL DEFAULT 1,
    has_fuel_delivery TINYINT(1) NOT NULL DEFAULT 1,
    has_motorcycle TINYINT(1) NOT NULL DEFAULT 0,
    has_ev_certified TINYINT(1) NOT NULL DEFAULT 0,
    has_lowclearance TINYINT(1) NOT NULL DEFAULT 0,

    is_24_7 TINYINT(1) NOT NULL DEFAULT 0,
    trucks_count INT NOT NULL DEFAULT 1,
    accepts_auto_dispatch TINYINT(1) NOT NULL DEFAULT 1,

    -- Stripe Connect (Express). Stripe holds KYC + bank details, not us.
    stripe_account_id VARCHAR(255) NULL,
    stripe_charges_enabled TINYINT(1) NOT NULL DEFAULT 0,
    stripe_payouts_enabled TINYINT(1) NOT NULL DEFAULT 0,
    stripe_details_submitted TINYINT(1) NOT NULL DEFAULT 0,
    stripe_requirements_due TEXT NULL,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_base_geo (base_lat, base_lng),
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── PROVIDER PROFILE (demand side) ──────────────────────────────────────────
CREATE TABLE provider_profiles (
    account_id INT PRIMARY KEY,
    -- 'escrow'  = prepaid balance, funds held per call (launch default)
    -- 'invoice' = net-30/45 terms, no prepay. For the big motor clubs, phase 2.
    billing_mode ENUM('escrow','invoice') NOT NULL DEFAULT 'escrow',
    credit_limit DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    net_terms_days INT NOT NULL DEFAULT 0,

    default_goa_amount DECIMAL(10,2) NOT NULL DEFAULT 45.00,
    default_call_expiry_minutes INT NOT NULL DEFAULT 20,
    auto_award_lowest_bid TINYINT(1) NOT NULL DEFAULT 0,

    stripe_customer_id VARCHAR(255) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── COMPLIANCE DOCUMENTS (the trust layer) ──────────────────────────────────
-- Insurance is the one that matters. An expired COI must block dispatch,
-- which is why expires_at is indexed and checked at accept time, not at upload.
CREATE TABLE compliance_docs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    doc_type ENUM('coi_liability','coi_garage_keepers','coi_on_hook','w9',
                  'business_license','tow_license','dot_authority','other') NOT NULL,
    file_url VARCHAR(500) NOT NULL,
    file_name VARCHAR(255),
    policy_number VARCHAR(100),
    carrier_name VARCHAR(255),
    coverage_amount DECIMAL(12,2) NULL,
    issued_at DATE NULL,
    expires_at DATE NULL,
    status ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    review_notes VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_account_type (account_id, doc_type),
    INDEX idx_expiry (expires_at, status),
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── TOWER SUBSCRIPTIONS (the revenue model) ─────────────────────────────────
CREATE TABLE subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    plan ENUM('trial','starter','pro','fleet') NOT NULL DEFAULT 'trial',
    status ENUM('trialing','active','past_due','canceled','incomplete')
        NOT NULL DEFAULT 'trialing',
    stripe_subscription_id VARCHAR(255) NULL,
    stripe_price_id VARCHAR(255) NULL,
    monthly_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    trial_ends_at DATETIME NULL,
    current_period_end DATETIME NULL,
    canceled_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_account (account_id),
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── CALLS (the loads) ───────────────────────────────────────────────────────
CREATE TABLE calls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    call_number VARCHAR(24) NOT NULL UNIQUE,
    provider_account_id INT NOT NULL,
    posted_by_user_id INT NULL,

    service_type ENUM('tow','winch_recovery','lockout','jumpstart','tire_change',
                      'fuel_delivery','impound','transport') NOT NULL DEFAULT 'tow',
    vehicle_class ENUM('light','medium','heavy','motorcycle') NOT NULL DEFAULT 'light',

    -- Pickup
    pickup_address VARCHAR(255) NOT NULL,
    pickup_city VARCHAR(100),
    pickup_state VARCHAR(2),
    pickup_zip VARCHAR(20),
    pickup_lat DECIMAL(10,7) NOT NULL,
    pickup_lng DECIMAL(10,7) NOT NULL,
    pickup_notes VARCHAR(500),

    -- Dropoff (null for non-tow services)
    dropoff_address VARCHAR(255),
    dropoff_city VARCHAR(100),
    dropoff_state VARCHAR(2),
    dropoff_zip VARCHAR(20),
    dropoff_lat DECIMAL(10,7),
    dropoff_lng DECIMAL(10,7),
    tow_miles DECIMAL(7,2) NULL,

    -- Vehicle
    vehicle_year VARCHAR(4),
    vehicle_make VARCHAR(60),
    vehicle_model VARCHAR(60),
    vehicle_color VARCHAR(40),
    vehicle_plate VARCHAR(20),
    vehicle_vin VARCHAR(20),

    -- Condition flags: these change which truck can run the call.
    has_keys TINYINT(1) NOT NULL DEFAULT 1,
    wheels_lock TINYINT(1) NOT NULL DEFAULT 1,
    is_accident TINYINT(1) NOT NULL DEFAULT 0,
    is_underground TINYINT(1) NOT NULL DEFAULT 0,
    needs_flatbed TINYINT(1) NOT NULL DEFAULT 0,
    is_ev TINYINT(1) NOT NULL DEFAULT 0,

    -- Customer (masked from the board until awarded)
    customer_name VARCHAR(150),
    customer_phone VARCHAR(30),

    -- Money
    pricing_mode ENUM('accept','bid') NOT NULL DEFAULT 'accept',
    offer_amount DECIMAL(10,2) NOT NULL,        -- accept: the price. bid: the ceiling/target.
    goa_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    awarded_amount DECIMAL(10,2) NULL,
    platform_fee DECIMAL(10,2) NULL,
    tower_net DECIMAL(10,2) NULL,

    -- Timing. A towing call dies fast; expires_at is enforced, not decorative.
    scheduled_for DATETIME NULL,                 -- NULL = now / ASAP
    expires_at DATETIME NOT NULL,
    eta_required_minutes INT NULL,

    status ENUM('draft','open','awarded','en_route','on_scene','in_progress',
                'completed','goa','canceled','expired','disputed')
        NOT NULL DEFAULT 'open',

    awarded_tower_account_id INT NULL,
    awarded_at DATETIME NULL,
    awarded_eta_minutes INT NULL,
    en_route_at DATETIME NULL,
    on_scene_at DATETIME NULL,
    completed_at DATETIME NULL,
    canceled_at DATETIME NULL,
    cancel_reason VARCHAR(500),

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_board (status, expires_at),
    INDEX idx_pickup_geo (pickup_lat, pickup_lng),
    INDEX idx_provider (provider_account_id, status),
    INDEX idx_awarded (awarded_tower_account_id, status),
    FOREIGN KEY (provider_account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── BIDS ────────────────────────────────────────────────────────────────────
CREATE TABLE bids (
    id INT AUTO_INCREMENT PRIMARY KEY,
    call_id INT NOT NULL,
    tower_account_id INT NOT NULL,
    bid_by_user_id INT NULL,
    amount DECIMAL(10,2) NOT NULL,
    eta_minutes INT NOT NULL,
    note VARCHAR(500),
    status ENUM('pending','accepted','rejected','withdrawn','expired')
        NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_call_tower (call_id, tower_account_id),
    INDEX idx_call_status (call_id, status),
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE,
    FOREIGN KEY (tower_account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── CALL EVENTS (audit trail; also the dispute evidence) ────────────────────
CREATE TABLE call_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    call_id INT NOT NULL,
    account_id INT NULL,
    user_id INT NULL,
    event_type VARCHAR(50) NOT NULL,
    detail VARCHAR(500),
    lat DECIMAL(10,7) NULL,
    lng DECIMAL(10,7) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_call (call_id, created_at),
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── PROOF OF SERVICE ────────────────────────────────────────────────────────
CREATE TABLE call_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    call_id INT NOT NULL,
    uploaded_by_user_id INT NULL,
    photo_type ENUM('arrival','pre_tow','hookup','damage','dropoff','signature','goa','other')
        NOT NULL DEFAULT 'other',
    file_url VARCHAR(500) NOT NULL,
    lat DECIMAL(10,7) NULL,
    lng DECIMAL(10,7) NULL,
    taken_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_call (call_id),
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ═══ MONEY ═══════════════════════════════════════════════════════════════════

-- Provider prepaid balance. One row per provider; the authoritative number is
-- always derivable from ledger_entries, this is the fast read.
CREATE TABLE provider_balances (
    account_id INT PRIMARY KEY,
    available DECIMAL(12,2) NOT NULL DEFAULT 0.00,   -- spendable
    held DECIMAL(12,2) NOT NULL DEFAULT 0.00,        -- escrowed against open calls
    lifetime_funded DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    lifetime_spent DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Append-only money log. Never UPDATE a row here; write a reversing entry.
CREATE TABLE ledger_entries (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    call_id INT NULL,
    entry_type ENUM('topup','hold','hold_release','hold_refund','payout',
                    'platform_fee','refund','adjustment','subscription')
        NOT NULL,
    amount DECIMAL(12,2) NOT NULL,   -- signed: + increases available, - decreases
    balance_after DECIMAL(12,2) NULL,
    description VARCHAR(255),
    stripe_ref VARCHAR(255) NULL,
    created_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_account_time (account_id, created_at),
    INDEX idx_call (call_id),
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Provider top-ups (ACH preferred: 0.8% capped at $5 vs 2.9%+30c on cards)
CREATE TABLE topups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    method ENUM('ach','card') NOT NULL DEFAULT 'ach',
    status ENUM('pending','processing','succeeded','failed','canceled')
        NOT NULL DEFAULT 'pending',
    stripe_payment_intent_id VARCHAR(255) NULL,
    failure_reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    settled_at DATETIME NULL,

    INDEX idx_account (account_id, status),
    UNIQUE KEY uniq_pi (stripe_payment_intent_id),
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- One row per awarded call. This is the escrow.
CREATE TABLE escrow_holds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    call_id INT NOT NULL,
    provider_account_id INT NOT NULL,
    tower_account_id INT NULL,
    amount DECIMAL(10,2) NOT NULL,          -- held from provider balance
    released_amount DECIMAL(10,2) NULL,     -- what the tower actually earned
    platform_fee DECIMAL(10,2) NULL,
    refunded_amount DECIMAL(10,2) NULL,     -- returned to provider
    status ENUM('held','released','refunded','partial','disputed')
        NOT NULL DEFAULT 'held',
    released_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_call (call_id),
    INDEX idx_provider (provider_account_id, status),
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Stripe Connect transfers out to towers
CREATE TABLE payouts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tower_account_id INT NOT NULL,
    call_id INT NULL,
    escrow_hold_id INT NULL,
    gross_amount DECIMAL(10,2) NOT NULL,
    platform_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    net_amount DECIMAL(10,2) NOT NULL,
    status ENUM('pending','paid','failed','reversed') NOT NULL DEFAULT 'pending',
    stripe_transfer_id VARCHAR(255) NULL,
    failure_reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    paid_at DATETIME NULL,

    INDEX idx_tower (tower_account_id, status),
    FOREIGN KEY (tower_account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── DISPUTES ────────────────────────────────────────────────────────────────
CREATE TABLE disputes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    call_id INT NOT NULL,
    opened_by_account_id INT NOT NULL,
    reason ENUM('no_show','late','damage','wrong_amount','goa_disputed',
                'service_not_performed','other') NOT NULL,
    detail TEXT,
    status ENUM('open','under_review','resolved_provider','resolved_tower','resolved_split')
        NOT NULL DEFAULT 'open',
    resolution_note TEXT,
    provider_amount DECIMAL(10,2) NULL,
    tower_amount DECIMAL(10,2) NULL,
    resolved_by_user_id INT NULL,
    resolved_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_status (status),
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── RATINGS (two-way, like every marketplace that works) ────────────────────
CREATE TABLE ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    call_id INT NOT NULL,
    rater_account_id INT NOT NULL,
    rated_account_id INT NOT NULL,
    stars TINYINT NOT NULL,
    comment VARCHAR(1000),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_call_rater (call_id, rater_account_id),
    INDEX idx_rated (rated_account_id),
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── NOTIFICATIONS ───────────────────────────────────────────────────────────
CREATE TABLE notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    user_id INT NULL,
    call_id INT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(150) NOT NULL,
    body VARCHAR(500),
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_account_read (account_id, is_read, created_at),
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── TOWMASTERS INTEGRATION ──────────────────────────────────────────────────
-- Links a loadboard tower account to their TowMasters company so an awarded
-- call drops straight into their dispatch board. This is the moat: no other
-- loadboard can push the job into the tower's own software.
CREATE TABLE tower_integrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    provider VARCHAR(40) NOT NULL DEFAULT 'towmasters',
    remote_company_id INT NULL,          -- towbook_saas.companies.id
    api_key VARCHAR(255) NULL,
    api_base_url VARCHAR(255) NULL,
    auto_push_awarded TINYINT(1) NOT NULL DEFAULT 1,
    auto_pull_status TINYINT(1) NOT NULL DEFAULT 1,
    last_sync_at DATETIME NULL,
    last_error VARCHAR(500) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_account_provider (account_id, provider),
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Maps a loadboard call to the dispatch record it created in TowMasters,
-- so status flows back without duplicating jobs.
CREATE TABLE call_sync_map (
    id INT AUTO_INCREMENT PRIMARY KEY,
    call_id INT NOT NULL,
    account_id INT NOT NULL,
    remote_call_id INT NULL,
    last_pushed_status VARCHAR(40) NULL,
    last_synced_at DATETIME NULL,

    UNIQUE KEY uniq_call_account (call_id, account_id),
    FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── PLATFORM SETTINGS (fee %, geo gating, etc. — no redeploy to change) ─────
CREATE TABLE platform_settings (
    setting_key VARCHAR(60) PRIMARY KEY,
    setting_value VARCHAR(500) NOT NULL,
    description VARCHAR(255),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO platform_settings (setting_key, setting_value, description) VALUES
('platform_fee_percent',    '10.0',  'Percent of the awarded amount kept by the platform'),
('platform_fee_minimum',    '5.00',  'Floor so small calls still cover Stripe fees'),
('default_call_expiry_min', '20',    'Minutes a call stays on the board before expiring'),
('min_topup_amount',        '250.00','Minimum provider balance top-up'),
('launch_states',           '',      'Comma-separated state codes. Empty = open nationwide'),
('require_coi_to_accept',   '1',     'Block accepting calls without an unexpired liability COI'),
('goa_requires_photo',      '1',     'GOA payout requires a geotagged arrival photo');

-- ─── ADMIN USERS (platform staff — Ricardo & co) ─────────────────────────────
CREATE TABLE admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(150) NOT NULL,
    role ENUM('superadmin','support','finance') NOT NULL DEFAULT 'support',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
