<?php
/**
 * Profil ekspor Ringan CMS: parsing, validasi, dan pengambilan dari CMS.
 *
 * Struktur profil (format_version 1):
 * {
 *   "format": "ringan-export-profile",
 *   "format_version": 1,
 *   "content_type": { "slug": "artikel", "label": "Artikel" },
 *   "source": {
 *     "post_type": "post",
 *     "post_types": ["post"],
 *     "post_statuses": ["publish", "draft"],
 *     "taxonomies": [ { "wp_taxonomy": "category", "ringan_taxonomy": "kategori" } ]
 *   },
 *   "options": {
 *     "status_map": { "publish": "published", "draft": "draft" },
 *     "default_status": "draft",
 *     "include_media": true,
 *     "include_content_media": true,
 *     "media_max_bytes": 10485760
 *   },
 *   "fields": [
 *     { "key": "judul", "type": "text", "source": "post.title", "transform": "auto", "options": {} }
 *   ]
 * }
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const RINGAN_EXPORT_PROFILE_FORMAT = 'ringan-export-profile';
const RINGAN_EXPORT_PROFILE_VERSION = 1;

const RINGAN_EXPORT_FIELD_TYPES = [
    'text', 'textarea', 'richtext', 'number', 'boolean', 'date',
    'image', 'file', 'gallery', 'select', 'relation',
];

const RINGAN_EXPORT_TRANSFORMS = [
    'auto', 'text', 'html', 'date', 'number', 'boolean',
    'select', 'media', 'gallery', 'relation', 'raw',
];

const RINGAN_EXPORT_POST_SOURCES = [
    'post.title', 'post.content', 'post.excerpt', 'post.slug', 'post.date',
    'post.modified', 'post.status', 'post.author', 'post.thumbnail',
];

const RINGAN_EXPORT_CONTENT_STATUSES = ['draft', 'published', 'archived'];

/**
 * @return array{ok:bool,errors:string[],profile:array}
 */
