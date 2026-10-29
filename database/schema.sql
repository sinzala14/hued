-- Hue MySQL schema (MySQL 8+, utf8mb4)


CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(12) NOT NULL UNIQUE,
  username VARCHAR(30) NOT NULL UNIQUE,
  display_name VARCHAR(60) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  bio VARCHAR(200) NULL,
  personal_color ENUM('green','red','purple','black','pink','yellow','blue','orange','white','gray','cyan','brown') NOT NULL DEFAULT 'white',
  personal_colors JSON NULL,
  is_banned TINYINT(1) NOT NULL DEFAULT 0,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  device_name VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX (user_id)
) ENGINE=InnoDB;

CREATE TABLE friendships (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  requester_id BIGINT UNSIGNED NOT NULL,
  addressee_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY pair (requester_id, addressee_id),
  FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (addressee_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE conversations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_a BIGINT UNSIGNED NOT NULL,   -- always the smaller user id
  user_b BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY pair (user_a, user_b)
) ENGINE=InnoDB;

CREATE TABLE conversation_members (
  conversation_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (conversation_id, user_id),
  FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id BIGINT UNSIGNED NOT NULL,
  sender_id BIGINT UNSIGNED NOT NULL,
  client_id CHAR(36) NOT NULL,
  body TEXT NOT NULL,
  reply_to_id BIGINT UNSIGNED NULL,
  status ENUM('sent','delivered','read') NOT NULL DEFAULT 'sent',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  deleted_at DATETIME NULL,
  edited_at DATETIME NULL,
  UNIQUE KEY dedupe (sender_id, client_id),
  INDEX conv_order (conversation_id, id),
  FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE message_media (
  message_id BIGINT UNSIGNED PRIMARY KEY,
  path VARCHAR(255) NOT NULL,
  mime VARCHAR(40) NOT NULL,
  size INT UNSIGNED NOT NULL,
  FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE message_reactions (
  message_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  reaction ENUM('like','love','amazing','funny','wow') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (message_id, user_id),
  FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE message_reads (
  message_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (message_id, user_id)
) ENGINE=InnoDB;

CREATE TABLE user_colors (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  color VARCHAR(10) NOT NULL,
  set_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX (user_id, set_at)
) ENGINE=InnoDB;

CREATE TABLE chat_colors (
  user_id BIGINT UNSIGNED NOT NULL,
  conversation_id BIGINT UNSIGNED NOT NULL,
  color ENUM('green','red','black','yellow','orange','white','purple','blue','pink','gray','cyan','brown') NOT NULL,
  revealed TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, conversation_id),
  FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE spaces (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id BIGINT UNSIGNED NOT NULL,
  type ENUM('public','private') NOT NULL,
  title VARCHAR(80) NOT NULL,
  description VARCHAR(300) NULL,
  item_count INT NOT NULL DEFAULT 0,
  access_key VARCHAR(20) NULL,
  is_hidden TINYINT(1) NOT NULL DEFAULT 0,  -- admin moderation
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX (type, deleted_at, created_at)
) ENGINE=InnoDB;

CREATE TABLE space_media (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  space_id BIGINT UNSIGNED NOT NULL,
  path VARCHAR(255) NOT NULL,
  mime VARCHAR(40) NOT NULL,
  size INT UNSIGNED NOT NULL,
  position INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE space_members (
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
) ENGINE=InnoDB;

CREATE TABLE space_comments (
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
) ENGINE=InnoDB;

CREATE TABLE space_access_keys (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  space_id BIGINT UNSIGNED NOT NULL,
  key_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE space_grants (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  space_id BIGINT UNSIGNED NOT NULL,
  key_id BIGINT UNSIGNED NOT NULL,
  viewer_id BIGINT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE,
  FOREIGN KEY (key_id) REFERENCES space_access_keys(id) ON DELETE CASCADE,
  UNIQUE KEY one_grant (key_id, viewer_id)
) ENGINE=InnoDB;

-- Short-lived relay: the owner's device fulfils a request; the blob is deleted after one fetch or 60 s.
CREATE TABLE space_relay (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  grant_id BIGINT UNSIGNED NOT NULL,
  item_index INT NOT NULL,
  status ENUM('requested','ready','delivered','expired') NOT NULL DEFAULT 'requested',
  blob_path VARCHAR(255) NULL,
  expires_at DATETIME NOT NULL,
  FOREIGN KEY (grant_id) REFERENCES space_grants(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE space_impressions (
  space_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('like','love','amazing','funny','interesting','beautiful','wow','emotional','unexpected','creative') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (space_id, user_id),
  FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE space_views (
  space_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (space_id, user_id),
  FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX (space_id, viewed_at)
) ENGINE=InnoDB;

CREATE TABLE blocked_users (
  user_id BIGINT UNSIGNED NOT NULL,
  blocked_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, blocked_id)
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(30) NOT NULL,
  payload JSON NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id, read_at)
) ENGINE=InnoDB;

CREATE TABLE reports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reporter_id BIGINT UNSIGNED NOT NULL,
  message_id BIGINT UNSIGNED NULL,
  space_id BIGINT UNSIGNED NULL,
  reason VARCHAR(200) NOT NULL,
  status ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE rate_limits (
  bucket VARCHAR(120) NOT NULL PRIMARY KEY,
  window_start INT UNSIGNED NOT NULL,
  hits INT UNSIGNED NOT NULL
) ENGINE=InnoDB;
