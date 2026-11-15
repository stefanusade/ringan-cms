-- Migrasi 011: profil ekspor/impor per content type (plugin Ringan Export).

CREATE TABLE IF NOT EXISTS export_profiles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  content_type_id INT UNSIGNED NOT NULL,
  profile_json JSON NOT NULL,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_export_profile_ct (content_type_id),
  CONSTRAINT fk_ep_content_type FOREIGN KEY (content_type_id) REFERENCES content_types(id) ON DELETE CASCADE,
  CONSTRAINT fk_ep_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;