-- Card Graph (CG) — Recurring Milestones Migration
-- Run after 003_analytics.sql

-- 1. Add 'weekly' to the time_window ENUM
ALTER TABLE CG_AnalyticsMilestones
    MODIFY COLUMN time_window ENUM(
        'auction','weekly','monthly','quarterly','annually',
        '2-year','3-year','4-year','5-year'
    ) NOT NULL;

-- 2. Add recurring columns
ALTER TABLE CG_AnalyticsMilestones
    ADD COLUMN is_recurring     TINYINT(1)   NOT NULL DEFAULT 0 AFTER is_active,
    ADD COLUMN recurrence_type  ENUM('weekly','monthly','custom') DEFAULT NULL AFTER is_recurring,
    ADD COLUMN recurrence_days  SMALLINT UNSIGNED DEFAULT NULL AFTER recurrence_type;

-- 3. Index for the recurring processing query
ALTER TABLE CG_AnalyticsMilestones
    ADD INDEX idx_ms_recurring (is_recurring, is_active, window_end);
