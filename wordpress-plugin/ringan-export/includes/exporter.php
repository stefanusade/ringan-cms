<?php
/**
 * Mesin ekspor: kumpulkan post + ACF + media, tulis ZIP sesuai manifest
 * "ringan-export" yang dibaca oleh Import ZIP di Ringan CMS.
 *
 * Ekspor dijalankan bertahap (batch per request) agar situs besar tidak
 * kehabisan waktu/memori:
 *   1. prepare   → kunci daftar post, buat file sementara
 *   2. batch x N → tulis satu baris JSON per entry ke file sementara
 *   3. finalize  → tambahkan media + manifest.json ke ZIP, siap diunduh
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const RINGAN_EXPORT_BATCH_SIZE = 20;
const RINGAN_EXPORT_TIME_LIMIT = 20;
// Disamakan dengan batas Import ZIP di Ringan CMS (5000 entry, 20000 file).
const RINGAN_EXPORT_MAX_POSTS = 5000;
const RINGAN_EXPORT_MAX_MEDIA_FILES = 19000;
const RINGAN_EXPORT_MAX_RELATIONS = 100;
const RINGAN_EXPORT_HARD_MEDIA_BYTES = 20971520;

/**
 * MIME yang diterima CMS (samakan dengan includes/upload.php di Ringan CMS).
 *
 * @return array<string,string> mime => ekstensi kanonik
 */
function ringan_export_mime_map(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/zip' => 'zip',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    ];
}

function ringan_export_image_mimes(): array
{
    return ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/x-icon', 'image/vnd.microsoft.icon'];
}

/* =========================================================================
 * Penyimpanan job (transient + file sementara)
 * ========================================================================= */

function ringan_export_job_key(string $token): string
{
    return 'ringan_export_job_' . $token;
}

function ringan_export_job_get(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $job = get_transient(ringan_export_job_key($token));
    return is_array($job) && isset($job['entries_path'], $job['media_path']) ? $job : null;
}

function ringan_export_job_save(string $token, array $job): void
{
    set_transient(ringan_export_job_key($token), $job, HOUR_IN_SECONDS);
}

function ringan_export_job_delete(string $token, array $job): void
{
    foreach (['zip_path', 'entries_path', 'media_path'] as $key) {
        if (!empty($job[$key]) && is_string($job[$key]) && is_file($job[$key])) {
            @unlink($job[$key]);
        }
    }
    delete_transient(ringan_export_job_key($token));
}

/**
 * Hapus file sementara ekspor yang tertinggal (lebih dari 24 jam).
 */
function ringan_export_cleanup_stale_files(): void
{
    $dir = rtrim(get_temp_dir(), '/\\');
    if ($dir === '' || !is_dir($dir)) {
        return;
    }
    $limit = time() - DAY_IN_SECONDS;
    foreach ((array) glob($dir . '/ringan-export-*') as $file) {
        if (!is_string($file) || !is_file($file)) {
            continue;
        }
        $mtime = @filemtime($file);
        if ($mtime !== false && $mtime < $limit) {
            @unlink($file);
        }
    }
}

