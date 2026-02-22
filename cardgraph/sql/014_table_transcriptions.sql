-- Card Graph - Table Transcriptions
-- Migration 014: Parse runs and extracted card records from transcription text

-- ============================================================
-- 1. CG_TranscriptionParseRuns — tracks each parse attempt
-- ============================================================
CREATE TABLE IF NOT EXISTS CG_TranscriptionParseRuns (
    run_id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id      INT UNSIGNED NOT NULL,
    status          ENUM('running','complete','error') NOT NULL DEFAULT 'running',
    total_records   INT UNSIGNED DEFAULT 0,
    high_confidence INT UNSIGNED DEFAULT 0,
    low_confidence  INT UNSIGNED DEFAULT 0,
    error_message   TEXT DEFAULT NULL,
    started_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at    DATETIME DEFAULT NULL,
    run_by          INT UNSIGNED NOT NULL,

    CONSTRAINT fk_parserun_session FOREIGN KEY (session_id)
        REFERENCES CG_TranscriptionSessions(session_id) ON DELETE CASCADE,
    CONSTRAINT fk_parserun_user FOREIGN KEY (run_by)
        REFERENCES CG_Users(user_id),
    INDEX idx_parserun_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. CG_TranscriptionRecords — one row per extracted card
-- ============================================================
CREATE TABLE IF NOT EXISTS CG_TranscriptionRecords (
    record_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id           INT UNSIGNED NOT NULL,
    session_id       INT UNSIGNED NOT NULL,
    segment_id       INT UNSIGNED DEFAULT NULL,
    sequence_number  INT UNSIGNED NOT NULL,

    -- Resolved references (FK to parser support tables)
    player_id        INT UNSIGNED DEFAULT NULL,
    team_id          INT UNSIGNED DEFAULT NULL,
    maker_id         INT UNSIGNED DEFAULT NULL,
    style_id         INT UNSIGNED DEFAULT NULL,
    specialty_id     INT UNSIGNED DEFAULT NULL,

    -- Raw extracted text (what the parser heard)
    raw_player       VARCHAR(200) DEFAULT NULL,
    raw_team         VARCHAR(200) DEFAULT NULL,
    raw_maker        VARCHAR(100) DEFAULT NULL,
    raw_style        VARCHAR(100) DEFAULT NULL,
    raw_specialty    VARCHAR(100) DEFAULT NULL,
    raw_parallel     VARCHAR(100) DEFAULT NULL,
    raw_card_number  VARCHAR(50)  DEFAULT NULL,

    -- Extracted attributes
    lot_number       INT UNSIGNED DEFAULT NULL,
    is_rookie        TINYINT(1) DEFAULT 0,
    is_autograph     TINYINT(1) DEFAULT 0,
    is_relic         TINYINT(1) DEFAULT 0,
    is_giveaway      TINYINT(1) DEFAULT 0,

    -- Confidence & context
    confidence       DECIMAL(3,2) NOT NULL DEFAULT 0.00,
    raw_text_excerpt TEXT DEFAULT NULL,
    segment_number   INT UNSIGNED DEFAULT NULL,
    text_position    INT UNSIGNED DEFAULT NULL,
    estimated_at     DATETIME DEFAULT NULL,

    -- Manual review
    is_verified      TINYINT(1) DEFAULT 0,
    verified_by      INT UNSIGNED DEFAULT NULL,
    notes            TEXT DEFAULT NULL,

    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_record_run FOREIGN KEY (run_id)
        REFERENCES CG_TranscriptionParseRuns(run_id) ON DELETE CASCADE,
    CONSTRAINT fk_record_session FOREIGN KEY (session_id)
        REFERENCES CG_TranscriptionSessions(session_id) ON DELETE CASCADE,
    CONSTRAINT fk_record_player FOREIGN KEY (player_id)
        REFERENCES CG_Players(player_id) ON DELETE SET NULL,
    CONSTRAINT fk_record_team FOREIGN KEY (team_id)
        REFERENCES CG_Teams(team_id) ON DELETE SET NULL,
    CONSTRAINT fk_record_maker FOREIGN KEY (maker_id)
        REFERENCES CG_CardMakers(maker_id) ON DELETE SET NULL,
    CONSTRAINT fk_record_style FOREIGN KEY (style_id)
        REFERENCES CG_CardStyles(style_id) ON DELETE SET NULL,
    CONSTRAINT fk_record_specialty FOREIGN KEY (specialty_id)
        REFERENCES CG_CardSpecialties(specialty_id) ON DELETE SET NULL,

    INDEX idx_record_session (session_id),
    INDEX idx_record_run (run_id),
    INDEX idx_record_sequence (session_id, sequence_number),
    INDEX idx_record_player (player_id),
    INDEX idx_record_confidence (confidence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