function ringan_export_profile_normalize(array $raw): array
{
    $errors = [];

    if ((string) ($raw['format'] ?? '') !== RINGAN_EXPORT_PROFILE_FORMAT) {
        $errors[] = 'Format profil harus "' . RINGAN_EXPORT_PROFILE_FORMAT . '".';
    }
    $version = (int) ($raw['format_version'] ?? 0);
    if ($version < 1 || $version > RINGAN_EXPORT_PROFILE_VERSION) {
        $errors[] = 'Versi profil tidak didukung (maks ' . RINGAN_EXPORT_PROFILE_VERSION . ').';
    }

    $content_type = is_array($raw['content_type'] ?? null) ? $raw['content_type'] : [];
    $slug = strtolower(trim((string) ($content_type['slug'] ?? '')));
    if (!preg_match('/^[a-z0-9_-]{1,100}$/', $slug)) {
        $errors[] = 'Profil tidak memuat content_type.slug yang valid.';
    }

    $source_in = is_array($raw['source'] ?? null) ? $raw['source'] : [];
    $post_types = [];
    foreach ((array) ($source_in['post_types'] ?? []) as $candidate) {
        $candidate = strtolower(trim((string) $candidate));
        if (preg_match('/^[a-z0-9_-]{1,50}$/', $candidate) && !in_array($candidate, $post_types, true)) {
            $post_types[] = $candidate;
        }
    }
    $primary = strtolower(trim((string) ($source_in['post_type'] ?? '')));
    if (preg_match('/^[a-z0-9_-]{1,50}$/', $primary) && !in_array($primary, $post_types, true)) {
        array_unshift($post_types, $primary);
    }
    if ($post_types === []) {
        $fallback = post_type_exists('post') ? 'post' : (string) (array_key_first(get_post_types(['public' => true])) ?: 'post');
        $post_types = [$fallback];
    }

    $post_statuses = [];
    foreach ((array) ($source_in['post_statuses'] ?? []) as $status) {
        $status = strtolower(trim((string) $status));
        if (preg_match('/^[a-z0-9_-]{1,50}$/', $status) && !in_array($status, $post_statuses, true)) {
            $post_statuses[] = $status;
        }
    }
    if ($post_statuses === []) {
        $post_statuses = ['publish'];
    }

    $taxonomies = [];
    foreach ((array) ($source_in['taxonomies'] ?? []) as $pair) {
        if (!is_array($pair)) {
            continue;
        }
        $wp_tax = strtolower(trim((string) ($pair['wp_taxonomy'] ?? '')));
        $ringan_tax = strtolower(trim((string) ($pair['ringan_taxonomy'] ?? '')));
        if (!preg_match('/^[a-z0-9_-]{1,100}$/', $wp_tax) || !preg_match('/^[a-z0-9_-]{1,100}$/', $ringan_tax)) {
            continue;
        }
        $taxonomies[$wp_tax . '|' . $ringan_tax] = [
            'wp_taxonomy' => $wp_tax,
            'ringan_taxonomy' => $ringan_tax,
        ];
    }

    $options_in = is_array($raw['options'] ?? null) ? $raw['options'] : [];
    $status_map = [];
    foreach ((array) ($options_in['status_map'] ?? []) as $wp_status => $cms_status) {
        $wp_status = strtolower(trim((string) $wp_status));
        $cms_status = strtolower(trim((string) $cms_status));
        if ($wp_status !== '' && in_array($cms_status, RINGAN_EXPORT_CONTENT_STATUSES, true)) {
            $status_map[$wp_status] = $cms_status;
        }
    }
    if ($status_map === []) {
        $status_map = [
            'publish' => 'published',
            'draft' => 'draft',
            'private' => 'draft',
            'future' => 'draft',
            'pending' => 'draft',
        ];
    }
    $default_status = strtolower(trim((string) ($options_in['default_status'] ?? 'draft')));
    if (!in_array($default_status, RINGAN_EXPORT_CONTENT_STATUSES, true)) {
        $default_status = 'draft';
    }
    $media_max_bytes = (int) ($options_in['media_max_bytes'] ?? 10485760);
    $media_max_bytes = max(1048576, min(20 * 1048576, $media_max_bytes));

    $fields = [];
    $seen = [];
    foreach ((array) ($raw['fields'] ?? []) as $field) {
        if (!is_array($field)) {
            continue;
        }
        $key = trim((string) ($field['key'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/', $key)) {
            $errors[] = 'Field dengan key tidak valid: ' . $key;
            continue;
        }
        if (isset($seen[$key])) {
            $errors[] = 'Field duplikat pada profil: ' . $key;
            continue;
        }
        $seen[$key] = true;

        $type = strtolower(trim((string) ($field['type'] ?? '')));
        if (!in_array($type, RINGAN_EXPORT_FIELD_TYPES, true)) {
            $errors[] = sprintf('Tipe field "%s" tidak dikenal: %s', $key, $type);
            continue;
        }

        $source = trim((string) ($field['source'] ?? 'none'));
        if (str_starts_with($source, 'meta.')) {
            $source = 'acf.' . substr($source, 5);
        }
        if ($source !== '' && $source !== 'none'
            && !in_array($source, RINGAN_EXPORT_POST_SOURCES, true)
            && !preg_match('/^acf\.[A-Za-z0-9_-]{1,100}$/', $source)) {
            $errors[] = sprintf('Sumber field "%s" tidak valid: %s', $key, $source);
            continue;
        }
        if ($source === '') {
            $source = 'none';
        }

        $transform = strtolower(trim((string) ($field['transform'] ?? 'auto')));
        if (!in_array($transform, RINGAN_EXPORT_TRANSFORMS, true)) {
            $errors[] = sprintf('Transform field "%s" tidak valid: %s', $key, $transform);
            continue;
        }

        $fields[] = [
            'key' => $key,
            'label' => (string) ($field['label'] ?? $key),
            'type' => $type,
            'required' => !empty($field['required']),
            'source' => $source,
            'transform' => $transform,
            'options' => is_array($field['options'] ?? null) ? $field['options'] : [],
        ];
    }

    if ($fields === [] && $errors === []) {
        $errors[] = 'Profil tidak memuat field apa pun.';
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'profile' => [
            'format' => RINGAN_EXPORT_PROFILE_FORMAT,
            'format_version' => RINGAN_EXPORT_PROFILE_VERSION,
            'generator' => (string) ($raw['generator'] ?? 'Ringan CMS'),
            'generated_at' => (string) ($raw['generated_at'] ?? ''),
            'content_type' => [
                'slug' => $slug,
                'label' => (string) ($content_type['label'] ?? $slug),
            ],
            'source' => [
                'post_type' => $post_types[0],
                'post_types' => $post_types,
                'post_statuses' => $post_statuses,
                'taxonomies' => array_values($taxonomies),
            ],
            'options' => [
                'status_map' => $status_map,
                'default_status' => $default_status,
                'include_media' => array_key_exists('include_media', $options_in)
                    ? !empty($options_in['include_media'])
                    : true,
                'include_content_media' => array_key_exists('include_content_media', $options_in)
                    ? !empty($options_in['include_content_media'])
                    : true,
                'media_max_bytes' => $media_max_bytes,
            ],
            'fields' => $fields,
        ],
    ];
}

/**
 * @return array{ok:bool,errors:string[],profile:array}
 */
function ringan_export_profile_parse(string $json): array
{
    $json = trim($json);
    if ($json === '') {
        return ['ok' => false, 'errors' => ['JSON profil masih kosong.'], 'profile' => []];
    }
    if (strlen($json) > 524288) {
        return ['ok' => false, 'errors' => ['JSON profil terlalu besar (maks 512 KB).'], 'profile' => []];
    }
    $decoded = json_decode($json, true, 64);
    if (!is_array($decoded)) {
        return ['ok' => false, 'errors' => ['JSON tidak valid: ' . json_last_error_msg()], 'profile' => []];
    }
    return ringan_export_profile_normalize($decoded);
}

/**
 * Ambil profil dari endpoint /api/v1/{slug}/profile di Ringan CMS.
 *
 * @return array{ok:bool,errors:string[],profile:array}
 */
function ringan_export_profile_fetch(string $url, string $api_key = ''): array
{
    $url = trim($url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return ['ok' => false, 'errors' => ['URL profil harus diawali http:// atau https://'], 'profile' => []];
    }

    $args = [
        'timeout' => 20,
        'redirection' => 2,
        'reject_unsafe_urls' => true,
        'headers' => ['Accept' => 'application/json'],
        'user-agent' => 'RinganExport/' . RINGAN_EXPORT_VERSION . '; ' . home_url('/'),
    ];
    $api_key = trim($api_key);
    if ($api_key !== '') {
        $args['headers']['X-API-Key'] = $api_key;
    }

    $response = wp_remote_get($url, $args);
    if (is_wp_error($response)) {
        return ['ok' => false, 'errors' => ['Gagal menghubungi CMS: ' . $response->get_error_message()], 'profile' => []];
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    if ($code !== 200) {
        $detail = '';
        $decoded_error = json_decode($body, true);
        if (is_array($decoded_error) && isset($decoded_error['error']['message'])) {
            $detail = ' — ' . (string) $decoded_error['error']['message'];
        }
        return [
            'ok' => false,
            'errors' => [sprintf('CMS mengembalikan HTTP %d%s', $code, $detail)],
            'profile' => [],
        ];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'errors' => ['Respons CMS bukan JSON yang valid.'], 'profile' => []];
    }
    // Endpoint CMS membungkus profil dalam { success, data }.
    $candidate = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;

    return ringan_export_profile_normalize($candidate);
}

/**
 * Peta status WordPress → status Ringan CMS.
 */
function ringan_export_map_status(array $profile, string $wp_status): string
{
    $map = (array) ($profile['options']['status_map'] ?? []);
    $mapped = (string) ($map[$wp_status] ?? ($profile['options']['default_status'] ?? 'draft'));
    return in_array($mapped, RINGAN_EXPORT_CONTENT_STATUSES, true) ? $mapped : 'draft';
}