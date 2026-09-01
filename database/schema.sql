-- ============================================================
-- Ringan CMS — Skema Database Inti
-- Pendekatan hybrid JSON column (bukan full EAV).
-- MySQL 8 / MariaDB modern.
-- ============================================================

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('superadmin','editor','viewer') NOT NULL DEFAULT 'viewer',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS content_types (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(100) NOT NULL UNIQUE,
  label VARCHAR(150) NOT NULL,
  description TEXT NULL,
  is_api_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ct_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS content_type_fields (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  content_type_id INT UNSIGNED NOT NULL,
  field_key VARCHAR(100) NOT NULL,
  label VARCHAR(150) NOT NULL,
  field_type ENUM('text','textarea','richtext','number','boolean','date','image','file','select','relation','gallery') NOT NULL,
  options_json JSON NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_ct_field_key (content_type_id, field_key),
  CONSTRAINT fk_ctf_content_type FOREIGN KEY (content_type_id) REFERENCES content_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS content_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  content_type_id INT UNSIGNED NOT NULL,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  data JSON NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ce_ct_status (content_type_id, status),
  CONSTRAINT fk_ce_content_type FOREIGN KEY (content_type_id) REFERENCES content_types(id) ON DELETE CASCADE,
  CONSTRAINT fk_ce_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_keys (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(150) NOT NULL,
  key_hash CHAR(64) NOT NULL UNIQUE,
  scope ENUM('read','read_write') NOT NULL DEFAULT 'read',
  content_type_restriction JSON NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_used_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ak_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  target_table VARCHAR(100) NULL,
  target_id VARCHAR(64) NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_al_action (action),
  KEY idx_al_created (created_at),
  CONSTRAINT fk_al_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  identifier VARCHAR(190) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_la_identifier_time (identifier, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_rate_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  api_key_id INT UNSIGNED NULL,
  ip_address VARCHAR(45) NOT NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_arl_key_time (api_key_id, requested_at),
  KEY idx_arl_ip_time (ip_address, requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pengaturan situs (key-value)
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

-- Taksonomi (kategori/tag ala WordPress)
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
