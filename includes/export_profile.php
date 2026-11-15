<?php
/**
 * Profil ekspor/impor per content type (JSON).
 *
 * Profil adalah kontrak antara plugin WordPress "Ringan Export" dan import ZIP
 * di CMS. Profil menjelaskan:
 *  - content type tujuan,
 *  - post type/status WordPress yang diekspor,
 *  - pemetaan setiap field CMS ke sumber WordPress (post.* atau acf.*),
 *  - transformasi nilai dan opsi media/taksonomi.
 *
 * Profil disimpan di tabel export_profiles (satu baris per content type).
 * Bila tabel belum tersedia (user DB tanpa hak DDL), CMS tetap dapat membuat
 * profil default secara on-the-fly.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/content_types.php';
require_once __DIR__ . '/content_entries.php';
require_once __DIR__ . '/taxonomies.php';
require_once __DIR__ . '/response.php';

const EXPORT_PROFILE_FORMAT = 'ringan-export-profile';
const EXPORT_PROFILE_VERSION = 1;

const EXPORT_PROFILE_POST_SOURCES = [
    'post.title' => 'Judul post',
    'post.content' => 'Konten post (HTML)',
    'post.excerpt' => 'Excerpt post',
    'post.slug' => 'Slug post',
    'post.date' => 'Tanggal publish',
    'post.modified' => 'Tanggal modifikasi',
    'post.status' => 'Status WordPress',
    'post.author' => 'Author',
    'post.thumbnail' => 'Gambar unggulan (featured image)',
];

const EXPORT_PROFILE_TRANSFORMS = [
    'auto', 'text', 'html', 'date', 'number', 'boolean',
    'select', 'media', 'gallery', 'relation', 'raw',
];

/* =========================================================================
 * Penyimpanan profil
 * ========================================================================= */

function export_profiles_ensure_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        get_db()->exec(
            'CREATE TABLE IF NOT EXISTS export_profiles (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                content_type_id INT UNSIGNED NOT NULL,
                profile_json JSON NOT NULL,
                updated_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_export_profile_ct (content_type_id),
                CONSTRAINT fk_ep_content_type FOREIGN KEY (content_type_id) REFERENCES content_types(id) ON DELETE CASCADE,
                CONSTRAINT fk_ep_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        // Least-privilege DB user (tanpa CREATE) tetap bisa memakai profil default.
    }
}

function export_profiles_available(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    export_profiles_ensure_table();
    try {
        get_db()->query('SELECT 1 FROM export_profiles LIMIT 1');
        $available = true;
    } catch (Throwable $e) {
        $available = false;
    }
    return $available;
}

function export_profile_get_saved(int $content_type_id): ?array
{
    if (!export_profiles_available()) {
        return null;
    }
    try {
        $stmt = get_db()->prepare(
            'SELECT profile_json FROM export_profiles WHERE content_type_id = ? LIMIT 1'
        );
        $stmt->execute([$content_type_id]);
        $json = $stmt->fetchColumn();
        if ($json === false || $json === null || $json === '') {
            return null;
        }
        $decoded = json_decode((string) $json, true);
        return is_array($decoded) ? $decoded : null;
    } catch (Throwable $e) {
        log_error('Gagal membaca profil ekspor: ' . $e->getMessage(), 'export_profile');
        return null;
    }
}

function export_profile_save(int $content_type_id, array $profile, ?int $user_id = null): bool
{
    if (!export_profiles_available()) {
        return false;
    }
    $json = json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    try {
        $stmt = get_db()->prepare(
            'INSERT INTO export_profiles (content_type_id, profile_json, updated_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE profile_json = VALUES(profile_json),
                                     updated_by = VALUES(updated_by)'
        );
        $stmt->execute([$content_type_id, $json, $user_id]);
        return true;
    } catch (Throwable $e) {
        log_error('Gagal menyimpan profil ekspor: ' . $e->getMessage(), 'export_profile');
        return false;
    }
}

