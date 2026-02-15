-- Card Graph (CG) - Seed Data
-- Run after 001_create_tables.sql

-- ============================================================
-- Seed status types
-- ============================================================
INSERT IGNORE INTO CG_StatusTypes (status_name, display_order) VALUES
    ('Completed', 1),
    ('Pending', 2),
    ('Shipped', 3),
    ('Cancelled', 4),
    ('Refused', 5),
    ('Did Not Pay', 6),
    ('Returned', 7),
    ('Disputed', 8),
    ('Giveaway', 9);

-- ============================================================
-- Seed admin user
-- Password: changeme (bcrypt hash, cost 12)
-- IMPORTANT: Change this password immediately after first login!
-- Generated via: php -r "echo password_hash('changeme', PASSWORD_BCRYPT, ['cost'=>12]);"
-- The hash below is a placeholder — generate a fresh one during setup.
-- ============================================================
INSERT IGNORE INTO CG_Users (username, display_name, password_hash, role)
VALUES ('admin', 'Administrator', '$2y$12$PLACEHOLDER_GENERATE_ON_NAS', 'admin');
