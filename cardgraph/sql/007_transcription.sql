-- Migration 007: Audio & Transcription
-- Adds tables for auction recording, segmented transcription, and global config.

-- 1. Global settings (single-row config, like CG_ScrollSettings)
CREATE TABLE IF NOT EXISTS CG_TranscriptionSettings (
    setting_id              TINYINT UNSIGNED NOT NULL DEFAULT 1 PRIMARY KEY,

    -- A. Recording Settings
    segment_length_minutes  SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    sample_rate             ENUM('8000','16000','22050') NOT NULL DEFAULT '16000',
    audio_channels          ENUM('mono','stereo') NOT NULL DEFAULT 'mono',
    audio_format            ENUM('wav','flac') NOT NULL DEFAULT 'wav',

    -- B. Silence Detection
    silence_threshold_dbfs  SMALLINT NOT NULL DEFAULT -48,
    silence_timeout_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10,

    -- C. Max Session Duration
    max_session_hours       TINYINT UNSIGNED NOT NULL DEFAULT 10,

    -- D. Transcription Settings
    max_cpu_cores           TINYINT UNSIGNED NOT NULL DEFAULT 2,
    whisper_model           ENUM('tiny','base') NOT NULL DEFAULT 'base',
    priority_mode           ENUM('low','normal') NOT NULL DEFAULT 'low',

    -- E. Storage Settings
    base_archive_dir        VARCHAR(255) NOT NULL DEFAULT '/volume1/web/cardgraph/archive/',
    folder_structure        ENUM('year-based','flat') NOT NULL DEFAULT 'year-based',
    min_free_disk_gb        TINYINT UNSIGNED NOT NULL DEFAULT 5,

    -- F. Acquisition Mode
    acquisition_mode        ENUM('direct_stream','browser_automation') NOT NULL DEFAULT 'direct_stream',

    updated_by              INT UNSIGNED DEFAULT NULL,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Auction sessions (recording jobs)
CREATE TABLE IF NOT EXISTS CG_TranscriptionSessions (
    session_id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    auction_name            VARCHAR(200) NOT NULL,
    auction_url             VARCHAR(500) NOT NULL,
    scheduled_start         DATETIME NOT NULL,
    status                  ENUM('scheduled','recording','processing','complete','stopped','error')
                                NOT NULL DEFAULT 'scheduled',
    stop_reason             VARCHAR(100) DEFAULT NULL,
    actual_start_time       DATETIME DEFAULT NULL,
    end_time                DATETIME DEFAULT NULL,
    total_segments          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    total_duration_sec      INT UNSIGNED NOT NULL DEFAULT 0,
    session_dir             VARCHAR(500) DEFAULT NULL,

    -- Per-session overrides (NULL = use global)
    override_segment_length   SMALLINT UNSIGNED DEFAULT NULL,
    override_silence_timeout  SMALLINT UNSIGNED DEFAULT NULL,
    override_max_duration     TINYINT UNSIGNED DEFAULT NULL,
    override_cpu_limit        TINYINT UNSIGNED DEFAULT NULL,
    override_acquisition_mode ENUM('direct_stream','browser_automation') DEFAULT NULL,

    created_by              INT UNSIGNED NOT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_ts_status (status),
    INDEX idx_ts_scheduled (scheduled_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Recording segments within a session
CREATE TABLE IF NOT EXISTS CG_TranscriptionSegments (
    segment_id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id              INT UNSIGNED NOT NULL,
    segment_number          SMALLINT UNSIGNED NOT NULL,
    filename_audio          VARCHAR(300) DEFAULT NULL,
    filename_transcript     VARCHAR(300) DEFAULT NULL,
    duration_seconds        INT UNSIGNED NOT NULL DEFAULT 0,
    file_size_bytes         BIGINT UNSIGNED NOT NULL DEFAULT 0,
    recording_status        ENUM('recording','complete','error') NOT NULL DEFAULT 'recording',
    transcription_status    ENUM('pending','transcribing','complete','error','skipped')
                                NOT NULL DEFAULT 'pending',
    transcription_progress  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    started_at              DATETIME DEFAULT NULL,
    completed_at            DATETIME DEFAULT NULL,
    error_message           VARCHAR(500) DEFAULT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE INDEX idx_seg_session_num (session_id, segment_number),
    INDEX idx_seg_rec_status (recording_status),
    INDEX idx_seg_tx_status (transcription_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Per-session event log
CREATE TABLE IF NOT EXISTS CG_TranscriptionLogs (
    log_id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id              INT UNSIGNED NOT NULL,
    log_level               ENUM('info','warning','error') NOT NULL DEFAULT 'info',
    event_type              VARCHAR(50) NOT NULL,
    message                 TEXT NOT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_log_session (session_id),
    INDEX idx_log_level (log_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed settings with defaults
INSERT INTO CG_TranscriptionSettings (setting_id) VALUES (1);
