-- Migration 015: Tax Preparation Records
-- Stores locked-in tax data by period (quarterly or annual).
-- Records are non-destructive from the application (delete only in dev).

CREATE TABLE IF NOT EXISTS CG_TaxRecords (
    tax_record_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tax_year             SMALLINT UNSIGNED NOT NULL,
    tax_quarter          TINYINT UNSIGNED DEFAULT NULL,  -- NULL = full year, 1-4 = quarter
    period_type          ENUM('quarterly','annual') NOT NULL,

    -- Income (what came in)
    total_payouts        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    paypal_income        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    gross_income         DECIMAL(12,2) NOT NULL DEFAULT 0.00,

    -- Cost of Goods Sold
    item_costs           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    paypal_purchases     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_cogs           DECIMAL(12,2) NOT NULL DEFAULT 0.00,

    -- Operating Expenses
    platform_fees        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    shipping_costs       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    general_costs        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_operating      DECIMAL(12,2) NOT NULL DEFAULT 0.00,

    -- Deductions (user-entered)
    phone_amount         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    phone_pct            TINYINT UNSIGNED NOT NULL DEFAULT 0,
    phone_deduction      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    mileage_miles        DECIMAL(10,1) NOT NULL DEFAULT 0.0,
    mileage_rate         DECIMAL(5,3) NOT NULL DEFAULT 0.670,
    mileage_deduction    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    equipment_deduction  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    supplies_deduction   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    advertising_deduction DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    other_deduction      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    deduction_notes      TEXT DEFAULT NULL,
    total_deductions     DECIMAL(12,2) NOT NULL DEFAULT 0.00,

    -- Summary
    gross_profit         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    net_profit           DECIMAL(12,2) NOT NULL DEFAULT 0.00,

    -- Lock/audit
    is_locked            TINYINT(1) NOT NULL DEFAULT 0,
    locked_by            INT UNSIGNED DEFAULT NULL,
    locked_at            DATETIME DEFAULT NULL,
    created_by           INT UNSIGNED NOT NULL,
    created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE INDEX idx_tax_period (tax_year, tax_quarter, period_type),
    INDEX idx_tax_year (tax_year),
    INDEX idx_locked (is_locked),
    CONSTRAINT fk_tax_creator FOREIGN KEY (created_by) REFERENCES CG_Users(user_id),
    CONSTRAINT fk_tax_locker  FOREIGN KEY (locked_by)  REFERENCES CG_Users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
