-- Add duration_seconds to message_media for voice note length display
ALTER TABLE message_media ADD COLUMN IF NOT EXISTS duration_seconds INT UNSIGNED NULL AFTER size;
ALTER TABLE message_media ADD COLUMN IF NOT EXISTS is_voice TINYINT(1) NOT NULL DEFAULT 0 AFTER duration_seconds;