function ringan_export_read_media(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $json = @file_get_contents($path);
    if (!is_string($json) || $json === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function ringan_export_write_media(string $path, array $media): bool
{
    $json = wp_json_encode($media, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) && @file_put_contents($path, $json, LOCK_EX) !== false;
}

/* =========================================================================
 * Media
 * ========================================================================= */

function ringan_export_uploads_dir(): array
{
    $uploads = wp_upload_dir(null, false);
    $basedir = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
    $baseurl = isset($uploads['baseurl']) ? (string) $uploads['baseurl'] : '';
    $real = $basedir !== '' ? realpath($basedir) : false;
    return [
        'basedir' => $real !== false ? $real : $basedir,
        'baseurl' => rtrim($baseurl, '/'),
    ];
}

function ringan_export_path_in_uploads(string $path): bool
{
    $base = ringan_export_uploads_dir()['basedir'];
    $real = realpath($path);
    if ($base === '' || $real === false) {
        return false;
    }
    return str_starts_with($real, rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
}

/**
 * Petakan URL media (termasuk ukuran turunan) ke file lokal di uploads.
 *
 * @return array{path:string,name:string,mime:string,size:int}|null
 */
function ringan_export_resolve_url_file(string $url): ?array
{
    $url = html_entity_decode(trim($url), ENT_QUOTES, 'UTF-8');
    if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, 'blob:')) {
        return null;
    }

    $absolute = $url;
    if (str_starts_with($url, '//')) {
        $absolute = (is_ssl() ? 'https:' : 'http:') . $url;
    } elseif (str_starts_with($url, '/')) {
        $absolute = home_url($url);
    }
    if (!preg_match('#^https?://#i', $absolute)) {
        return null;
    }

    $candidate = '';
    $attachment_id = ringan_export_url_to_attachment_id($absolute);
    if ($attachment_id > 0) {
        $attached = get_attached_file($attachment_id);
        if (is_string($attached) && $attached !== '' && is_file($attached)) {
            $candidate = $attached;
        }
    }

    if ($candidate === '') {
        $uploads = ringan_export_uploads_dir();
        $path_only = (string) parse_url($absolute, PHP_URL_PATH);
        $base_path = (string) parse_url($uploads['baseurl'], PHP_URL_PATH);
        $base_path = rtrim($base_path, '/');
        if ($base_path !== '' && str_starts_with($path_only, $base_path . '/')) {
            $relative = ltrim(substr($path_only, strlen($base_path)), '/');
            $direct = $uploads['basedir'] . '/' . $relative;
            if (is_file($direct)) {
                $candidate = $direct;
            }
        }
    }

    if ($candidate === '' || !is_file($candidate) || !ringan_export_path_in_uploads($candidate)) {
        return null;
    }

    $mime = ringan_export_file_mime($candidate);
    if ($mime === '') {
        return null;
    }

    return [
        'path' => $candidate,
        'name' => wp_basename($candidate),
        'mime' => $mime,
        'size' => (int) filesize($candidate),
    ];
}

function ringan_export_file_mime(string $path): string
{
    // Utamakan deteksi isi file: Ringan CMS memverifikasi ulang dengan finfo
    // dan menolak media bila MIME manifest tidak sama.
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = finfo_file($finfo, $path);
            finfo_close($finfo);
            if (is_string($mime) && $mime !== '') {
                return strtolower($mime);
            }
        }
    }
    $check = wp_check_filetype(wp_basename($path));
    if (is_array($check) && !empty($check['type'])) {
        return (string) $check['type'];
    }
    return '';
}

/**
 * Tambahkan file media ke peta ekspor. Mengembalikan path relatif di ZIP.
 *
 * @param array<string,array{path:string,name:string,mime:string,size:int}> $media
 */
function ringan_export_media_add(array $file, array &$media, int $max_bytes, array &$warnings): ?string
{
    $path = (string) ($file['path'] ?? '');
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        $warnings[] = 'File media tidak dapat dibaca dan dilewati.';
        return null;
    }
    if (!ringan_export_path_in_uploads($path)) {
        $warnings[] = 'File media di luar folder uploads dan dilewati: ' . wp_basename($path);
        return null;
    }

    $mime = (string) ($file['mime'] ?? '');
    $mime_map = ringan_export_mime_map();
    if (!isset($mime_map[$mime])) {
        $warnings[] = sprintf('Tipe media %s tidak didukung CMS dan dilewati: %s', $mime !== '' ? $mime : '(tidak dikenal)', wp_basename($path));
        return null;
    }
    $ext = $mime_map[$mime];

    $size = (int) filesize($path);
    $limit = max(1048576, min(RINGAN_EXPORT_HARD_MEDIA_BYTES, $max_bytes));
    if ($size <= 0) {
        $warnings[] = 'File media kosong dan dilewati: ' . wp_basename($path);
        return null;
    }
    if ($size > $limit) {
        $warnings[] = sprintf(
            'Media %s (%s) melebihi batas %s dan dilewati.',
            wp_basename($path),
            size_format($size),
            size_format($limit)
        );
        return null;
    }

    if (count($media) >= RINGAN_EXPORT_MAX_MEDIA_FILES) {
        $warnings[] = 'Batas jumlah media tercapai; sebagian media dilewati.';
        return null;
    }

    $rel = 'media/' . substr(sha1($path . '|' . $size . '|' . (string) filemtime($path)), 0, 20) . '.' . $ext;
    if (!isset($media[$rel])) {
        $media[$rel] = [
            'path' => $path,
            'name' => (string) ($file['name'] ?? wp_basename($path)),
            'mime' => $mime,
            'size' => $size,
        ];
    }
    return $rel;
}

/**
 * Ubah nilai ACF (ID/array/URL) menjadi referensi media di ZIP.
 */
