import pymysql

conn = pymysql.connect(
    host='192.168.0.215',
    port=3307,
    user='cg_app',
    password='ACe!sysD#0kVnBWF',
    database='card_graph',
    charset='utf8mb4'
)
cursor = conn.cursor()

# 1. Create CG_AnalyticsMetrics
cursor.execute("""
CREATE TABLE IF NOT EXISTS CG_AnalyticsMetrics (
    metric_id      TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    metric_key     VARCHAR(30)  NOT NULL UNIQUE,
    metric_name    VARCHAR(100) NOT NULL,
    description    TEXT         DEFAULT NULL,
    method         TEXT         DEFAULT NULL,
    unit_type      ENUM('currency','count','percent') NOT NULL DEFAULT 'currency',
    display_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_metric_order (display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
""")
print("Created CG_AnalyticsMetrics")

# 2. Seed metrics
cursor.execute("""
INSERT INTO CG_AnalyticsMetrics (metric_key, metric_name, description, method, unit_type, display_order) VALUES
('total_sales',    'Total Sales',    'Total revenue from all completed transactions.',
 'SUM of transaction_amount for all ORDER_EARNINGS and SHIPPING_CHARGE transactions, excluding cancelled statuses.', 'currency', 1),
('items_sold',     'Items Sold',     'Count of individual auction items sold.',
 'COUNT of CG_AuctionLineItems where buy_format = AUCTION and transaction is completed.', 'count', 2),
('unique_buyers',  'Unique Buyers',  'Number of distinct buyers who purchased items.',
 'COUNT(DISTINCT buyer_id) from completed AUCTION transactions.', 'count', 3),
('shipments',      'Shipments',      'Number of unique shipments sent.',
 'COUNT(DISTINCT shipment_id) from all transactions.', 'count', 4),
('profit_amount',  'Profit Amount',  'Net profit after costs, fees, and expenses.',
 'total_earnings - total_fees - total_item_costs - total_general_costs for the period.', 'currency', 5),
('profit_percent', 'Profit Percent', 'Profit as a percentage of total earnings.',
 '(profit_amount / total_earnings) * 100. Returns 0 if no earnings.', 'percent', 6)
""")
print(f"Inserted {cursor.rowcount} metrics")

# 3. Create CG_AnalyticsMilestones
cursor.execute("""
CREATE TABLE IF NOT EXISTS CG_AnalyticsMilestones (
    milestone_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    metric_id      TINYINT UNSIGNED NOT NULL,
    milestone_name VARCHAR(150) NOT NULL,
    target_value   DECIMAL(14,2) NOT NULL,
    time_window    ENUM('auction','monthly','quarterly','annually',
                        '2-year','3-year','4-year','5-year') NOT NULL,
    window_start   DATE         NOT NULL,
    window_end     DATE         NOT NULL,
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_by     INT UNSIGNED NOT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ms_metric (metric_id),
    INDEX idx_ms_window (time_window),
    INDEX idx_ms_dates  (window_start, window_end),
    CONSTRAINT fk_ms_metric  FOREIGN KEY (metric_id)  REFERENCES CG_AnalyticsMetrics(metric_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_ms_creator FOREIGN KEY (created_by)  REFERENCES CG_Users(user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
""")
print("Created CG_AnalyticsMilestones")

conn.commit()

# Verify
cursor.execute("SELECT metric_id, metric_key, metric_name, unit_type FROM CG_AnalyticsMetrics ORDER BY display_order")
rows = cursor.fetchall()
print(f"\nCG_AnalyticsMetrics: {len(rows)} rows")
for r in rows:
    print(f"  [{r[0]}] {r[1]}: {r[2]} ({r[3]})")

cursor.execute("DESCRIBE CG_AnalyticsMilestones")
cols = cursor.fetchall()
print(f"\nCG_AnalyticsMilestones: {len(cols)} columns")
for c in cols:
    print(f"  {c[0]}: {c[1]}")

conn.close()
print("\nDone!")
