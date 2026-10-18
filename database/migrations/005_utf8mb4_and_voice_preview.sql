-- Migration 005: UTF8MB4 emoji support
-- Converts message, user, and space text tables to utf8mb4 so emojis (e.g. 😊, 💜, 🎉) do not appear as '??'

ALTER TABLE messages CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE users CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE spaces CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
