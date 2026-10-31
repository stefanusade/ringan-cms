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
 * Status yang boleh dipakai untuk filter admin, termasuk trash (soft delete).
 */
function entry_filter_statuses(): array
{
    return array_merge(entry_statuses(), ['trash']);
}

function entry_is_trashed(array $entry): bool
{
    return (string) ($entry['status'] ?? '') === 'trash';
}

/**
 * Apakah kolom status sudah mendukung 'trash' (migrasi 009 diterapkan)?
 */
function entry_trash_supported(): bool
{
    static $supported = null;
    if ($supported !== null) {
        return $supported;
    }
    try {
        $stmt = get_db()->query("SHOW COLUMNS FROM content_entries LIKE 'status'");
        $column = $stmt->fetch();
        $supported = is_array($column) && str_contains((string) ($column['Type'] ?? ''), "'trash'");
    } catch (Throwable $e) {
        $supported = false;
    }
    return $supported;
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
    if (!in_array($status, entry_filter_statuses(), true)) {
        $status = '';
    }
    if ($status === 'trash' && !entry_trash_supported()) {
        $status = '';
    }

    $where = 'WHERE ce.content_type_id = ?';
    $params = [$content_type_id];
    if ($status !== '') {
        $where .= ' AND ce.status = ?';
        $params[] = $status;
    } else {
        // "Semua" tidak menampilkan entri yang sudah dibuang ke Trash.
        $where .= " AND ce.status <> 'trash'";
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

function create_entry(int $content_type_id, array $data, string $status, ?int $created_by = null): int
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

function set_entry_status(int $id, string $status): void
{
    if (!in_array($status, entry_filter_statuses(), true)) {
        throw new InvalidArgumentException('Status entri tidak valid.');
    }
    $stmt = get_db()->prepare('UPDATE content_entries SET status = ? WHERE id = ?');
    $stmt->execute([$status, $id]);
}

function trash_entry(int $id): void
{
    set_entry_status($id, 'trash');
}

function restore_entry(int $id): void
{
    set_entry_status($id, 'draft');
}

/**
 * Jumlah entri per status untuk tab daftar entri.
 *
 * @return array<string,int>
 */
function count_entries_by_status(int $content_type_id): array
{
    $stmt = get_db()->prepare(
        'SELECT status, COUNT(*) AS total FROM content_entries WHERE content_type_id = ? GROUP BY status'
    );
    $stmt->execute([$content_type_id]);
    $counts = [];
    foreach ($stmt->fetchAll() as $row) {
        $counts[(string) $row['status']] = (int) $row['total'];
    }
    return $counts;
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
        // Sertakan options hasil decode agar batasan field (max_length, min/max,
        // daftar pilihan select) benar-benar ditegakkan saat validasi.
        $field_with_options = array_merge($field, [
            'options' => decode_options($field['options_json'] ?? null),
        ]);
        $value = validate_field_value($field_with_options, $raw, $field_errors, $enforce_required);

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
                $stmt = get_db()->prepare("SELECT COUNT(*) FROM content_entries WHERE content_type_id = ? AND status <> 'trash' AND id IN ($placeholders)");
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
 * Tipe field yang boleh diedit lewat Quick Edit di tabel entri.
 * Sengaja dibatasi ke field ringan (tanpa upload/relasi/HTML) agar aman & cepat.
 */
function entry_quick_edit_field_types(): array
{
    return ['text', 'date'];
}

/**
 * Filter definisi field menjadi hanya field yang bisa diedit via Quick Edit.
 */
function entry_quick_edit_fields(array $fields): array
{
    $allowed = entry_quick_edit_field_types();
    return array_values(array_filter(
        $fields,
        static fn(array $field): bool => in_array($field['field_type'] ?? '', $allowed, true)
    ));
}

/**
 * Simpan perubahan Quick Edit: hanya field text/date yang diproses, field lain
 * dan status entri dibiarkan apa adanya (partial update, anti mass-assignment).
 *
 * @return array{ok:bool,errors:array<string,string[]>}
 */
function update_entry_quick_fields(array $entry, array $fields, array $payload): array
{
    $quick_fields = entry_quick_edit_fields($fields);
    $editable_keys = array_column($quick_fields, 'field_key');

    $submitted = [];
    foreach ($payload as $key => $value) {
        if (in_array((string) $key, $editable_keys, true)) {
            $submitted[(string) $key] = $value;
        }
    }

    // Field wajib hanya dicek saat entri bukan draft (konsisten dengan form edit).
    $status = (string) ($entry['status'] ?? 'draft');
    $enforce_required = !in_array($status, ['draft', 'trash'], true);

    $result = validate_entry_payload($submitted, $quick_fields, true, $enforce_required);
    if (!$result['ok']) {
        return ['ok' => false, 'errors' => $result['errors']];
    }

    $data = array_merge(decode_entry_data($entry), $result['data']);
    update_entry((int) $entry['id'], $data, $status);
    return ['ok' => true, 'errors' => []];
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