function ringan_export_media_ref(mixed $value, array &$media, array $profile, array &$warnings, bool $image_only): ?string
{
    if ($value instanceof WP_Post) {
        $value = (int) $value->ID;
    }
    $id = ringan_export_attachment_id($value);
    $file = null;
    if ($id > 0) {
        $attached = get_attached_file($id);
        if (is_string($attached) && $attached !== '' && is_file($attached)) {
            $file = [
                'path' => $attached,
                'name' => wp_basename((string) get_post_meta($id, '_wp_attached_file', true)) ?: wp_basename($attached),
                'mime' => ringan_export_file_mime($attached),
                'size' => (int) filesize($attached),
            ];
        }
    }
    if ($file === null) {
        $url = '';
        if (is_string($value)) {
            $url = $value;
        } elseif (is_array($value) && isset($value['url']) && is_string($value['url'])) {
            $url = $value['url'];
        }
        if ($url !== '') {
            $file = ringan_export_resolve_url_file($url);
        }
    }
    if ($file === null) {
        if ($id <= 0) {
            $warnings[] = 'Nilai media tidak dapat dipetakan ke file di folder uploads.';
        }
        return null;
    }
    if ($image_only && !in_array($file['mime'], ringan_export_image_mimes(), true)) {
        $warnings[] = 'Field gambar berisi file non-gambar dan dilewati: ' . $file['name'];
        return null;
    }
    $max_bytes = (int) ($profile['options']['media_max_bytes'] ?? 10485760);
    return ringan_export_media_add($file, $media, $max_bytes, $warnings);
}

/**
 * Ganti URL media di HTML dengan placeholder {{ringan_media:media/xxx.jpg}}.
 */
function ringan_export_rewrite_content(string $html, array &$media, array $profile, array &$warnings): string
{
    if ($html === '' || strpos($html, '<') === false) {
        return $html;
    }
    $pattern = '/<\w+[^>]*?\b(?:src|href)\s*=\s*("([^"]*)"|\'([^\']*)\')/i';
    if (!preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
        return $html;
    }

    $replacements = [];
    foreach ($matches as $match) {
        $raw_url = (string) (($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? ''));
        if ($raw_url === '' || isset($replacements[$raw_url])) {
            continue;
        }
        $file = ringan_export_resolve_url_file($raw_url);
        if ($file === null) {
            continue;
        }
        $rel = ringan_export_media_add($file, $media, (int) ($profile['options']['media_max_bytes'] ?? 10485760), $warnings);
        if ($rel !== null) {
            $replacements[$raw_url] = '{{ringan_media:' . $rel . '}}';
        }
    }

    return $replacements === [] ? $html : strtr($html, $replacements);
}

/* =========================================================================
 * Sumber nilai & transformasi
 * ========================================================================= */

function ringan_export_source_value(string $source, WP_Post $post, array &$warnings): mixed
{
    switch ($source) {
        case 'post.title':
            return (string) get_the_title($post);
        case 'post.content':
            return (string) $post->post_content;
        case 'post.excerpt':
            if (trim((string) $post->post_excerpt) !== '') {
                return (string) $post->post_excerpt;
            }
            $excerpt = wp_trim_words(wp_strip_all_tags((string) $post->post_content), 40, '…');
            return $excerpt;
        case 'post.slug':
            return (string) $post->post_name;
        case 'post.date':
            return (string) get_post_time('Y-m-d', false, $post);
        case 'post.modified':
            return (string) get_post_modified_time('Y-m-d', false, $post);
        case 'post.status':
            return (string) $post->post_status;
        case 'post.author':
            return (string) get_the_author_meta('display_name', (int) $post->post_author);
        case 'post.thumbnail':
            return (int) get_post_thumbnail_id($post);
        case 'none':
            return null;
    }

    if (str_starts_with($source, 'acf.')) {
        return ringan_export_acf_value(substr($source, 4), (int) $post->ID);
    }

    $warnings[] = 'Sumber tidak dikenal dan dilewati: ' . $source;
    return null;
}

function ringan_export_kind(string $type, string $transform): string
{
    if ($transform !== 'auto') {
        return $transform;
    }
    return match ($type) {
        'image', 'file' => 'media',
        'gallery' => 'gallery',
        'relation' => 'relation',
        'richtext' => 'html',
        'date' => 'date',
        'number' => 'number',
        'boolean' => 'boolean',
        'select' => 'select',
        default => 'text',
    };
}

