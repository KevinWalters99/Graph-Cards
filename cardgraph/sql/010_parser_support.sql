-- Card Graph - Parser Support Tables
-- Migration 010: Reference tables for baseball card auction transcript parsing

-- ============================================================
-- 1. CG_Players
-- ============================================================
CREATE TABLE IF NOT EXISTS CG_Players (
    player_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name       VARCHAR(100) NOT NULL,
    last_name        VARCHAR(100) NOT NULL,
    primary_position VARCHAR(10)  DEFAULT NULL,
    is_active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_player_name (last_name, first_name),
    INDEX idx_player_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. CG_PlayerNicknames
-- ============================================================
CREATE TABLE IF NOT EXISTS CG_PlayerNicknames (
    nickname_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id    INT UNSIGNED NOT NULL,
    nickname     VARCHAR(150) NOT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_nickname_player (player_id),
    INDEX idx_nickname_name (nickname),
    CONSTRAINT fk_nickname_player FOREIGN KEY (player_id)
        REFERENCES CG_Players(player_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. CG_PlayerStatistics (populated by external scripts, no UI)
-- ============================================================
CREATE TABLE IF NOT EXISTS CG_PlayerStatistics (
    player_id          INT UNSIGNED PRIMARY KEY,
    last_season_stats  JSON DEFAULT NULL,
    overall_stats      JSON DEFAULT NULL,
    last_updated       DATETIME DEFAULT NULL,

    CONSTRAINT fk_playerstats_player FOREIGN KEY (player_id)
        REFERENCES CG_Players(player_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. CG_Teams
-- ============================================================
CREATE TABLE IF NOT EXISTS CG_Teams (
    team_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    team_name  VARCHAR(150) NOT NULL,
    city       VARCHAR(100) DEFAULT NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_team_name (team_name),
    INDEX idx_team_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. CG_TeamAliases
-- ============================================================
CREATE TABLE IF NOT EXISTS CG_TeamAliases (
    alias_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    team_id    INT UNSIGNED NOT NULL,
    alias_name VARCHAR(150) NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_alias_team (team_id),
    INDEX idx_alias_name (alias_name),
    CONSTRAINT fk_alias_team FOREIGN KEY (team_id)
        REFERENCES CG_Teams(team_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. CG_TeamStatistics (populated by external scripts, no UI)
-- ============================================================
CREATE TABLE IF NOT EXISTS CG_TeamStatistics (
    team_id              INT UNSIGNED PRIMARY KEY,
    current_season_stats JSON DEFAULT NULL,
    last_season_stats    JSON DEFAULT NULL,
    last_updated         DATETIME DEFAULT NULL,

    CONSTRAINT fk_teamstats_team FOREIGN KEY (team_id)
        REFERENCES CG_Teams(team_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. CG_CardMakers
-- ============================================================
CREATE TABLE IF NOT EXISTS CG_CardMakers (
    maker_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL UNIQUE,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. CG_CardStyles
-- ============================================================
CREATE TABLE IF NOT EXISTS CG_CardStyles (
    style_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    style_name VARCHAR(100) NOT NULL UNIQUE,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. CG_CardSpecialties
-- ============================================================
CREATE TABLE IF NOT EXISTS CG_CardSpecialties (
    specialty_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(100) NOT NULL UNIQUE,
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Seed Data
-- ============================================================
INSERT INTO CG_CardMakers (name) VALUES
('Topps'), ('Bowman'), ('Donruss'), ('Panini'), ('Upper Deck');

INSERT INTO CG_CardStyles (style_name) VALUES
('Chrome'), ('Sapphire'), ('Refractor'), ('Heritage'), ('Prizm'),
('Select'), ('Optic'), ('Mosaic'), ('Gallery'), ('Stadium Club');

INSERT INTO CG_CardSpecialties (name) VALUES
('Die Cut'), ('Booklet'), ('Insert'), ('Case Hit'), ('Foil'),
('Holographic'), ('Printing Plate'), ('Autograph'), ('Relic'), ('Numbered');
