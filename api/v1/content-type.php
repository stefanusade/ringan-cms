<?php
/**
 * Handler generik per content type.
 * Di-include oleh api/v1/index.php — variabel $auth, $slug, $id sudah tersedia.
 *
 * Endpoint: /api/v1/{content_type_slug}[/{id}]
 * Method: GET (list & detail), POST (create), PUT/PATCH (update), DELETE.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/content_types.php';
require_once dirname(__DIR__, 2) . '/includes/content_entries.php';
require_once dirname(__DIR__, 2) . '/includes/upload.php';
require_once dirname(__DIR__, 2) . '/includes/audit_log.php';
require_once dirname(__DIR__, 2) . '/includes/taxonomies.php';

$ct = get_content_type_by_slug($slug);
if ($ct === null || !(int) $ct['is_api_enabled']) {
    json_error('not_found', 'Content type tidak ditemukan.', 404);
}

// Batasan content type dari API key
if (($auth['type'] ?? '') === 'api_key') {
    $restriction = json_decode($auth['api_key']['content_type_restriction'] ?? 'null', true);
    if (is_array($restriction) && $restriction !== [] && !in_array($ct['slug'], $restriction, true)) {
        json_error('forbidden', 'API key tidak memiliki akses ke content type ini.', 403);
    }
}

// GET /{slug}/taxonomies — daftar taksonomi + term (read)
if (!empty($taxonomies_request)) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        json_error('method_not_allowed', 'Method tidak diizinkan.', 405);
    }
    $taxonomies = get_taxonomies_with_terms((int) $ct['id']);
    $data = [];
    foreach ($taxonomies as $tax) {
        $terms = [];
        foreach ($tax['terms'] as $term) {
            $terms[] = [
                'id' => (int) $term['id'],
                'name' => $term['name'],
                'slug' => $term['slug'],
                'parent_id' => $term['parent_id'] !== null ? (int) $term['parent_id'] : null,
            ];
        }
        $data[] = [
            'id' => (int) $tax['id'],
            'slug' => $tax['slug'],
            'label' => $tax['label'],
            'is_hierarchical' => (bool) $tax['is_hierarchical'],
            'terms' => $terms,
        ];
    }
    json_response(['success' => true, 'data' => $data]);
}

$is_write = ($auth['type'] ?? '') === 'jwt'
    ? in_array($auth['user']['role'] ?? '', ['superadmin', 'editor'], true)
    : (($auth['api_key']['scope'] ?? '') === 'read_write');

function api_entry_payload(array $entry): array
{
    return [
        'id' => (int) $entry['id'],
        'status' => $entry['status'],
        'created_at' => $entry['created_at'],
        'updated_at' => $entry['updated_at'],
        'fields' => decode_entry_data($entry),
        'terms' => entry_terms_map((int) $entry['id']),
    ];
}

function api_read_body(): array
{
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body)) {
        json_error('validation_error', 'Body harus berupa JSON object.', 400);
    }
    return $body;
}

/**
 * Resolusi terms dari body API: { "terms": { "category": ["berita"], "tag": ["a"] } }.
 * Slug taksonomi & term divalidasi; tak dikenal → 422 (anti mass-assignment).
 */
function api_resolve_terms(array $body, array $ct): array
{
    if (!isset($body['terms'])) {
        return [];
    }
    $terms_payload = $body['terms'];
    if (!is_array($terms_payload)) {
        json_error('validation_error', 'terms harus berupa object { "slug_taksonomi": ["slug_term"] }.', 400);
    }
    $all = [];
    foreach ($terms_payload as $tax_slug => $slugs) {
        $tax_row = get_taxonomy_by_slug((int) $ct['id'], (string) $tax_slug);
        if ($tax_row === null) {
            json_error('validation_error', sprintf('Taksonomi "%s" tidak ditemukan pada content type ini.', $tax_slug), 422);
        }
        $slugs = is_array($slugs) ? array_map('strval', $slugs) : [(string) $slugs];
        $ids = resolve_term_ids_by_slug($slugs, (int) $tax_row['id']);
        if (count($ids) !== count(array_unique(array_map('trim', $slugs)))) {
            json_error('validation_error', sprintf('Satu atau lebih term pada taksonomi "%s" tidak ditemukan.', $tax_slug), 422);
        }
        $all = array_merge($all, $ids);
    }
    return $all;
}

