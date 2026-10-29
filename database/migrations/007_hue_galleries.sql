-- Migration 007: Hue Galleries (max 5 public/private galleries, approval-based access keys, private comments & replies, 3-day cache sync)

ALTER TABLE spaces
  ADD COLUMN IF NOT EXISTS access_key VARCHAR(20) NULL AFTER item_count,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

CREATE TABLE IF NOT EXISTS space_members (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  space_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','approved','rejected','revoked') NOT NULL DEFAULT 'pending',
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_at DATETIME NULL,
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
