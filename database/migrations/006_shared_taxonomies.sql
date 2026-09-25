-- Migrasi 006: taksonomi lintas content type (many-to-many) untuk normalisasi.
--
-- Sumber kebenaran keterkaitan taksonomi <-> content type kini ada di tabel
-- penghubung taxonomy_content_types. Kolom taxonomies.content_type_id dipertahankan
-- hanya untuk kompatibilitas skema lama (dibuat NULL setelah backfill) agar tidak
-- memicu penghapusan CASCADE saat sebuah content type dihapus.

CREATE TABLE IF NOT EXISTS taxonomy_content_types (
  taxonomy_id INT UNSIGNED NOT NULL,
  content_type_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (taxonomy_id, content_type_id),
  KEY idx_tct_content_type (content_type_id),
  CONSTRAINT fk_tct_taxonomy FOREIGN KEY (taxonomy_id) REFERENCES taxonomies(id) ON DELETE CASCADE,
  CONSTRAINT fk_tct_content_type FOREIGN KEY (content_type_id) REFERENCES content_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill dari skema lama (idempotent: setelah kolom di-NULL-kan, tidak ada baris lagi).
INSERT IGNORE INTO taxonomy_content_types (taxonomy_id, content_type_id)
SELECT id, content_type_id FROM taxonomies WHERE content_type_id IS NOT NULL;

ALTER TABLE taxonomies MODIFY content_type_id INT UNSIGNED NULL;

UPDATE taxonomies SET content_type_id = NULL WHERE content_type_id IS NOT NULL;