function ringan_export_scalar_string(mixed $value): string
{
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_scalar($value)) {
        return trim((string) $value);
    }
    if (is_array($value)) {
        $first = null;
        array_walk_recursive($value, static function ($item) use (&$first): void {
            if ($first === null && is_scalar($item)) {
                $first = (string) $item;
            }
        });
        return $first !== null ? trim($first) : '';
    }
    return '';
}

function ringan_export_to_bool(mixed $value, array $options): bool
{
    $true_values = [];
    foreach ((array) ($options['true_values'] ?? []) as $candidate) {
        $true_values[] = strtolower(trim((string) $candidate));
    }
    if ($true_values === []) {
        $true_values = ['1', 'true', 'yes', 'on', 'ya'];
    }
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return (float) $value != 0.0;
    }
    return in_array(strtolower(ringan_export_scalar_string($value)), $true_values, true);
}

function ringan_export_to_date(mixed $value): ?string
{
    $value = ringan_export_scalar_string($value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\d{8}$/', $value)) {
        $value = substr($value, 0, 4) . '-' . substr($value, 4, 2) . '-' . substr($value, 6, 2);
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }
    if (is_numeric($value) && strlen($value) >= 9) {
        return gmdate('Y-m-d', (int) $value);
    }
    $timestamp = strtotime($value);
    return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
}

/**
 * @return array<string,mixed>|null
 */
function ringan_export_transform_value(
    array $field,
    mixed $raw,
    WP_Post $post,
    array $profile,
    array &$media,
    array &$warnings
): mixed {
    $type = (string) ($field['type'] ?? 'text');
    $transform = (string) ($field['transform'] ?? 'auto');
    $options = is_array($field['options'] ?? null) ? $field['options'] : [];
    $kind = ringan_export_kind($type, $transform);
    $include_content_media = !empty($profile['options']['include_content_media']);

    switch ($kind) {
        case 'media':
            $image_only = $type === 'image';
            return ringan_export_media_ref($raw, $media, $profile, $warnings, $image_only);

        case 'gallery':
            $items = is_array($raw) ? $raw : [$raw];
            $refs = [];
            $max = max(1, min(100, (int) ($options['media_max'] ?? 20)));
            foreach (array_slice($items, 0, $max) as $item) {
                $rel = ringan_export_media_ref($item, $media, $profile, $warnings, true);
                if ($rel !== null) {
                    $refs[] = $rel;
                }
            }
            return $refs === [] ? null : $refs;

        case 'relation':
            $ids = ringan_export_collect_ids($raw);
            $ids = array_slice($ids, 0, RINGAN_EXPORT_MAX_RELATIONS);
            return $ids === [] ? null : ['__relation_source_ids' => array_map('strval', $ids)];

        case 'html':
            $html = ringan_export_scalar_string($raw);
            if ($html === '') {
                return null;
            }
            return $include_content_media
                ? ringan_export_rewrite_content($html, $media, $profile, $warnings)
                : $html;

        case 'date':
            return ringan_export_to_date($raw);

        case 'number':
            $value = ringan_export_scalar_string($raw);
            return is_numeric($value) ? $value + 0 : null;

        case 'boolean':
            return ringan_export_to_bool($raw, $options) ? 1 : 0;

        case 'select':
            $value = ringan_export_scalar_string($raw);
            if ($value === '') {
                return null;
            }
            $map = (array) ($options['value_map'] ?? []);
            if ($map !== [] && isset($map[$value])) {
                return (string) $map[$value];
            }
            return $value;

        case 'raw':
            if (is_scalar($raw)) {
                return (string) $raw;
            }
            $encoded = wp_json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return is_string($encoded) ? $encoded : null;

        case 'text':
        default:
            $value = ringan_export_scalar_string($raw);
            if ($value === '') {
                return null;
            }
            return wp_strip_all_tags($value);
    }
}

/**
 * Bangun satu entry manifest dari sebuah post.
 *
 * @param array<string,array{path:string,name:string,mime:string,size:int}> $media
 * @return array<string,mixed>|null
 */
