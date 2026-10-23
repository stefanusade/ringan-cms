-- Migrasi 008: pemetaan sumber import (WordPress WXR → content entry).

CREATE TABLE IF NOT EXISTS import_refs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source VARCHAR(50) NOT NULL,
  source_site VARCHAR(190) NOT NULL DEFAULT '',
  source_id VARCHAR(100) NOT NULL,
  content_type_id INT UNSIGNED NOT NULL,
  entry_id INT UNSIGNED NOT NULL,
  imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_import_ref (source, source_site, source_id, content_type_id),
  KEY idx_import_entry (entry_id),
  CONSTRAINT fk_import_entry FOREIGN KEY (entry_id) REFERENCES content_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_import_ct FOREIGN KEY (content_type_id) REFERENCES content_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
