-- ═══════════════════════════════════════════════════════════════════════════
--  014 — The fleet a towing company actually owns
--
--  tower_profiles already carries has_flatbed / has_heavy_duty / etc, and those
--  stay the single source of truth for MATCHING — they decide which jobs reach
--  which company. This table is the inventory behind them: the individual
--  trucks, so an operator can keep their own record and so trucks_count stops
--  being a number typed once at signup and never corrected.
--
--  Deliberately NOT wired into matching. Deriving capability from this list
--  would mean a company that had not finished entering its trucks silently
--  stopped receiving the work it had been getting the day before.
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS tower_trucks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,

    -- What the company calls it on the radio. Free text on purpose: "Truck 12",
    -- "Big Blue" and "305-Unit-3" are all real answers.
    label VARCHAR(80) NOT NULL,
    truck_type ENUM('flatbed','wheel_lift','wrecker','heavy_wrecker','service_van','other')
        NOT NULL DEFAULT 'flatbed',
    capacity_class ENUM('light','medium','heavy') NOT NULL DEFAULT 'light',

    make VARCHAR(60) NULL,
    model VARCHAR(60) NULL,
    year SMALLINT NULL,
    plate VARCHAR(20) NULL,

    -- Comma-separated equipment keys carried on this truck (dollies, winch,
    -- straps, ev_kit, low_clearance...). A list rather than a column each so
    -- adding one later is a translation string, not a migration.
    equipment VARCHAR(500) NULL,
    notes VARCHAR(255) NULL,

    -- Soft delete. A truck can be attached to a completed job's history, and a
    -- company retiring a unit should not rewrite what happened last month.
    is_active TINYINT(1) NOT NULL DEFAULT 1,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_acct (account_id, is_active),
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB;
