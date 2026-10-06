-- Phase 2: run once, after schema.sql.  mysql -u root -p hue < database/migrations/002_phase2.sql
USE iwnd_560_hue;

ALTER TABLE users
  ADD COLUMN avatar_path VARCHAR(255) NULL,
  ADD COLUMN suspension_reason VARCHAR(200) NULL,
  ADD COLUMN who_can_request ENUM('everyone','nobody') NOT NULL DEFAULT 'everyone',
  ADD COLUMN who_can_message ENUM('friends','nobody') NOT NULL DEFAULT 'friends',
  ADD COLUMN color_visibility ENUM('everyone','friends','nobody') NOT NULL DEFAULT 'everyone',
  ADD COLUMN profile_visibility ENUM('everyone','friends') NOT NULL DEFAULT 'everyone',
  ADD COLUMN space_visibility ENUM('everyone','friends') NOT NULL DEFAULT 'everyone',
  ADD COLUMN reveal_chat_color_default TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE sessions ADD COLUMN last_used_at DATETIME NULL, ADD COLUMN ip VARCHAR(45) NULL;

ALTER TABLE conversation_members
  ADD COLUMN muted TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN hidden TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN cleared_before_id BIGINT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE space_access_keys ADD COLUMN notified_at DATETIME NULL;

ALTER TABLE notifications ADD COLUMN title VARCHAR(100) NULL, ADD COLUMN body VARCHAR(200) NULL;

ALTER TABLE reports MODIFY status ENUM('open','reviewed','resolved','dismissed','rejected') NOT NULL DEFAULT 'open';
UPDATE reports SET status = 'rejected' WHERE status = 'dismissed';
ALTER TABLE reports
  MODIFY status ENUM('open','reviewed','resolved','rejected') NOT NULL DEFAULT 'open',
  ADD COLUMN reported_user_id BIGINT UNSIGNED NULL,
  ADD COLUMN category ENUM('spam','harassment','inappropriate','other') NOT NULL DEFAULT 'other',
  ADD COLUMN details VARCHAR(500) NULL,
  ADD COLUMN admin_note VARCHAR(500) NULL,
  ADD COLUMN reviewed_by BIGINT UNSIGNED NULL,
  ADD COLUMN reviewed_at DATETIME NULL,
  ADD INDEX idx_status (status, created_at),
  ADD INDEX idx_reported (reported_user_id),
  ADD INDEX idx_reporter (reporter_id, created_at),
  ADD CONSTRAINT fk_rep_reporter FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_rep_msg FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_rep_space FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_rep_user FOREIGN KEY (reported_user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE blocked_users
  ADD INDEX idx_blocked (blocked_id),
  ADD CONSTRAINT fk_blk_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_blk_blocked FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE message_reads ADD CONSTRAINT fk_rd_msg FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE;
ALTER TABLE notifications ADD CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE space_relay ADD INDEX idx_relay (status, expires_at);

CREATE TABLE password_resets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX (user_id), INDEX (code_hash)
) ENGINE=InnoDB;

CREATE TABLE device_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NULL,
  fcm_token VARCHAR(512) NOT NULL,
  platform VARCHAR(10) NOT NULL DEFAULT 'android',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_token (fcm_token(255)),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE notification_settings (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  messages TINYINT(1) NOT NULL DEFAULT 1,
  friend_requests TINYINT(1) NOT NULL DEFAULT 1,
  friend_activity TINYINT(1) NOT NULL DEFAULT 0,
  spaces TINYINT(1) NOT NULL DEFAULT 1,
  reactions TINYINT(1) NOT NULL DEFAULT 1,
  push_enabled TINYINT(1) NOT NULL DEFAULT 1,
  sound TINYINT(1) NOT NULL DEFAULT 1,
  vibration TINYINT(1) NOT NULL DEFAULT 1,
  show_previews TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO notification_settings (user_id) SELECT id FROM users;
