-- Run once after database/migrations/003_multi_personal_colors.sql.
USE iwnd_560_hue;

CREATE TABLE message_media (
  message_id BIGINT UNSIGNED PRIMARY KEY,
  path VARCHAR(255) NOT NULL,
  mime VARCHAR(40) NOT NULL,
  size INT UNSIGNED NOT NULL,
  FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB;