/**
 * Proses payload: tangani image/file (path relatif atau data URI base64)
 * lalu kembalikan payload siap divalidasi.
 */
function api_prepare_payload(array $body, array $fields): array
{
    $payload = [];
    foreach ($fields as $field) {
        $key = $field['field_key'];
        if (!array_key_exists($key, $body)) {
            continue;
        }
        $raw = $body[$key];
        $ftype = $field['field_type'] ?? '';
        if (in_array($ftype, ['image', 'file'], true) && is_string($raw)) {
            if (str_starts_with($raw, 'data:')) {
                $res = save_base64_upload($raw, $ftype === 'image');
                if (!$res['ok']) {
                    json_error('validation_error', 'Gagal memproses upload: ' . $res['error'], 400);
                }
                $raw = $res['path'];
            } elseif ($raw !== '' && !valid_upload_path($raw)) {
                json_error('validation_error', sprintf('Field "%s": path file tidak valid.', $key), 400);
            }
        }
        if ($ftype === 'gallery') {
            if (!is_array($raw)) {
                json_error('validation_error', sprintf('Field "%s" harus berupa array path gambar.', $key), 400);
            }
            $clean_items = [];
            foreach ($raw as $item) {
                if (!is_string($item)) {
                    json_error('validation_error', sprintf('Field "%s": item galeri tidak valid.', $key), 400);
                }
                if (str_starts_with($item, 'data:')) {
                    $res = save_base64_upload($item, true);
                    if (!$res['ok']) {
                        json_error('validation_error', 'Gagal memproses upload: ' . $res['error'], 400);
                    }
                    $clean_items[] = $res['path'];
                } elseif ($item !== '' && !valid_upload_path($item)) {
                    json_error('validation_error', sprintf('Field "%s": path file tidak valid.', $key), 400);
                } else {
                    $clean_items[] = $item;
                }
            }
            $payload[$key] = $clean_items;
            continue;
        }
        $payload[$key] = $raw;
    }
    return $payload;
}

function handle_api_get(array $ct, ?int $id): void
{
    if ($id !== null) {
        $entry = get_entry_for_content_type($id, (int) $ct['id']);
        if ($entry === null) {
            json_error('not_found', 'Entry tidak ditemukan.', 404);
        }
        json_response(['success' => true, 'data' => api_entry_payload($entry)]);
    }

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $per_page = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
    $status = (string) ($_GET['status'] ?? 'published');
    if ($status !== '' && !in_array($status, entry_statuses(), true)) {
        json_error('validation_error', 'Status tidak valid.', 400);
    }
    $sort = (string) ($_GET['sort'] ?? 'created_at');
    $order = strtoupper((string) ($_GET['order'] ?? 'DESC'));

    $filter_taxonomy_id = null;
    $filter_term_slug = null;
    if (isset($_GET['tax']) && isset($_GET['term'])) {
        $tax_row = get_taxonomy_by_slug((int) $ct['id'], sanitize_text((string) $_GET['tax']));
        if ($tax_row === null) {
            json_error('validation_error', 'Taksonomi tidak ditemukan.', 400);
        }
        $filter_taxonomy_id = (int) $tax_row['id'];
        $filter_term_slug = sanitize_text((string) $_GET['term']);
    }

    $result = get_entries((int) $ct['id'], $status, $page, $per_page, $sort, $order, $filter_taxonomy_id, $filter_term_slug);
    $items = array_map('api_entry_payload', $result['items']);

    json_response([
        'success' => true,
        'data' => $items,
        'meta' => [
            'total' => $result['total'],
            'page' => $result['page'],
            'per_page' => $result['per_page'],
            'total_pages' => $result['total_pages'],
        ],
    ]);
}

