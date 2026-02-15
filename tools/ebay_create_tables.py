"""
Card Graph - Create eBay Transaction Tables
Creates CG_EbayOrders and CG_EbayOrderItems tables in MariaDB.
"""
import pymysql

DB_CONFIG = {
    'host': '192.168.0.215',
    'port': 3307,
    'user': 'cg_app',
    'password': 'ACe!sysD#0kVnBWF',
    'database': 'card_graph',
    'charset': 'utf8mb4',
}

TABLES = [
    # Orders table - one row per eBay order
    """
    CREATE TABLE IF NOT EXISTS CG_EbayOrders (
        ebay_order_id       INT AUTO_INCREMENT PRIMARY KEY,
        order_number        VARCHAR(50) NOT NULL,
        order_date          DATETIME NOT NULL,
        transaction_type    ENUM('PURCHASE', 'SALE') NOT NULL DEFAULT 'PURCHASE',
        subtotal            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        shipping_cost       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        sales_tax           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total_amount        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        seller_buyer_name   VARCHAR(100) DEFAULT NULL,
        email_uid           VARCHAR(50) DEFAULT NULL COMMENT 'IMAP UID for dedup',
        email_subject       VARCHAR(500) DEFAULT NULL,
        status              ENUM('Pending', 'Confirmed', 'Shipped', 'Delivered', 'Returned', 'Cancelled') NOT NULL DEFAULT 'Confirmed',
        notes               TEXT DEFAULT NULL,
        created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_order_number (order_number),
        KEY idx_order_date (order_date),
        KEY idx_transaction_type (transaction_type),
        KEY idx_email_uid (email_uid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """,

    # Order items table - one row per item in an order
    """
    CREATE TABLE IF NOT EXISTS CG_EbayOrderItems (
        ebay_item_id        INT AUTO_INCREMENT PRIMARY KEY,
        ebay_order_id       INT NOT NULL,
        item_title          VARCHAR(500) NOT NULL,
        item_price          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        ebay_item_number    VARCHAR(20) DEFAULT NULL,
        seller_buyer_name   VARCHAR(100) DEFAULT NULL,
        quantity            INT NOT NULL DEFAULT 1,
        created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (ebay_order_id) REFERENCES CG_EbayOrders(ebay_order_id) ON DELETE CASCADE,
        KEY idx_ebay_item_number (ebay_item_number),
        KEY idx_order_id (ebay_order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """
]

def main():
    conn = pymysql.connect(**DB_CONFIG)
    cursor = conn.cursor()

    for sql in TABLES:
        table_name = sql.split('CREATE TABLE IF NOT EXISTS ')[1].split(' ')[0]
        print(f"Creating {table_name}...")
        cursor.execute(sql)
        print(f"  Done.")

    conn.commit()

    # Verify
    cursor.execute("SHOW TABLES LIKE 'CG_Ebay%'")
    tables = cursor.fetchall()
    print(f"\neBay tables created: {[t[0] for t in tables]}")

    for t in tables:
        cursor.execute(f"DESCRIBE {t[0]}")
        cols = cursor.fetchall()
        print(f"\n{t[0]}:")
        for col in cols:
            print(f"  {col[0]:25s} {col[1]}")

    cursor.close()
    conn.close()
    print("\nDone!")

if __name__ == '__main__':
    main()