function ringan_export_build_entry(WP_Post $post, array $profile, array &$media, array &$warnings): ?array
{
    $fields = [];
    foreach ((array) ($profile['fields'] ?? []) as $field) {
        $source = (string) ($field['source'] ?? 'none');
        if ($source === 'none' || $source === '') {
            continue;
        }
        $key = (string) ($field['key'] ?? '');
        if ($key === '') {
            continue;
        }
        $raw = ringan_export_source_value($source, $post, $warnings);
        if ($raw === null || $raw === '' || $raw === []) {
            continue;
        }
        if ($raw === 0 || $raw === '0') {
            $kind = ringan_export_kind((string) ($field['type'] ?? 'text'), (string) ($field['transform'] ?? 'auto'));
            if (!in_array($kind, ['number', 'boolean', 'select', 'text', 'raw'], true)) {
                continue;
            }
        }
        $value = ringan_export_transform_value($field, $raw, $post, $profile, $media, $warnings);
        if ($value === null || $value === '' || $value === []) {
            continue;
        }
        $fields[$key] = $value;
    }

    $terms = [];
    foreach ((array) ($profile['source']['taxonomies'] ?? []) as $pair) {
        $wp_tax = (string) ($pair['wp_taxonomy'] ?? '');
        $ringan_tax = (string) ($pair['ringan_taxonomy'] ?? '');
        if ($wp_tax === '' || $ringan_tax === '') {
            continue;
        }
        $post_terms = wp_get_post_terms((int) $post->ID, $wp_tax);
        if (is_wp_error($post_terms)) {
            continue;
        }
        foreach ($post_terms as $term) {
            if (!($term instanceof WP_Term)) {
                continue;
            }
            $terms[] = [
                'taxonomy' => $wp_tax,
                'domain' => $ringan_tax,
                'slug' => (string) $term->slug,
                'name' => (string) $term->name,
            ];
        }
    }

    return [
        'source_id' => (string) $post->ID,
        'title' => (string) get_the_title($post),
        'status' => ringan_export_map_status($profile, (string) $post->post_status),
        'fields' => $fields,
        'terms' => $terms,
    ];
}

/* =========================================================================
 * Tahap ekspor
 * ========================================================================= */

/**
 * @return array{ok:bool,error?:string,token?:string,total?:int}
 */
function ringan_export_prepare(array $profile, array $post_types, array $post_statuses, int $user_id): array
{
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'error' => 'Ekstensi PHP ZipArchive (ext-zip) tidak tersedia di server ini.'];
    }

    $registered = get_post_types([], 'names');
    $post_types = array_values(array_filter($post_types, static function ($type) use ($registered): bool {
        return is_string($type) && isset($registered[$type]);
    }));
    if ($post_types === []) {
        return ['ok' => false, 'error' => 'Pilih minimal satu post type yang valid.'];
    }

    $post_statuses = array_values(array_filter($post_statuses, static function ($status): bool {
        return is_string($status) && preg_match('/^[a-z0-9_-]{1,50}$/', $status);
    }));
    if ($post_statuses === []) {
        $post_statuses = ['publish'];
    }

    ringan_export_cleanup_stale_files();

    $ids = get_posts([
        'post_type' => $post_types,
        'post_status' => $post_statuses,
        'posts_per_page' => RINGAN_EXPORT_MAX_POSTS + 1,
        'fields' => 'ids',
        'orderby' => 'ID',
        'order' => 'ASC',
        'no_found_rows' => true,
        'suppress_filters' => true,
    ]);
    $ids = array_values(array_map('intval', (array) $ids));
    if ($ids === []) {
        return ['ok' => false, 'error' => 'Tidak ada post yang cocok dengan post type/status tersebut.'];
    }
    if (count($ids) > RINGAN_EXPORT_MAX_POSTS) {
        return [
            'ok' => false,
            'error' => sprintf(
                'Terlalu banyak post (%d+). Batasi post type/status atau bagi ekspor menjadi beberapa bagian (maks %d per ekspor).',
                RINGAN_EXPORT_MAX_POSTS,
                RINGAN_EXPORT_MAX_POSTS
            ),
        ];
    }

    $token = bin2hex(random_bytes(16));
    $zip_path = wp_tempnam('ringan-export-' . $token . '-');
    $entries_path = wp_tempnam('ringan-export-' . $token . '-entries-');
    $media_path = wp_tempnam('ringan-export-' . $token . '-media-');
    if ($zip_path === '' || $entries_path === '' || $media_path === ''
        || !is_file($zip_path) || !is_file($entries_path) || !is_file($media_path)) {
        return ['ok' => false, 'error' => 'Gagal membuat file sementara untuk ekspor.'];
    }
    @chmod($zip_path, 0600);
    @chmod($entries_path, 0600);
    @chmod($media_path, 0600);
    ringan_export_write_media($media_path, []);

    $job = [
        'token' => $token,
        'user_id' => $user_id,
        'zip_path' => $zip_path,
        'entries_path' => $entries_path,
        'media_path' => $media_path,
        'profile' => $profile,
        'post_types' => $post_types,
        'post_statuses' => $post_statuses,
        'ids' => $ids,
        'offset' => 0,
        'stats' => ['entries' => 0, 'media' => 0, 'statuses' => [], 'warnings' => []],
        'created_at' => time(),
        'finished' => false,
    ];
    ringan_export_job_save($token, $job);

    return ['ok' => true, 'token' => $token, 'total' => count($ids)];
}

