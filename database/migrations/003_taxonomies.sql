-- Migrasi 003: taksonomi, term, dan relasi entry-term — idempotent.
CREATE TABLE IF NOT EXISTS taxonomies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  content_type_id INT UNSIGNED NOT NULL,
  slug VARCHAR(100) NOT NULL,
  label VARCHAR(150) NOT NULL,
  is_hierarchical TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tax_ct_slug (content_type_id, slug),
  CONSTRAINT fk_tax_content_type FOREIGN KEY (content_type_id) REFERENCES content_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS terms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  taxonomy_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  slug VARCHAR(150) NOT NULL,
  parent_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_term_tax_slug (taxonomy_id, slug),
  KEY idx_terms_parent (parent_id),
  CONSTRAINT fk_terms_taxonomy FOREIGN KEY (taxonomy_id) REFERENCES taxonomies(id) ON DELETE CASCADE,
  CONSTRAINT fk_terms_parent FOREIGN KEY (parent_id) REFERENCES terms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS entry_terms (
  entry_id INT UNSIGNED NOT NULL,
  term_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (entry_id, term_id),
  KEY idx_et_term (term_id),
  CONSTRAINT fk_et_entry FOREIGN KEY (entry_id) REFERENCES content_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_et_term FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
