-- Migrasi 005: seed default setting zona waktu & path API (idempotent).
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
  ('site_timezone', 'Asia/Jakarta'),
  ('api_path', 'api/v1'),
  ('update_url', 'https://api.github.com/repos/stefanusade/ringan-cms/releases/latest');
