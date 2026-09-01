-- Migrasi 002: tabel settings (pengaturan situs) — idempotent.
CREATE TABLE IF NOT EXISTS settings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(100) NOT NULL UNIQUE,
  setting_value TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
  ('site_title', 'Ringan CMS'),
  ('site_tagline', ''),
  ('site_description', ''),
  ('site_favicon', ''),
  ('site_timezone', 'Asia/Jakarta'),
  ('api_path', 'api/v1'),
  ('update_url', 'https://api.github.com/repos/stefanusade/ringan-cms/releases/latest');
