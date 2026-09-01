-- Migrasi 004: tambah tipe field 'gallery' ke enum content_type_fields.
ALTER TABLE content_type_fields
  MODIFY field_type ENUM('text','textarea','richtext','number','boolean','date','image','file','select','relation','gallery') NOT NULL;
