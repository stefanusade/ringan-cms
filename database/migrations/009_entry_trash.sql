-- Migrasi 009: soft delete entri (status trash).

ALTER TABLE content_entries
  MODIFY COLUMN status ENUM('draft','published','archived','trash') NOT NULL DEFAULT 'draft';
