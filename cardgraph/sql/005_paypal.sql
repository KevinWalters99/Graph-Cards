-- Migration 005: PayPal Transactions & Allocations
-- Adds tables for PayPal CSV import, cost assignment, and sign-off workflow.

-- 1. PayPal Transactions (raw imported data)
CREATE TABLE IF NOT EXISTS CG_PayPalTransactions (
    pp_transaction_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    paypal_txn_id       VARCHAR(50) NOT NULL,
    transaction_date    DATE NOT NULL,
    transaction_time    TIME NOT NULL,
    timezone            VARCHAR(10) DEFAULT 'PST',
    name                VARCHAR(255) DEFAULT NULL,
    type                VARCHAR(100) NOT NULL,
    status              VARCHAR(20) NOT NULL,
    currency            VARCHAR(10) DEFAULT 'USD',
    amount              DECIMAL(10,2) NOT NULL,
    fees                DECIMAL(10,2) DEFAULT 0.00,
    net_amount          DECIMAL(10,2) NOT NULL,
    balance             DECIMAL(10,2) DEFAULT NULL,
    receipt_id          VARCHAR(50) DEFAULT NULL,
    item_title          VARCHAR(255) DEFAULT NULL,
    order_number        VARCHAR(30) DEFAULT NULL,
    charge_category     ENUM('purchase','refund','income','offset','auth','withdrawal') NOT NULL,
    upload_id           INT UNSIGNED DEFAULT NULL,
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,

    UNIQUE INDEX idx_pp_dedup (paypal_txn_id),
    INDEX idx_date (transaction_date),
    INDEX idx_name (name(50)),
    INDEX idx_order (order_number),
    INDEX idx_category (charge_category),
    INDEX idx_upload (upload_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. PayPal Allocations (assignment to cost buckets)
CREATE TABLE IF NOT EXISTS CG_PayPalAllocations (
    allocation_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pp_transaction_id   INT UNSIGNED NOT NULL,
    sales_source        ENUM('Auction','eBay','Private-Collection') NOT NULL DEFAULT 'Auction',
    livestream_id       VARCHAR(36) DEFAULT NULL,
    amount_allocated    DECIMAL(10,2) NOT NULL,
    notes               VARCHAR(255) DEFAULT NULL,
    assigned_by         INT UNSIGNED NOT NULL,
    assigned_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_locked           TINYINT(1) NOT NULL DEFAULT 0,
    locked_by           INT UNSIGNED DEFAULT NULL,
    locked_at           DATETIME DEFAULT NULL,

    INDEX idx_pp_txn (pp_transaction_id),
    INDEX idx_livestream (livestream_id),
    INDEX idx_source (sales_source),
    INDEX idx_locked (is_locked),
    CONSTRAINT fk_alloc_pp FOREIGN KEY (pp_transaction_id)
        REFERENCES CG_PayPalTransactions(pp_transaction_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Update UploadLog ENUM to include 'paypal'
ALTER TABLE CG_UploadLog
    MODIFY COLUMN upload_type ENUM('earnings','payouts','paypal') NOT NULL;
