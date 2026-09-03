<?php
/**
 * CRUD data/entry berdasarkan content type (hybrid JSON column).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/content_types.php';
require_once __DIR__ . '/validation.php';

function entry_statuses(): array
{
    return ['draft', 'published', 'archived'];
}

/**
 * CATATAN KEAMANAN: bagian dinamis query ($where/$sort_col/$order_dir) hanya
 * berasal dari whitelist internal di bawah — TIDAK pernah dari input user.
 * Semua nilai user melewati prepared statement.
 */
function get_entries(int $content_type_id, string $status = '', int $page = 1, int $per_page = 20, string $sort = 'created_at', string $order = 'DESC', ?int $filter_taxonomy_id = null, ?string $filter_term_slug = null): array
{
    $db = get_db();
    $per_page = max(1, min(100, $per_page));
    $page = max(1, $page);
    $sort_col = in_array($sort, ['id', 'created_at', 'updated_at'], true) ? $sort : 'created_at';
    $order_dir = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

    $where = 'WHERE ce.content_type_id = ?';
    $params = [$content_type_id];
    if ($status !== '' && in_array($status, entry_statuses(), true)) {
        $where .= ' AND ce.status = ?';
        $params[] = $status;
    }
    // Filter per term (taksonomi): ce.id harus ada di mapping term tsb.
    if ($filter_taxonomy_id !== null && $filter_term_slug !== null && $filter_term_slug !== '') {
        $where .= ' AND ce.id IN (
            SELECT et.entry_id FROM entry_terms et
            JOIN terms t ON t.id = et.term_id
            WHERE t.taxonomy_id = ? AND t.slug = ?
        )';
        $params[] = $filter_taxonomy_id;
        $params[] = $filter_term_slug;
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM content_entries ce $where");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    $offset = ($page - 1) * $per_page;
    $stmt = $db->prepare(
        "SELECT ce.* FROM content_entries ce $where ORDER BY ce.$sort_col $order_dir LIMIT $per_page OFFSET $offset"
    );
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    return [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => $total > 0 ? (int) ceil($total / $per_page) : 1,
    ];
}

function get_entry(int $id): ?array
{
    $db = get_db();
    $stmt = $db->prepare(
        'SELECT ce.*, ct.slug AS content_type_slug, ct.label AS content_type_label
         FROM content_entries ce
         JOIN content_types ct ON ct.id = ce.content_type_id
         WHERE ce.id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Ambil entry dengan jaminan milik content type tertentu (anti-IDOR).
 */
function get_entry_for_content_type(int $id, int $content_type_id): ?array
{
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM content_entries WHERE id = ? AND content_type_id = ? LIMIT 1');
    $stmt->execute([$id, $content_type_id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function create_entry(int $content_type_id, array $data, string $status, int $created_by): int
{
    $db = get_db();
    $stmt = $db->prepare(
        'INSERT INTO content_entries (content_type_id, status, data, created_by) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([
        $content_type_id,
        $status,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $created_by,
    ]);
    return (int) $db->lastInsertId();
}

function update_entry(int $id, array $data, string $status): void
{
    $db = get_db();
    $stmt = $db->prepare('UPDATE content_entries SET data = ?, status = ? WHERE id = ?');
    $stmt->execute([json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $status, $id]);
}

function delete_entry(int $id): void
{
    $db = get_db();
    $stmt = $db->prepare('DELETE FROM content_entries WHERE id = ?');
    $stmt->execute([$id]);
}

function decode_entry_data(array $entry): array
{
    $decoded = json_decode($entry['data'] ?? '{}', true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Validasi payload entry: whitelist field, cek tipe, cek relasi.
 * $partial=true → field yang tidak dikirim dibiarkan apa adanya (untuk PATCH).
 *
 * @return array{ok:bool,data:array,errors:array<string,string[]>}
 */
function validate_entry_payload(array $payload, array $fields, bool $partial = false, bool $enforce_required = true): array
{
    $errors = [];
    $field_map = [];
    foreach ($fields as $field) {
        $field_map[$field['field_key']] = $field;
    }

    // 1. Tolak field yang tidak terdefinisi (anti mass-assignment)
    foreach (array_keys($payload) as $key) {
        if (!isset($field_map[$key])) {
            $errors[$key] = [sprintf('Field "%s" tidak terdaftar pada content type ini.', $key)];
        }
    }

    // 2. Validasi setiap field yang terdefinisi
    $clean = [];
    foreach ($field_map as $key => $field) {
        $has = array_key_exists($key, $payload);
        if ($partial && !$has) {
            continue;
        }
        $raw = $has ? $payload[$key] : null;
        $field_errors = [];
        $value = validate_field_value($field, $raw, $field_errors, $enforce_required);

        // validasi relasi: target harus eksis
        if (($field['field_type'] ?? '') === 'relation' && $value !== null) {
            $options = decode_options($field['options_json'] ?? null);
            $target_slug = $options['relation_target'] ?? '';
            $target = $target_slug !== '' ? get_content_type_by_slug($target_slug) : null;
            if ($target === null) {
                $field_errors[] = 'Target relasi tidak valid.';
            } else {
                $ids = (array) $value;
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = get_db()->prepare("SELECT COUNT(*) FROM content_entries WHERE content_type_id = ? AND id IN ($placeholders)");
                $stmt->execute(array_merge([(int) $target['id']], $ids));
                if ((int) $stmt->fetchColumn() !== count($ids)) {
                    $field_errors[] = 'Satu atau lebih entry relasi tidak ditemukan.';
                }
            }
        }

        if ($field_errors !== []) {
            $errors[$key] = $field_errors;
        } else {
            $clean[$key] = $value;
        }
    }

    return ['ok' => $errors === [], 'data' => $clean, 'errors' => $errors];
}

/**
 * Daftar field required yang nilainya kosong — dipakai untuk memblokir
 * status non-draft (published/archived) bila ada field wajib belum diisi.
 */
function entry_missing_required_fields(array $data, array $fields): array
{
    $missing = [];
    foreach ($fields as $field) {
        if (empty($field['is_required'])) {
            continue;
        }
        $key = $field['field_key'];
        if (!validate_required($data[$key] ?? null)) {
            $missing[] = sprintf('Field "%s" wajib diisi untuk status ini.', $field['label'] ?? $key);
        }
    }
    return $missing;
}
