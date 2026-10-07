-- Run once on existing databases to ensure chat-color reveals are stored.
-- The base schema already creates this table for new installations.
CREATE TABLE IF NOT EXISTS chat_colors (
  user_id BIGINT UNSIGNED NOT NULL,
  conversation_id BIGINT UNSIGNED NOT NULL,
  color ENUM('green','red','black','yellow','orange','white','purple','blue','pink','gray','cyan','brown') NOT NULL,
  revealed TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, conversation_id),
  FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Older installations may already have chat_colors but lack the reveal flag.
SET @has_chat_color_revealed := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'chat_colors'
    AND COLUMN_NAME = 'revealed'
);
SET @chat_color_reveal_ddl := IF(
  @has_chat_color_revealed = 0,
  'ALTER TABLE chat_colors ADD COLUMN revealed TINYINT(1) NOT NULL DEFAULT 0 AFTER color',
  'SELECT 1'
);
PREPARE chat_color_reveal_stmt FROM @chat_color_reveal_ddl;
EXECUTE chat_color_reveal_stmt;
DEALLOCATE PREPARE chat_color_reveal_stmt;