function export_profile_delete(int $content_type_id): bool
{
    if (!export_profiles_available()) {
        return false;
    }
    try {
        $stmt = get_db()->prepare('DELETE FROM export_profiles WHERE content_type_id = ?');
        $stmt->execute([$content_type_id]);
        return true;
    } catch (Throwable $e) {
        log_error('Gagal menghapus profil ekspor: ' . $e->getMessage(), 'export_profile');
        return false;
    }
}

/* =========================================================================
 * Profil default (dibuat dari definisi field CMS)
 * ========================================================================= */

function export_profile_default_status_map(): array
{
    return [
        'publish' => 'published',
        'draft' => 'draft',
        'private' => 'draft',
        'future' => 'draft',
        'pending' => 'draft',
    ];
}

function export_profile_slug(mixed $value): string
{
    $value = strtolower(trim((string) $value));
    return preg_match('/^[a-z0-9_-]{1,100}$/', $value) ? $value : '';
}

/**
 * Tebak sumber WordPress yang paling masuk akal untuk sebuah field CMS.
 * Pengguna tetap dapat mengubahnya lewat editor JSON.
 */
function export_profile_default_source(array $field, array $fields): string
{
    $key = (string) ($field['field_key'] ?? '');
    $label = (string) ($field['label'] ?? '');
    $type = (string) ($field['field_type'] ?? 'text');

    $normalize = static function (string $value): string {
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;
        return trim($value, '_');
    };
    $key_n = $normalize($key);
    $label_n = $normalize($label);

    if (!in_array($type, ['image', 'file', 'gallery'], true)) {
        $map = [
            'post.title' => ['title', 'judul', 'post_title', 'nama', 'headline'],
            'post.content' => ['content', 'body', 'konten', 'isi', 'post_content'],
            'post.excerpt' => ['excerpt', 'ringkasan', 'summary', 'post_excerpt'],
            'post.slug' => ['slug', 'post_name'],
            'post.date' => ['date', 'tanggal', 'published_at', 'publish_date', 'post_date'],
            'post.modified' => ['modified', 'updated_at', 'post_modified'],
            'post.status' => ['status', 'post_status'],
            'post.author' => ['author', 'penulis', 'creator'],
        ];
        foreach ($map as $source => $aliases) {
            if (in_array($key_n, $aliases, true) || in_array($label_n, $aliases, true)) {
                return $source;
            }
        }
    }

    $image_aliases = [
        'thumbnail', 'featured_image', 'featured', 'gambar_utama', 'foto_utama',
        'gambar_unggulan', 'foto_unggulan', 'cover', 'image', 'gambar', 'foto',
    ];
    if (in_array($type, ['image', 'file'], true)
        && (in_array($key_n, $image_aliases, true) || in_array($label_n, $image_aliases, true))) {
        return 'post.thumbnail';
    }

    // Konvensi paling umum: nama field ACF sama dengan field key CMS.
    $acf_key = validate_field_key($key) ? $key : 'field';
    return 'acf.' . $acf_key;
}

function export_profile_default_field(array $field, array $fields): array
{
    $type = (string) ($field['field_type'] ?? 'text');
    $source = export_profile_default_source($field, $fields);
    $field_options = decode_options($field['options_json'] ?? null);
    $options = [];

    if ($type === 'select') {
        $options['value_map'] = $source === 'post.status' ? export_profile_default_status_map() : [];
    }
    if ($type === 'relation') {
        $options['relation_target'] = export_profile_slug($field_options['relation_target'] ?? '');
    }
    if ($type === 'gallery') {
        $options['media_max'] = 20;
    }

    return [
        'key' => (string) ($field['field_key'] ?? ''),
        'label' => (string) ($field['label'] ?? $field['field_key'] ?? ''),
        'type' => $type,
        'required' => (bool) ($field['is_required'] ?? false),
        'source' => $source,
        'transform' => 'auto',
        'options' => $options,
    ];
}

/**
 * @return array<string,mixed>
 */
