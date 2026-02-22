-- Card Graph — Parser Stats Enhancements
-- Migration 012: MLB API integration, team association, stats columns, refresh tracking

-- ============================================================
-- 1. Add columns to CG_Players
-- ============================================================
ALTER TABLE CG_Players
    ADD COLUMN mlb_id INT UNSIGNED DEFAULT NULL AFTER player_id,
    ADD COLUMN current_team_id INT UNSIGNED DEFAULT NULL AFTER primary_position,
    ADD COLUMN minor_league_level VARCHAR(20) DEFAULT NULL AFTER current_team_id,
    ADD COLUMN prospect_rank INT UNSIGNED DEFAULT NULL AFTER minor_league_level,
    ADD UNIQUE INDEX idx_player_mlb_id (mlb_id),
    ADD INDEX idx_player_team (current_team_id);

-- ============================================================
-- 2. Add columns to CG_Teams
-- ============================================================
ALTER TABLE CG_Teams
    ADD COLUMN mlb_id INT UNSIGNED DEFAULT NULL AFTER team_id,
    ADD COLUMN abbreviation VARCHAR(5) DEFAULT NULL AFTER city,
    ADD COLUMN league VARCHAR(20) DEFAULT NULL AFTER abbreviation,
    ADD COLUMN division VARCHAR(20) DEFAULT NULL AFTER league,
    ADD UNIQUE INDEX idx_team_mlb_id (mlb_id);

-- ============================================================
-- 3. Add current_season_stats to CG_PlayerStatistics
-- ============================================================
ALTER TABLE CG_PlayerStatistics
    ADD COLUMN current_season_stats JSON DEFAULT NULL AFTER player_id;

-- ============================================================
-- 4. CG_DataRefreshLog — tracks "Check for Updates" runs
-- ============================================================
CREATE TABLE IF NOT EXISTS CG_DataRefreshLog (
    refresh_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    data_type       VARCHAR(50)  NOT NULL,
    status          ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
    started_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at    DATETIME     DEFAULT NULL,
    records_updated INT UNSIGNED DEFAULT 0,
    error_message   TEXT         DEFAULT NULL,
    triggered_by    VARCHAR(50)  DEFAULT 'manual',

    INDEX idx_refresh_type (data_type),
    INDEX idx_refresh_status (status),
    INDEX idx_refresh_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. Seed MLB API IDs for all 30 teams
-- ============================================================
UPDATE CG_Teams SET mlb_id = 109, abbreviation = 'AZ',  league = 'NL', division = 'West'    WHERE team_name = 'Diamondbacks';
UPDATE CG_Teams SET mlb_id = 144, abbreviation = 'ATL', league = 'NL', division = 'East'    WHERE team_name = 'Braves';
UPDATE CG_Teams SET mlb_id = 110, abbreviation = 'BAL', league = 'AL', division = 'East'    WHERE team_name = 'Orioles';
UPDATE CG_Teams SET mlb_id = 111, abbreviation = 'BOS', league = 'AL', division = 'East'    WHERE team_name = 'Red Sox';
UPDATE CG_Teams SET mlb_id = 112, abbreviation = 'CHC', league = 'NL', division = 'Central' WHERE team_name = 'Cubs';
UPDATE CG_Teams SET mlb_id = 145, abbreviation = 'CWS', league = 'AL', division = 'Central' WHERE team_name = 'White Sox';
UPDATE CG_Teams SET mlb_id = 113, abbreviation = 'CIN', league = 'NL', division = 'Central' WHERE team_name = 'Reds';
UPDATE CG_Teams SET mlb_id = 114, abbreviation = 'CLE', league = 'AL', division = 'Central' WHERE team_name = 'Guardians';
UPDATE CG_Teams SET mlb_id = 115, abbreviation = 'COL', league = 'NL', division = 'West'    WHERE team_name = 'Rockies';
UPDATE CG_Teams SET mlb_id = 116, abbreviation = 'DET', league = 'AL', division = 'Central' WHERE team_name = 'Tigers';
UPDATE CG_Teams SET mlb_id = 117, abbreviation = 'HOU', league = 'AL', division = 'West'    WHERE team_name = 'Astros';
UPDATE CG_Teams SET mlb_id = 118, abbreviation = 'KC',  league = 'AL', division = 'Central' WHERE team_name = 'Royals';
UPDATE CG_Teams SET mlb_id = 108, abbreviation = 'LAA', league = 'AL', division = 'West'    WHERE team_name = 'Angels';
UPDATE CG_Teams SET mlb_id = 119, abbreviation = 'LAD', league = 'NL', division = 'West'    WHERE team_name = 'Dodgers';
UPDATE CG_Teams SET mlb_id = 146, abbreviation = 'MIA', league = 'NL', division = 'East'    WHERE team_name = 'Marlins';
UPDATE CG_Teams SET mlb_id = 158, abbreviation = 'MIL', league = 'NL', division = 'Central' WHERE team_name = 'Brewers';
UPDATE CG_Teams SET mlb_id = 142, abbreviation = 'MIN', league = 'AL', division = 'Central' WHERE team_name = 'Twins';
UPDATE CG_Teams SET mlb_id = 121, abbreviation = 'NYM', league = 'NL', division = 'East'    WHERE team_name = 'Mets';
UPDATE CG_Teams SET mlb_id = 147, abbreviation = 'NYY', league = 'AL', division = 'East'    WHERE team_name = 'Yankees';
UPDATE CG_Teams SET mlb_id = 133, abbreviation = 'OAK', league = 'AL', division = 'West'    WHERE team_name = 'Athletics';
UPDATE CG_Teams SET mlb_id = 143, abbreviation = 'PHI', league = 'NL', division = 'East'    WHERE team_name = 'Phillies';
UPDATE CG_Teams SET mlb_id = 134, abbreviation = 'PIT', league = 'NL', division = 'Central' WHERE team_name = 'Pirates';
UPDATE CG_Teams SET mlb_id = 135, abbreviation = 'SD',  league = 'NL', division = 'West'    WHERE team_name = 'Padres';
UPDATE CG_Teams SET mlb_id = 136, abbreviation = 'SEA', league = 'AL', division = 'West'    WHERE team_name = 'Mariners';
UPDATE CG_Teams SET mlb_id = 137, abbreviation = 'SF',  league = 'NL', division = 'West'    WHERE team_name = 'Giants';
UPDATE CG_Teams SET mlb_id = 138, abbreviation = 'STL', league = 'NL', division = 'Central' WHERE team_name = 'Cardinals';
UPDATE CG_Teams SET mlb_id = 139, abbreviation = 'TB',  league = 'AL', division = 'East'    WHERE team_name = 'Rays';
UPDATE CG_Teams SET mlb_id = 140, abbreviation = 'TEX', league = 'AL', division = 'West'    WHERE team_name = 'Rangers';
UPDATE CG_Teams SET mlb_id = 141, abbreviation = 'TOR', league = 'AL', division = 'East'    WHERE team_name = 'Blue Jays';
UPDATE CG_Teams SET mlb_id = 120, abbreviation = 'WSH', league = 'NL', division = 'East'    WHERE team_name = 'Nationals';
