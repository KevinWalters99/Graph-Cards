-- Migration 017: Add batting/throwing hand to players
-- R = Right, L = Left, S = Switch (batting only)

ALTER TABLE CG_Players ADD COLUMN bats CHAR(1) DEFAULT NULL AFTER primary_position;
ALTER TABLE CG_Players ADD COLUMN throws_hand CHAR(1) DEFAULT NULL AFTER bats;
