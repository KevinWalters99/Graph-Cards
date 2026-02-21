-- Migration 009: Expand whisper_model ENUM to all available sizes
-- Adds small, medium, large model options (base remains default)

ALTER TABLE CG_TranscriptionSettings
    MODIFY COLUMN whisper_model ENUM('tiny','base','small','medium','large') NOT NULL DEFAULT 'base';
