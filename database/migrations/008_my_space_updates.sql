-- Migration 008: My Space updates (Discover, Hue Gallery, Private Pics)
ALTER TABLE spaces ADD COLUMN IF NOT EXISTS category VARCHAR(20) NOT NULL DEFAULT 'gallery';
