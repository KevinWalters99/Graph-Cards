-- Card Graph (CG) - Database Schema
-- Run this script against the 'card_graph' database on MariaDB 10
-- All timestamps default to CST via session time_zone setting

-- ============================================================
-- 1. CG_Users — Multi-user authentication
-- ============================================================
CREATE TABLE IF NOT EXISTS CG_Users (
    user_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username       VARCHAR(50)  NOT NULL UNIQUE,
    display_name   VARCHAR(100) NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,
    role           ENUM('admin','user') NOT NULL DEFAULT 'user',
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_users_username (username),
    INDEX idx_users_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. CG_Sessions — Database-backed session management
-- ============================================================
CREATE TABLE IF NOT EXISTS CG_Sessions (
    session_id     VARCHAR(128) PRIMARY KEY,
    user_id        INT UNSIGNED NOT NULL,
    ip_address     VARCHAR(45)  NOT NULL,
    user_agent     VARCHAR(512) DEFAULT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at     DATETIME     NOT NULL,
    is_valid       TINYINT(1)   NOT NULL DEFAULT 1,

    INDEX idx_sessions_user (user_id),
    INDEX idx_sessions_expires (expires_at),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES CG_Users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. CG_UploadLog — Audit trail for every CSV upload
-- ============================================================
CREATE TABLE IF NOT EXISTS CG_UploadLog (
    upload_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uploaded_by       INT UNSIGNED NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename   VARCHAR(255) NOT NULL,
    upload_type       ENUM('earnings','payouts') NOT NULL DEFAULT 'earnings',
    file_size_bytes   INT UNSIGNED NOT NULL,
    row_count         INT UNSIGNED DEFAULT NULL,
    rows_inserted     INT UNSIGNED DEFAULT NULL,
    rows_skipped      INT UNSIGNED DEFAULT NULL,
    parsed_start_date DATE         DEFAULT NULL,
    parsed_end_date   DATE         DEFAULT NULL,
    status            ENUM('uploaded','processing','completed','failed') NOT NULL DEFAULT 'uploaded',
    error_message     TEXT         DEFAULT NULL,
    uploaded_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at      DATETIME     DEFAULT NULL,

    INDEX idx_upload_user (uploaded_by),
    INDEX idx_upload_status (status),
    INDEX idx_upload_dates (parsed_start_date, parsed_end_date),
    CONSTRAINT fk_upload_user FOREIGN KEY (uploaded_by) REFERENCES CG_Users(user_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. CG_EarningsStatements — One row per weekly CSV period
-- ============================================================
CREATE TABLE IF NOT EXISTS CG_EarningsStatements (
    statement_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    upload_id         INT UNSIGNED NOT NULL,
    report_start_date DATE         NOT NULL,
    week_number       SMALLINT UNSIGNED NOT NULL,
    seller_id         VARCHAR(20)  NOT NULL,
    total_rows        INT UNSIGNED NOT NULL DEFAULT 0,
    total_earnings    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_fees        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE INDEX uq_statement_period (report_start_date, week_number, seller_id),
    INDEX idx_statement_upload (upload_id),
    CONSTRAINT fk_statement_upload FOREIGN KEY (upload_id) REFERENCES CG_UploadLog(upload_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. CG_Livestreams — Normalized livestream lookup
-- ============================================================
CREATE TABLE IF NOT EXISTS CG_Livestreams (
    livestream_id    VARCHAR(36) PRIMARY KEY,
    livestream_title VARCHAR(255) NOT NULL,
    first_seen_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_livestream_title (livestream_title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. CG_Buyers — Normalized buyer lookup
-- ============================================================
CREATE TABLE IF NOT EXISTS CG_Buyers (
    buyer_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    buyer_name     VARCHAR(100) NOT NULL,
    buyer_state    VARCHAR(10)  DEFAULT NULL,
    buyer_country  VARCHAR(10)  NOT NULL DEFAULT 'US',
    first_seen_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE INDEX uq_buyer_name (buyer_name),
    INDEX idx_buyer_state (buyer_state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. CG_StatusTypes — Lookup table for line item statuses
-- ============================================================
CREATE TABLE IF NOT EXISTS CG_StatusTypes (
    status_type_id   TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    status_name      VARCHAR(50)  NOT NULL UNIQUE,
    display_order    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. CG_AuctionLineItems — Core table (one row per CSV line)
-- ============================================================
CREATE TABLE IF NOT EXISTS CG_AuctionLineItems (
    ledger_transaction_id  VARCHAR(20) PRIMARY KEY,
    statement_id           INT UNSIGNED    NOT NULL,
    upload_id              INT UNSIGNED    NOT NULL,
    order_id               VARCHAR(20)     DEFAULT NULL,
    transaction_type       VARCHAR(30)     NOT NULL,
    transaction_message    TEXT            DEFAULT NULL,
    listing_title          VARCHAR(255)    DEFAULT NULL,
    listing_description    VARCHAR(255)    DEFAULT NULL,
    product_category       VARCHAR(100)    DEFAULT NULL,
    buy_format             VARCHAR(20)     NOT NULL DEFAULT '',
    quantity_sold          SMALLINT UNSIGNED DEFAULT 1,
    livestream_id          VARCHAR(36)     DEFAULT NULL,
    buyer_id               INT UNSIGNED    DEFAULT NULL,
    shipment_id            VARCHAR(20)     DEFAULT NULL,
    transaction_currency   VARCHAR(3)      NOT NULL DEFAULT 'USD',

    transaction_amount              DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    buyer_paid                      DECIMAL(10,2) DEFAULT NULL,
    original_item_price             DECIMAL(10,2) DEFAULT NULL,
    coupon_cost                     DECIMAL(10,2) DEFAULT NULL,
    post_coupon_price               DECIMAL(10,2) DEFAULT NULL,
    shipping_fee                    DECIMAL(10,2) DEFAULT NULL,
    commission_fee                  DECIMAL(10,2) DEFAULT NULL,
    payment_processing_fee          DECIMAL(10,2) DEFAULT NULL,
    tax_on_commission_fee           DECIMAL(10,2) DEFAULT NULL,
    tax_on_payment_processing_fee   DECIMAL(10,2) DEFAULT NULL,

    order_placed_at          DATETIME      DEFAULT NULL,
    transaction_completed_at DATETIME      DEFAULT NULL,

    current_status_id  TINYINT UNSIGNED NOT NULL DEFAULT 1,

    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_line_statement (statement_id),
    INDEX idx_line_upload (upload_id),
    INDEX idx_line_order (order_id),
    INDEX idx_line_buyer (buyer_id),
    INDEX idx_line_livestream (livestream_id),
    INDEX idx_line_shipment (shipment_id),
    INDEX idx_line_status (current_status_id),
    INDEX idx_line_type (transaction_type),
    INDEX idx_line_buy_format (buy_format),
    INDEX idx_line_completed_at (transaction_completed_at),
    INDEX idx_line_placed_at (order_placed_at),
    INDEX idx_line_amount (transaction_amount),

    CONSTRAINT fk_line_statement FOREIGN KEY (statement_id) REFERENCES CG_EarningsStatements(statement_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_line_upload FOREIGN KEY (upload_id) REFERENCES CG_UploadLog(upload_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_line_livestream FOREIGN KEY (livestream_id) REFERENCES CG_Livestreams(livestream_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_line_buyer FOREIGN KEY (buyer_id) REFERENCES CG_Buyers(buyer_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_line_status FOREIGN KEY (current_status_id) REFERENCES CG_StatusTypes(status_type_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. CG_ItemCosts — Manual cost-basis entry per item
-- ============================================================
CREATE TABLE IF NOT EXISTS CG_ItemCosts (
    cost_id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ledger_transaction_id VARCHAR(20) NOT NULL,
    cost_amount           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    cost_description      VARCHAR(255) DEFAULT NULL,
    entered_by            INT UNSIGNED NOT NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_cost_ledger (ledger_transaction_id),
    INDEX idx_cost_entered (entered_by),
    CONSTRAINT fk_cost_line FOREIGN KEY (ledger_transaction_id)
        REFERENCES CG_AuctionLineItems(ledger_transaction_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_cost_user FOREIGN KEY (entered_by) REFERENCES CG_Users(user_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 10. CG_StatusHistory — Full audit trail of status changes
-- ============================================================
CREATE TABLE IF NOT EXISTS CG_StatusHistory (
    history_id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ledger_transaction_id VARCHAR(20) NOT NULL,
    old_status_id         TINYINT UNSIGNED DEFAULT NULL,
    new_status_id         TINYINT UNSIGNED NOT NULL,
    changed_by            INT UNSIGNED NOT NULL,
    change_reason         VARCHAR(255) DEFAULT NULL,
    changed_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_history_ledger (ledger_transaction_id),
    INDEX idx_history_date (changed_at),
    CONSTRAINT fk_history_line FOREIGN KEY (ledger_transaction_id)
        REFERENCES CG_AuctionLineItems(ledger_transaction_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_history_old FOREIGN KEY (old_status_id) REFERENCES CG_StatusTypes(status_type_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_history_new FOREIGN KEY (new_status_id) REFERENCES CG_StatusTypes(status_type_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_history_user FOREIGN KEY (changed_by) REFERENCES CG_Users(user_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 11. CG_Payouts — Payout tracking (manual + CSV import)
-- ============================================================
CREATE TABLE IF NOT EXISTS CG_Payouts (
    payout_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    amount          DECIMAL(12,2) NOT NULL,
    destination     VARCHAR(255)  NOT NULL,
    date_initiated  DATE          NOT NULL,
    arrival_date    DATE          DEFAULT NULL,
    status          ENUM('In Progress','Failed','Completed') NOT NULL DEFAULT 'In Progress',
    upload_id       INT UNSIGNED  DEFAULT NULL,
    notes           TEXT          DEFAULT NULL,
    entered_by      INT UNSIGNED  NOT NULL,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_payout_status (status),
    INDEX idx_payout_initiated (date_initiated),
    INDEX idx_payout_arrival (arrival_date),
    INDEX idx_payout_upload (upload_id),
    CONSTRAINT fk_payout_upload FOREIGN KEY (upload_id) REFERENCES CG_UploadLog(upload_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_payout_user FOREIGN KEY (entered_by) REFERENCES CG_Users(user_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