/**
 * Proses sebagian post dan tulis entry ke file sementara.
 *
 * @return array{ok:bool,error?:string,processed?:int,total?:int,offset?:int,finished?:bool,stats?:array}
 */
function ringan_export_batch(string $token, int $user_id): array
{
    $job = ringan_export_job_get($token);
    if ($job === null || (int) ($job['user_id'] ?? 0) !== $user_id) {
        return ['ok' => false, 'error' => 'Sesi ekspor tidak ditemukan. Mulai ulang ekspor.'];
    }
    if (!empty($job['finished'])) {
        return ['ok' => false, 'error' => 'Sesi ekspor ini sudah selesai.'];
    }

    $ids = (array) ($job['ids'] ?? []);
    $total = count($ids);
    $offset = max(0, (int) ($job['offset'] ?? 0));
    $profile = (array) ($job['profile'] ?? []);
    $media = ringan_export_read_media((string) $job['media_path']);
    $warnings = (array) ($job['stats']['warnings'] ?? []);
    $stats = (array) ($job['stats'] ?? []);
    $stats['statuses'] = is_array($stats['statuses'] ?? null) ? $stats['statuses'] : [];

    $batch_lines = [];

    @set_time_limit(RINGAN_EXPORT_TIME_LIMIT + 15);
    $deadline = microtime(true) + RINGAN_EXPORT_TIME_LIMIT;
    $processed = 0;

    while ($offset < $total && $processed < RINGAN_EXPORT_BATCH_SIZE) {
        if (microtime(true) > $deadline) {
            break;
        }
        $post = get_post($ids[$offset]);
        $offset++;
        $processed++;
        if (!($post instanceof WP_Post)) {
            continue;
        }
        $entry = ringan_export_build_entry($post, $profile, $media, $warnings);
        if ($entry === null) {
            continue;
        }
        $line = wp_json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($line) || $line === '') {
            return ['ok' => false, 'error' => 'Gagal menyusun JSON entry (kemungkinan data tidak valid).'];
        }
        $batch_lines[] = $line;
        $stats['entries'] = (int) ($stats['entries'] ?? 0) + 1;
        $status = (string) ($entry['status'] ?? 'draft');
        $stats['statuses'][$status] = (int) ($stats['statuses'][$status] ?? 0) + 1;
    }

    // Tulis satu blok per batch agar kegagalan di tengah tidak menyisakan baris rusak.
    if ($batch_lines !== []) {
        $written = @file_put_contents(
            (string) $job['entries_path'],
            implode("\n", $batch_lines) . "\n",
            FILE_APPEND | LOCK_EX
        );
        if ($written === false) {
            return ['ok' => false, 'error' => 'Gagal menulis entry ekspor (kemungkinan disk penuh).'];
        }
    }

    $stats['media'] = count($media);
    $stats['warnings'] = array_slice(array_values(array_unique($warnings)), 0, 100);

    if (!ringan_export_write_media((string) $job['media_path'], $media)) {
        return ['ok' => false, 'error' => 'Gagal menyimpan indeks media sementara.'];
    }

    $job['offset'] = $offset;
    $job['stats'] = $stats;
    $job['finished'] = $offset >= $total;
    ringan_export_job_save($token, $job);

    return [
        'ok' => true,
        'processed' => $processed,
        'total' => $total,
        'offset' => $offset,
        'finished' => (bool) $job['finished'],
        'stats' => $stats,
    ];
}

function ringan_export_download_url(string $token): string
{
    return add_query_arg(
        [
            'action' => 'ringan_export_download',
            'token' => $token,
            '_wpnonce' => wp_create_nonce('ringan_export_download_' . $token),
        ],
        admin_url('admin-post.php')
    );
}

/**
 * Susun manifest.json + media ke ZIP, siap diunduh.
 *
 * @return array{ok:bool,error?:string,download_url?:string,size?:int,stats?:array}
 */
