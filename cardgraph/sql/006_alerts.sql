-- Migration 006: Alerts & Notifications + Scroll Ticker
-- Creates CG_AlertDefinitions, CG_AlertDismissals, CG_ScrollSettings

CREATE TABLE IF NOT EXISTS CG_AlertDefinitions (
    alert_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(100) NOT NULL,
    description     TEXT NOT NULL,
    alert_type      ENUM('alert','notification') NOT NULL,
    frequency       ENUM('weekly','biweekly','monthly') NOT NULL,
    day_of_week     TINYINT DEFAULT NULL COMMENT '0=Sun..6=Sat, NULL for monthly',
    time_of_day     TIME NOT NULL DEFAULT '14:00:00',
    anchor_date     DATE DEFAULT NULL COMMENT 'For biweekly: reference start week',
    action_check    VARCHAR(50) DEFAULT NULL COMMENT 'upload_earnings, upload_payouts, upload_paypal, or NULL',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_by      INT UNSIGNED NOT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS CG_AlertDismissals (
    dismissal_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alert_id        INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    period_key      VARCHAR(20) NOT NULL COMMENT 'e.g. 2026-W08, 2026-02',
    dismissed_at    DATETIME DEFAULT CURRENT_TIMESTAMP,

    UNIQUE INDEX idx_unique_dismiss (alert_id, user_id, period_key),
    INDEX idx_alert (alert_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS CG_ScrollSettings (
    setting_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    is_enabled      TINYINT(1) NOT NULL DEFAULT 0,
    show_scorecard  TINYINT(1) NOT NULL DEFAULT 1,
    show_analytics  TINYINT(1) NOT NULL DEFAULT 1,
    show_players    TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Future: Parser project',
    show_teams      TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Future: Parser project',
    scroll_speed    ENUM('slow','medium','fast') NOT NULL DEFAULT 'medium',
    updated_by      INT UNSIGNED DEFAULT NULL,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default scroll settings (disabled)
INSERT INTO CG_ScrollSettings (is_enabled, show_scorecard, show_analytics, show_players, show_teams)
VALUES (0, 1, 1, 0, 0);
