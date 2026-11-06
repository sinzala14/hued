-- 009_space_follows.sql
-- Adds space_follows table to allow users to follow friends' spaces and receive notifications on updates

CREATE TABLE IF NOT EXISTS space_follows (
  space_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (space_id, user_id),
  INDEX idx_user_follows (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
