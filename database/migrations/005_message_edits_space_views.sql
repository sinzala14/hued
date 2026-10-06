-- Run once after database/migrations/004_message_images.sql.
USE iwnd_560_hue;

ALTER TABLE messages ADD COLUMN edited_at DATETIME NULL AFTER deleted_at;

-- One view per signed-in viewer per public Space; opening it repeatedly does not inflate views.
CREATE TABLE space_views (
  space_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (space_id, user_id),
  FOREIGN KEY (space_id) REFERENCES spaces(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX (space_id, viewed_at)
) ENGINE=InnoDB;
