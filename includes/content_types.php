<?php
/**
 * CRUD definisi content type & field (setara CPT + ACF di WordPress).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/validation.php';

function field_types(): array
{
    return [
        'text' => 'Teks (single line)',
        'textarea' => 'Teks panjang',
        'richtext' => 'Rich text (HTML)',
        'number' => 'Angka',
        'boolean' => 'Ya/Tidak',
        'date' => 'Tanggal',
        'image' => 'Gambar',
        'file' => 'File',
        'gallery' => 'Galeri gambar (banyak)',
        'select' => 'Pilihan (dropdown)',
        'relation' => 'Relasi ke content type lain',
    ];
}

function get_content_types(): array
{
    $db = get_db();
    $stmt = $db->query(
        'SELECT ct.*,
                (SELECT COUNT(*) FROM content_entries ce WHERE ce.content_type_id = ct.id) AS entry_count,
                (SELECT COUNT(*) FROM content_type_fields f WHERE f.content_type_id = ct.id) AS field_count
         FROM content_types ct
         ORDER BY ct.created_at DESC, ct.id DESC'
    );
    return $stmt->fetchAll();
}

function get_content_type(int $id): ?array
{
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM content_types WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_content_type_by_slug(string $slug): ?array
{
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM content_types WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function count_content_types(): int
{
    return (int) get_db()->query('SELECT COUNT(*) FROM content_types')->fetchColumn();
}

function content_type_slug_exists(string $slug, ?int $exclude_id = null): bool
{
    $db = get_db();
    $sql = 'SELECT COUNT(*) FROM content_types WHERE slug = ?';
    $params = [$slug];
    if ($exclude_id !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $exclude_id;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ((int) $stmt->fetchColumn()) > 0;
}

function create_content_type(string $slug, string $label, string $description, bool $is_api_enabled, int $created_by): int
{
    $db = get_db();
    $stmt = $db->prepare(
        'INSERT INTO content_types (slug, label, description, is_api_enabled, created_by)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$slug, $label, $description, $is_api_enabled ? 1 : 0, $created_by]);
    return (int) $db->lastInsertId();
}

function update_content_type(int $id, string $slug, string $label, string $description, bool $is_api_enabled): void
{
    $db = get_db();
    $stmt = $db->prepare(
        'UPDATE content_types SET slug = ?, label = ?, description = ?, is_api_enabled = ? WHERE id = ?'
    );
    $stmt->execute([$slug, $label, $description, $is_api_enabled ? 1 : 0, $id]);
}

function delete_content_type(int $id): void
{
    $db = get_db();
    $stmt = $db->prepare('DELETE FROM content_types WHERE id = ?');
    $stmt->execute([$id]);
}

/* ===== Fields ===== */

function get_fields(int $content_type_id): array
{
    $db = get_db();
    $stmt = $db->prepare(
        'SELECT * FROM content_type_fields WHERE content_type_id = ? ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$content_type_id]);
    return $stmt->fetchAll();
}

function get_field(int $id): ?array
{
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM content_type_fields WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function field_key_exists(int $content_type_id, string $field_key, ?int $exclude_id = null): bool
{
    $db = get_db();
    $sql = 'SELECT COUNT(*) FROM content_type_fields WHERE content_type_id = ? AND field_key = ?';
    $params = [$content_type_id, $field_key];
    if ($exclude_id !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $exclude_id;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ((int) $stmt->fetchColumn()) > 0;
}

function create_field(int $content_type_id, string $field_key, string $label, string $field_type, array $options, int $sort_order, bool $is_required): int
{
    $db = get_db();
    $stmt = $db->prepare(
        'INSERT INTO content_type_fields (content_type_id, field_key, label, field_type, options_json, sort_order, is_required)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $content_type_id, $field_key, $label, $field_type,
        json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $sort_order, $is_required ? 1 : 0,
    ]);
    return (int) $db->lastInsertId();
}

function update_field(int $id, string $field_key, string $label, string $field_type, array $options, int $sort_order, bool $is_required): void
{
    $db = get_db();
    $stmt = $db->prepare(
        'UPDATE content_type_fields
         SET field_key = ?, label = ?, field_type = ?, options_json = ?, sort_order = ?, is_required = ?
         WHERE id = ?'
    );
    $stmt->execute([
        $field_key, $label, $field_type,
        json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $sort_order, $is_required ? 1 : 0, $id,
    ]);
}

function delete_field(int $id): void
{
    $db = get_db();
    $stmt = $db->prepare('DELETE FROM content_type_fields WHERE id = ?');
    $stmt->execute([$id]);
}

/* ===== Helpers ===== */

function decode_options(?string $options_json): array
{
    if ($options_json === null || $options_json === '') {
        return [];
    }
    $decoded = json_decode($options_json, true);
    return is_array($decoded) ? $decoded : [];
}

function make_field_key(string $label): string
{
    $key = strtolower(trim($label));
    $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? $key;
    $key = trim($key, '_');
    if ($key === '' || !preg_match('/^[a-z]/', $key)) {
        $key = 'field_' . $key;
    }
    return substr($key, 0, 100);
}
