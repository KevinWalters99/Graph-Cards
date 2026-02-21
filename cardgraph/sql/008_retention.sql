-- Migration 008: Add audio retention policy setting
-- Adds configurable retention period for automatic cleanup of old sessions

ALTER TABLE CG_TranscriptionSettings
    ADD COLUMN audio_retention_days SMALLINT NOT NULL DEFAULT 30;