function ringan_export_finalize(string $token, int $user_id): array
{
    $job = ringan_export_job_get($token);
    if ($job === null || (int) ($job['user_id'] ?? 0) !== $user_id) {
        return ['ok' => false, 'error' => 'Sesi ekspor tidak ditemukan. Mulai ulang ekspor.'];
    }
    if (!empty($job['finished'])) {
        $zip_path = (string) ($job['zip_path'] ?? '');
        if ($zip_path !== '' && is_file($zip_path)) {
            return [
                'ok' => true,
                'download_url' => ringan_export_download_url($token),
                'size' => (int) ($job['zip_size'] ?? filesize($zip_path)),
                'stats' => (array) ($job['stats'] ?? []),
            ];
        }
        return ['ok' => false, 'error' => 'ZIP hasil ekspor sudah diunduh/dihapus. Mulai ekspor baru.'];
    }
    $profile = (array) ($job['profile'] ?? []);
    $media = ringan_export_read_media((string) $job['media_path']);
    $warnings = (array) ($job['stats']['warnings'] ?? []);

    $zip = new ZipArchive();
    if ($zip->open((string) $job['zip_path'], ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['ok' => false, 'error' => 'Gagal membuat arsip ZIP di server.'];
    }

    $media_index = [];
    $failed = [];
    foreach ($media as $rel => $file) {
        $path = (string) ($file['path'] ?? '');
        if (!is_string($rel) || !preg_match('#^media/[A-Za-z0-9._-]{1,150}\.(?:jpg|jpeg|png|gif|webp|ico|pdf|txt|csv|zip|doc|docx|xls|xlsx|ppt|pptx)$#i', $rel)
            || $path === '' || !is_file($path) || !is_readable($path)) {
            $failed[] = (string) ($file['name'] ?? $rel);
            continue;
        }
        if (!$zip->addFile($path, $rel)) {
            $failed[] = (string) ($file['name'] ?? $rel);
            continue;
        }
        $size = (int) filesize($path);
        $media_index[$rel] = [
            'name' => (string) ($file['name'] ?? wp_basename($path)),
            'mime' => (string) ($file['mime'] ?? 'application/octet-stream'),
            'size' => $size,
        ];
    }
    if ($failed !== []) {
        $zip->close();
        @unlink((string) $job['zip_path']);
        return [
            'ok' => false,
            'error' => 'Sebagian media gagal ditambahkan ke ZIP: ' . implode(', ', array_slice($failed, 0, 5))
                . (count($failed) > 5 ? ' (+' . (count($failed) - 5) . ' lainnya)' : ''),
        ];
    }

    $manifest_path = wp_tempnam('ringan-export-' . $token . '-manifest-');
    $handle = @fopen($manifest_path, 'wb');
    if ($handle === false) {
        $zip->close();
        @unlink((string) $job['zip_path']);
        return ['ok' => false, 'error' => 'Gagal menulis manifest ekspor.'];
    }

    $head = [
        'format' => 'ringan-export',
        'format_version' => 1,
        'generator' => 'Ringan Export ' . RINGAN_EXPORT_VERSION . ' (WordPress ' . get_bloginfo('version') . ')',
        'exported_at' => gmdate('c'),
        'source' => [
            'site_url' => home_url('/'),
            'site_name' => (string) get_bloginfo('name'),
            'post_type' => (string) ($profile['source']['post_type'] ?? ($job['post_types'][0] ?? 'post')),
            'post_types' => array_values((array) ($job['post_types'] ?? [])),
            'post_statuses' => array_values((array) ($job['post_statuses'] ?? [])),
        ],
        'profile' => $profile,
    ];
    $head_json = wp_json_encode($head, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($head_json) || !str_ends_with($head_json, '}')) {
        fclose($handle);
        @unlink($manifest_path);
        $zip->close();
        @unlink((string) $job['zip_path']);
        return ['ok' => false, 'error' => 'Gagal menyusun manifest ekspor.'];
    }
    fwrite($handle, substr($head_json, 0, -1) . ',"entries":[');

    $entries_handle = @fopen((string) $job['entries_path'], 'rb');
    if ($entries_handle === false) {
        fclose($handle);
        @unlink($manifest_path);
        $zip->close();
        @unlink((string) $job['zip_path']);
        return ['ok' => false, 'error' => 'File entry sementara tidak ditemukan.'];
    }
    $first = true;
    $entries_written = 0;
    while (($line = fgets($entries_handle)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (!$first) {
            fwrite($handle, ',');
        }
        fwrite($handle, $line);
        $first = false;
        $entries_written++;
    }
    fclose($entries_handle);

    fwrite($handle, '],"media":' . wp_json_encode($media_index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '}');
    fclose($handle);

    if ($entries_written === 0) {
        @unlink($manifest_path);
        $zip->close();
        @unlink((string) $job['zip_path']);
        return ['ok' => false, 'error' => 'Tidak ada entry yang berhasil diekspor.'];
    }

    if (!$zip->addFile($manifest_path, 'manifest.json')) {
        @unlink($manifest_path);
        $zip->close();
        @unlink((string) $job['zip_path']);
        return ['ok' => false, 'error' => 'Gagal menambahkan manifest.json ke ZIP.'];
    }
    $zip->close();
    @unlink($manifest_path);
    @unlink((string) $job['entries_path']);

    clearstatcache(true, (string) $job['zip_path']);
    $size = is_file((string) $job['zip_path']) ? (int) filesize((string) $job['zip_path']) : 0;
    if ($size <= 0) {
        @unlink((string) $job['zip_path']);
        return ['ok' => false, 'error' => 'ZIP hasil ekspor kosong atau gagal dibuat.'];
    }

    // Verifikasi arsip dapat dibuka kembali dan semua media benar-benar tertulis.
    $verify = new ZipArchive();
    if ($verify->open((string) $job['zip_path']) !== true) {
        @unlink((string) $job['zip_path']);
        return ['ok' => false, 'error' => 'ZIP hasil ekspor tidak dapat dibuka kembali.'];
    }
    $missing = [];
    if ($verify->locateName('manifest.json') === false) {
        $missing[] = 'manifest.json';
    }
    foreach ($media_index as $rel => $meta) {
        $stat = $verify->statName((string) $rel);
        if (!is_array($stat) || (int) $stat['size'] !== (int) $meta['size']) {
            $missing[] = (string) $rel;
        }
    }
    $verify->close();
    if ($missing !== []) {
        // Jangan biarkan ZIP tanpa media: entry akan menunjuk file yang tidak ada.
        @unlink((string) $job['zip_path']);
        return [
            'ok' => false,
            'error' => 'ZIP tidak lengkap, sebagian media gagal ditulis: ' . implode(', ', array_slice($missing, 0, 5))
                . (count($missing) > 5 ? ' (+' . (count($missing) - 5) . ' lainnya)' : ''),
        ];
    }

    $stats = (array) ($job['stats'] ?? []);
    $stats['media'] = count($media_index);
    $stats['warnings'] = array_slice(array_values(array_unique($warnings)), 0, 100);
    $job['stats'] = $stats;
    $job['finished'] = true;
    $job['finished_at'] = time();
    $job['zip_size'] = $size;
    ringan_export_job_save($token, $job);

    $download_url = ringan_export_download_url($token);

    return ['ok' => true, 'download_url' => $download_url, 'size' => $size, 'stats' => $stats];
}

/**
 * Unduh ZIP hasil ekspor lalu bersihkan file sementara.
 */
function ringan_export_handle_download(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Anda tidak memiliki akses.', 'ringan-export'), 403);
    }
    $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash((string) $_GET['token'])) : '';
    check_admin_referer('ringan_export_download_' . $token);

    $job = ringan_export_job_get($token);
    if ($job === null || (int) ($job['user_id'] ?? 0) !== get_current_user_id() || empty($job['finished'])) {
        wp_die(esc_html__('Sesi ekspor tidak ditemukan atau sudah kedaluwarsa.', 'ringan-export'), 404);
    }
    $zip_path = (string) ($job['zip_path'] ?? '');
    if ($zip_path === '' || !is_file($zip_path) || !is_readable($zip_path)) {
        wp_die(esc_html__('File ZIP sudah tidak tersedia. Silakan ekspor ulang.', 'ringan-export'), 404);
    }

    $slug = (string) ($job['profile']['content_type']['slug'] ?? 'export');
    $slug = preg_replace('/[^a-z0-9_-]/', '-', strtolower($slug)) ?: 'export';
    $filename = 'ringan-export-' . $slug . '-' . gmdate('Ymd-His') . '.zip';

    nocache_headers();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string) filesize($zip_path));
    header('X-Content-Type-Options: nosniff');

    @set_time_limit(0);
    readfile($zip_path);
    ringan_export_job_delete($token, $job);
    exit;
}

/**
 * Batalkan job dan hapus file sementara.
 */
function ringan_export_cancel(string $token, int $user_id): bool
{
    $job = ringan_export_job_get($token);
    if ($job === null || (int) ($job['user_id'] ?? 0) !== $user_id) {
        return false;
    }
    ringan_export_job_delete($token, $job);
    return true;
}