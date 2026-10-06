-- Add support for multiple personal colors while keeping personal_color as the
-- primary color used by older app versions and existing indexes.
ALTER TABLE users ADD COLUMN personal_colors JSON NULL AFTER personal_color;
UPDATE users SET personal_colors = JSON_ARRAY(personal_color) WHERE personal_colors IS NULL;
