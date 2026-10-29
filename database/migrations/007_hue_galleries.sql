-- Hue Galleries (Spaces v2):
-- 1. Adds access_key and updated_at to spaces (supports up to 5 public or private galleries per user, with optional expires_at duration)
-- 2. Creates space_members for Private Gallery key redemption + owner approval workflow (with optional expires_at)
-- 3. Creates space_comments for private viewer comments + owner-only replies
-- 4. Creates space_media_views to track per-picture NEW vs Viewed status for friends

SET NAMES utf8mb4;

ALTER TABLE spaces
  ADD COLUMN IF NOT EXISTS access_key VARCHAR(20) NULL AFTER item_count,
  ADD COLUMN IF NOT EXISTS expires_at DATETIME NULL AFTER access_key,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

CREATE TABLE IF NOT EXISTS space_members (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  space_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','approved','rejected','revoked') NOT NULL DEFAULT 'pending',
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_at DATETIME NULL,
  expires_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_space_member (space_id, user_id),
  FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_space_status (space_id, status),
  INDEX idx_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS space_comments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  space_id BIGINT UNSIGNED NOT NULL,
  media_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  body VARCHAR(1000) NOT NULL,
  owner_reply VARCHAR(1000) NULL,
  replied_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_space_user (space_id, user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS space_media_views (
  media_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (media_id, user_id),
  FOREIGN KEY (media_id) REFERENCES space_media(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_user_viewed (user_id, viewed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
