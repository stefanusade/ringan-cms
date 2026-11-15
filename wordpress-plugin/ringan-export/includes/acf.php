<?php
/**
 * Integrasi ACF: penemuan field dan pembacaan nilai.
 *
 * Plugin tetap berfungsi tanpa ACF (sumber post.* saja). Bila ACF aktif,
 * field dari field group yang terpasang pada post type terkait ditampilkan
 * sebagai saran pemetaan di halaman admin.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function ringan_export_acf_active(): bool
{
    return function_exists('get_field') && function_exists('acf_get_field_groups');
}

/**
 * Daftar field ACF untuk post type tertentu.
 *
 * @return array<string,array{name:string,label:string,type:string,post_types:string[]}>
 */
function ringan_export_acf_fields(array $post_types = []): array
{
    if (!ringan_export_acf_active()) {
        return [];
    }

    $groups = [];
    foreach ($post_types as $post_type) {
        $found = acf_get_field_groups(['post_type' => $post_type]);
        if (is_array($found)) {
            foreach ($found as $group) {
                if (isset($group['ID'])) {
                    $groups[(string) $group['ID']] = $group;
                }
            }
        }
    }
    if ($groups === [] && $post_types === []) {
        $found = acf_get_field_groups();
        if (is_array($found)) {
            foreach ($found as $group) {
                if (isset($group['ID'])) {
                    $groups[(string) $group['ID']] = $group;
                }
            }
        }
    }

    $fields = [];
    foreach ($groups as $group) {
        $group_fields = acf_get_fields($group);
        if (!is_array($group_fields)) {
            continue;
        }
        foreach ($group_fields as $field) {
            if (!is_array($field) || empty($field['name'])) {
                continue;
            }
            $name = (string) $field['name'];
            if (isset($fields[$name])) {
                continue;
            }
            $fields[$name] = [
                'name' => $name,
                'label' => (string) ($field['label'] ?? $name),
                'type' => (string) ($field['type'] ?? ''),
                'post_types' => $post_types,
            ];
        }
    }

    ksort($fields);
    return $fields;
}

/**
 * Baca nilai field ACF (fallback ke post meta bila ACF tidak aktif).
 */
function ringan_export_acf_value(string $key, int $post_id): mixed
{
    if (ringan_export_acf_active()) {
        $value = get_field($key, $post_id);
        if ($value !== null && $value !== false) {
            return $value;
        }
    }
    return get_post_meta($post_id, $key, true);
}

/**
 * Ambil attachment ID dari nilai ACF image/file (ID, array, atau URL).
 */
function ringan_export_attachment_id(mixed $value): int
{
    if (is_numeric($value)) {
        return (int) $value;
    }
    if (is_array($value)) {
        foreach (['ID', 'id', 'attachment_id'] as $key) {
            if (isset($value[$key]) && is_numeric($value[$key])) {
                return (int) $value[$key];
            }
        }
        if (isset($value['url']) && is_string($value['url'])) {
            return ringan_export_url_to_attachment_id($value['url']);
        }
        return 0;
    }
    if (is_string($value) && preg_match('#^https?://#i', $value)) {
        return ringan_export_url_to_attachment_id($value);
    }
    return 0;
}

function ringan_export_url_to_attachment_id(string $url): int
{
    $url = trim($url);
    if ($url === '') {
        return 0;
    }
    if (function_exists('attachment_url_to_postid')) {
        $id = (int) attachment_url_to_postid($url);
        if ($id > 0) {
            return $id;
        }
    }
    return 0;
}

/**
 * Kumpulkan ID attachment/post dari nilai apa pun (gallery, relationship, repeater).
 *
 * @return int[]
 */
function ringan_export_collect_ids(mixed $value, int $depth = 0): array
{
    if ($depth > 5) {
        return [];
    }
    $ids = [];
    if ($value instanceof WP_Post) {
        return [(int) $value->ID];
    }
    if (is_numeric($value)) {
        $id = (int) $value;
        return $id > 0 ? [$id] : [];
    }
    if (is_array($value)) {
        if (isset($value['ID']) && is_numeric($value['ID'])) {
            return [(int) $value['ID']];
        }
        foreach ($value as $item) {
            foreach (ringan_export_collect_ids($item, $depth + 1) as $id) {
                $ids[] = $id;
            }
        }
    }
    return array_values(array_unique($ids));
}