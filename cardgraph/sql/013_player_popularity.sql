-- Card Graph — Player Popularity Ranking
-- Migration 013: Add popularity_score to CG_Players

ALTER TABLE CG_Players
    ADD COLUMN popularity_score INT UNSIGNED DEFAULT NULL AFTER prospect_rank,
    ADD INDEX idx_player_popularity (popularity_score);
