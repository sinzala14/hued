-- Hue seed: one normal user and one admin. Run after schema.sql and 002_phase2.sql:
--   mysql -u root -p hue < database/seed_users.sql
-- CHANGE BOTH PASSWORDS after first login. Passwords are stored as Argon2id hashes.
USE iwnd_560_hue;

INSERT INTO users (code, username, display_name, email, password_hash, personal_color, is_admin) VALUES
('USR-DEMO1', 'demo', 'Demo User', 'demo@example.com', '$argon2id$v=19$m=65536,t=4,p=1$LmpQZ0pDQjViRU5paURwOQ$ARGPJdmiwXaeatFWB8SViDmScpv4SyG6deDrXAbLIzw', 'green', 0),
('ADM-HUE01', 'admin', 'Hue Admin', 'admin@example.com', '$argon2id$v=19$m=65536,t=4,p=1$UkVnVXVzRjljL3RLb2VxWQ$x/kmVQlbCvSg069xRKWLTFIHS7GT4fy30Ok0iHbU7GM', 'blue', 1);

INSERT IGNORE INTO notification_settings (user_id) SELECT id FROM users WHERE username IN ('demo','admin');
INSERT INTO user_colors (user_id, color) SELECT id, personal_color FROM users WHERE username IN ('demo','admin');
