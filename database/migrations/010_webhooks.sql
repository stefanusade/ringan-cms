-- Migrasi 010: antrean webhook keluar untuk rebuild SSG.

CREATE TABLE IF NOT EXISTS webhook_deliveries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event VARCHAR(50) NOT NULL,
  url VARCHAR(500) NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  status ENUM('pending','sending','delivered','failed') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  response_code SMALLINT UNSIGNED NULL,
  last_error VARCHAR(255) NULL,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  delivered_at DATETIME NULL,
  KEY idx_wd_due (status, available_at),
  KEY idx_wd_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