function export_profile_default(array $ct, array $fields): array
{
    $taxonomies = [];
    foreach (get_taxonomies((int) $ct['id']) as $tax) {
        $taxonomies[] = [
            'wp_taxonomy' => (string) $tax['slug'],
            'ringan_taxonomy' => (string) $tax['slug'],
        ];
    }

    $profile = [
        'format' => EXPORT_PROFILE_FORMAT,
        'format_version' => EXPORT_PROFILE_VERSION,
        'generator' => 'Ringan CMS ' . RINGAN_CMS_VERSION,
        'generated_at' => gmdate('c'),
        'content_type' => [
            'slug' => (string) $ct['slug'],
            'label' => (string) $ct['label'],
        ],
        'source' => [
            'post_type' => 'post',
            'post_types' => ['post'],
            'post_statuses' => ['publish', 'draft', 'private'],
            'taxonomies' => $taxonomies,
        ],
        'options' => [
            'status_map' => export_profile_default_status_map(),
            'default_status' => 'draft',
            'include_media' => true,
            'include_content_media' => true,
            'media_max_bytes' => min(MAX_UPLOAD_SIZE, 10485760),
            'duplicates' => 'skip',
        ],
        'fields' => [],
    ];

    foreach ($fields as $field) {
        $profile['fields'][] = export_profile_default_field($field, $fields);
    }

    return $profile;
}

/* =========================================================================
 * Validasi & normalisasi profil
 * ========================================================================= */

function export_profile_source_is_valid(string $source): bool
{
    $source = trim($source);
    if ($source === '' || $source === 'none') {
        return true;
    }
    if (isset(EXPORT_PROFILE_POST_SOURCES[$source])) {
        return true;
    }
    foreach (['acf.', 'meta.'] as $prefix) {
        if (str_starts_with($source, $prefix)) {
            $key = substr($source, strlen($prefix));
            return (bool) preg_match('/^[A-Za-z0-9_-]{1,100}$/', $key);
        }
    }
    return false;
}

function export_profile_normalize_field_options(array $field, mixed $raw_options): array
{
    $raw_options = is_array($raw_options) ? $raw_options : [];
    $type = (string) ($field['field_type'] ?? 'text');
    $options = [];

    if (isset($raw_options['value_map']) && is_array($raw_options['value_map'])) {
        $map = [];
        foreach ($raw_options['value_map'] as $from => $to) {
            $from = trim((string) $from);
            $to = trim((string) $to);
            if ($from !== '' && $to !== '') {
                $map[$from] = $to;
            }
        }
        if ($map !== []) {
            $options['value_map'] = $map;
        }
    }

    if (isset($raw_options['true_values']) && is_array($raw_options['true_values'])) {
        $values = [];
        foreach ($raw_options['true_values'] as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $values[] = $value;
            }
        }
        if ($values !== []) {
            $options['true_values'] = array_values(array_unique($values));
        }
    }

    if ($type === 'relation') {
        $default = decode_options($field['options_json'] ?? null);
        $target = export_profile_slug($raw_options['relation_target'] ?? ($default['relation_target'] ?? ''));
        if ($target !== '') {
            $options['relation_target'] = $target;
        }
    }

    if ($type === 'gallery') {
        $max = (int) ($raw_options['media_max'] ?? 20);
        $options['media_max'] = max(1, min(100, $max));
    }

    return $options;
}

/**
 * Validasi dan normalisasi profil terhadap definisi content type saat ini.
 * Field yang belum ada di profil akan ditambahkan otomatis dengan sumber default.
 *
 * @return array{ok:bool,errors:string[],warnings:string[],profile:array}
 */