function handle_api_post(array $ct): void
{
    $fields = get_fields((int) $ct['id']);
    $body = api_read_body();
    $payload = api_prepare_payload($body, $fields);
    $status = (string) ($body['status'] ?? 'published');
    if (!in_array($status, entry_statuses(), true)) {
        json_error('validation_error', 'Status tidak valid.', 400);
    }

    $result = validate_entry_payload($payload, $fields, false);
    if (!$result['ok']) {
        json_error('validation_error', 'Validasi gagal.', 422, $result['errors']);
    }

    $all_term_ids = api_resolve_terms($body, $ct);

    $entry_id = create_entry((int) $ct['id'], $result['data'], $status, (int) ($GLOBALS['api_user_id'] ?? 0));
    set_entry_terms($entry_id, $all_term_ids);
    log_audit('api_create_entry', 'content_entries', (string) $entry_id, $GLOBALS['api_user_id'] ?? null);
    json_response(['success' => true, 'data' => ['id' => $entry_id]], 201);
}

function handle_api_put(array $ct, ?int $id, bool $partial): void
{
    if ($id === null) {
        json_error('validation_error', 'ID entry wajib ada di URL.', 400);
    }
    $entry = get_entry_for_content_type($id, (int) $ct['id']);
    if ($entry === null) {
        json_error('not_found', 'Entry tidak ditemukan.', 404);
    }

    $fields = get_fields((int) $ct['id']);
    $body = api_read_body();
    $payload = api_prepare_payload($body, $fields);
    $status = (string) ($body['status'] ?? $entry['status']);
    if (!in_array($status, entry_statuses(), true)) {
        json_error('validation_error', 'Status tidak valid.', 400);
    }

    $result = validate_entry_payload($payload, $fields, $partial);
    if (!$result['ok']) {
        json_error('validation_error', 'Validasi gagal.', 422, $result['errors']);
    }

    $existing = decode_entry_data($entry);
    $clean = $partial ? array_merge($existing, $result['data']) : $result['data'];

    update_entry($id, $clean, $status);
    if (array_key_exists('terms', $body)) {
        set_entry_terms($id, api_resolve_terms($body, $ct));
    }
    log_audit('api_update_entry', 'content_entries', (string) $id, $GLOBALS['api_user_id'] ?? null);
    json_response(['success' => true, 'data' => ['id' => $id]]);
}

function handle_api_delete(array $ct, ?int $id): void
{
    if ($id === null) {
        json_error('validation_error', 'ID entry wajib ada di URL.', 400);
    }
    $entry = get_entry_for_content_type($id, (int) $ct['id']);
    if ($entry === null) {
        json_error('not_found', 'Entry tidak ditemukan.', 404);
    }

    delete_entry($id);
    log_audit('api_delete_entry', 'content_entries', (string) $id, $GLOBALS['api_user_id'] ?? null);
    json_response(['success' => true, 'data' => ['id' => $id]]);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($method) {
    case 'GET':
        handle_api_get($ct, $id);
        break;
    case 'POST':
        if (!$is_write) {
            json_error('forbidden', 'Akses tulis tidak diizinkan.', 403);
        }
        handle_api_post($ct);
        break;
    case 'PUT':
    case 'PATCH':
        if (!$is_write) {
            json_error('forbidden', 'Akses tulis tidak diizinkan.', 403);
        }
        handle_api_put($ct, $id, $method === 'PATCH');
        break;
    case 'DELETE':
        if (!$is_write) {
            json_error('forbidden', 'Akses tulis tidak diizinkan.', 403);
        }
        handle_api_delete($ct, $id);
        break;
    default:
        json_error('method_not_allowed', 'Method tidak diizinkan.', 405);
}