function export_profile_validate(array $profile, array $ct, array $fields): array
{
    $errors = [];
    $warnings = [];

    $format = (string) ($profile['format'] ?? '');
    if ($format !== EXPORT_PROFILE_FORMAT) {
        $errors[] = 'Format profil harus "' . EXPORT_PROFILE_FORMAT . '".';
    }
    $version = (int) ($profile['format_version'] ?? 0);
    if ($version < 1 || $version > EXPORT_PROFILE_VERSION) {
        $errors[] = 'Versi profil tidak didukung (maks ' . EXPORT_PROFILE_VERSION . ').';
    }

    $profile_slug = export_profile_slug($profile['content_type']['slug'] ?? '');
    if ($profile_slug !== (string) $ct['slug']) {
        $errors[] = sprintf(
            'Profil ditujukan untuk content type "%s", bukan "%s".',
            $profile_slug !== '' ? $profile_slug : '(kosong)',
            (string) $ct['slug']
        );
    }

    /* ---- source ---- */
    $source_in = is_array($profile['source'] ?? null) ? $profile['source'] : [];
    $post_type = export_profile_slug($source_in['post_type'] ?? 'post');
    if ($post_type === '') {
        $post_type = 'post';
    }
    $post_types = [];
    foreach ((array) ($source_in['post_types'] ?? []) as $candidate) {
        $candidate = export_profile_slug($candidate);
        if ($candidate !== '') {
            $post_types[] = $candidate;
        }
    }
    if ($post_types === []) {
        $post_types = [$post_type];
    }
    if (!in_array($post_type, $post_types, true)) {
        array_unshift($post_types, $post_type);
    }

    $post_statuses = [];
    foreach ((array) ($source_in['post_statuses'] ?? []) as $status) {
        $status = strtolower(trim((string) $status));
        if (preg_match('/^[a-z0-9_-]{1,50}$/', $status)) {
            $post_statuses[] = $status;
        }
    }
    if ($post_statuses === []) {
        $post_statuses = ['publish', 'draft', 'private'];
    }

    $ct_taxonomy_slugs = [];
    foreach (get_taxonomies((int) $ct['id']) as $tax) {
        $ct_taxonomy_slugs[] = (string) $tax['slug'];
    }
    $taxonomies = [];
    $seen_taxonomies = [];
    foreach ((array) ($source_in['taxonomies'] ?? []) as $tax) {
        if (!is_array($tax)) {
            continue;
        }
        $wp_tax = export_profile_slug($tax['wp_taxonomy'] ?? '');
        $ringan_tax = export_profile_slug($tax['ringan_taxonomy'] ?? '');
        if ($wp_tax === '' || $ringan_tax === '') {
            continue;
        }
        if (!in_array($ringan_tax, $ct_taxonomy_slugs, true)) {
            $warnings[] = sprintf('Taksonomi Ringan "%s" tidak terpasang pada content type ini.', $ringan_tax);
        }
        $pair = $wp_tax . '|' . $ringan_tax;
        if (isset($seen_taxonomies[$pair])) {
            continue;
        }
        $seen_taxonomies[$pair] = true;
        $taxonomies[] = [
            'wp_taxonomy' => $wp_tax,
            'ringan_taxonomy' => $ringan_tax,
        ];
    }

    /* ---- options ---- */
    $options_in = is_array($profile['options'] ?? null) ? $profile['options'] : [];
    $allowed_statuses = entry_statuses();
    $status_map = [];
    foreach ((array) ($options_in['status_map'] ?? []) as $wp_status => $cms_status) {
        $wp_status = strtolower(trim((string) $wp_status));
        $cms_status = (string) $cms_status;
        if ($wp_status !== '' && in_array($cms_status, $allowed_statuses, true)) {
            $status_map[$wp_status] = $cms_status;
        }
    }
    if ($status_map === []) {
        $status_map = export_profile_default_status_map();
    }

    $default_status = (string) ($options_in['default_status'] ?? 'draft');
    if (!in_array($default_status, $allowed_statuses, true)) {
        $default_status = 'draft';
    }

    $media_max_bytes = (int) ($options_in['media_max_bytes'] ?? 10485760);
    $media_max_bytes = max(1048576, min(MAX_UPLOAD_SIZE, $media_max_bytes));

    $duplicates = (string) ($options_in['duplicates'] ?? 'skip');
    if (!in_array($duplicates, ['skip', 'update', 'duplicate'], true)) {
        $duplicates = 'skip';
    }

    $options = [
        'status_map' => $status_map,
        'default_status' => $default_status,
        'include_media' => array_key_exists('include_media', $options_in)
            ? !empty($options_in['include_media'])
            : true,
        'include_content_media' => array_key_exists('include_content_media', $options_in)
            ? !empty($options_in['include_content_media'])
            : true,
        'media_max_bytes' => $media_max_bytes,
        'duplicates' => $duplicates,
    ];

    /* ---- fields ---- */
    $ct_fields = [];
    foreach ($fields as $field) {
        $ct_fields[(string) $field['field_key']] = $field;
    }

    $profile_fields_in = is_array($profile['fields'] ?? null) ? $profile['fields'] : [];
    $by_key = [];
    foreach ($profile_fields_in as $profile_field) {
        if (!is_array($profile_field)) {
            continue;
        }
        $key = (string) ($profile_field['key'] ?? '');
        if ($key === '') {
            continue;
        }
        if (!isset($ct_fields[$key])) {
            $errors[] = sprintf('Profil memuat field "%s" yang tidak ada pada content type.', $key);
            continue;
        }
        $by_key[$key] = $profile_field;
    }

    $normalized_fields = [];
    foreach ($ct_fields as $key => $field) {
        $field_type = (string) ($field['field_type'] ?? 'text');
        if (!isset($by_key[$key])) {
            $warnings[] = sprintf(
                'Field "%s" belum ada di profil; ditambahkan dengan sumber default.',
                (string) ($field['label'] ?? $key)
            );
            $normalized_fields[] = export_profile_default_field($field, $fields);
            continue;
        }

        $profile_field = $by_key[$key];
        $type = (string) ($profile_field['type'] ?? '');
        if ($type !== $field_type) {
            $errors[] = sprintf(
                'Tipe field "%s" di profil (%s) tidak cocok dengan content type (%s).',
                $key,
                $type !== '' ? $type : '(kosong)',
                $field_type
            );
            $type = $field_type;
        }

        $source = trim((string) ($profile_field['source'] ?? ''));
        if (str_starts_with($source, 'meta.')) {
            $source = 'acf.' . substr($source, 5);
        }
        if ($source === '') {
            $source = 'none';
        }
        if (!export_profile_source_is_valid($source)) {
            $errors[] = sprintf('Sumber field "%s" tidak valid: %s.', $key, $source);
            $source = 'none';
        }

        $transform = (string) ($profile_field['transform'] ?? 'auto');
        if (!in_array($transform, EXPORT_PROFILE_TRANSFORMS, true)) {
            $errors[] = sprintf('Transform field "%s" tidak valid: %s.', $key, $transform);
            $transform = 'auto';
        }

        $normalized_fields[] = [
            'key' => $key,
            'label' => (string) ($field['label'] ?? $key),
            'type' => $type,
            'required' => (bool) ($field['is_required'] ?? false),
            'source' => $source,
            'transform' => $transform,
            'options' => export_profile_normalize_field_options($field, $profile_field['options'] ?? []),
        ];
    }

    $normalized = [
        'format' => EXPORT_PROFILE_FORMAT,
        'format_version' => EXPORT_PROFILE_VERSION,
        'generator' => 'Ringan CMS ' . RINGAN_CMS_VERSION,
        'generated_at' => (string) ($profile['generated_at'] ?? gmdate('c')),
        'content_type' => [
            'slug' => (string) $ct['slug'],
            'label' => (string) $ct['label'],
        ],
        'source' => [
            'post_type' => $post_type,
            'post_types' => array_values(array_unique($post_types)),
            'post_statuses' => array_values(array_unique($post_statuses)),
            'taxonomies' => $taxonomies,
        ],
        'options' => $options,
        'fields' => $normalized_fields,
    ];

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'warnings' => $warnings,
        'profile' => $normalized,
    ];
}

/**
 * Profil yang siap dipakai: profil tersimpan bila valid, selain itu default.
 *
 * @return array<string,mixed>|null
 */
function export_profile_effective(int $content_type_id): ?array
{
    $ct = get_content_type($content_type_id);
    if ($ct === null) {
        return null;
    }
    $fields = get_fields($content_type_id);
    $default = export_profile_default($ct, $fields);
    $saved = export_profile_get_saved($content_type_id);
    if ($saved === null) {
        return $default;
    }
    $validated = export_profile_validate($saved, $ct, $fields);
    return $validated['ok'] ? $validated['profile'] : $default;
}

function export_profile_json(array $profile): string
{
    return (string) json_encode(
        $profile,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}
