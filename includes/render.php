<?php
/**
 * Helper rendering HTML untuk form & nilai field (dipisah dari query DB).
 */

declare(strict_types=1);

require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/content_types.php';
require_once __DIR__ . '/content_entries.php';

function upload_url(string $relative_path): string
{
    return BASE_URL . '/files/' . ltrim($relative_path, '/');
}

function render_field_input(array $field, mixed $value = null): string
{
    $type = $field['field_type'] ?? 'text';
    $key = $field['field_key'];
    $options = decode_options($field['options_json'] ?? null);
    $id = 'field_' . $key;
    $name = 'data[' . $key . ']';
    $attr = 'name="' . e($name) . '" id="' . e($id) . '"';

    switch ($type) {
        case 'textarea':
        case 'richtext':
            $rows = $type === 'richtext' ? 8 : 5;
            $cls = $type === 'richtext' ? ' class="richtext"' : '';
            return '<textarea ' . $attr . $cls . ' rows="' . $rows . '">' . e((string) ($value ?? '')) . '</textarea>';

        case 'number':
            $minmax = '';
            if (isset($options['min'])) {
                $minmax .= ' min="' . e((string) $options['min']) . '"';
            }
            if (isset($options['max'])) {
                $minmax .= ' max="' . e((string) $options['max']) . '"';
            }
            return '<input type="number" step="any" ' . $attr . ' value="' . e((string) ($value ?? '')) . '"' . $minmax . '>';

        case 'boolean':
            return '<input type="hidden" name="' . e($name) . '" value="0">'
                . '<input type="checkbox" name="' . e($name) . '" value="1" id="' . e($id) . '"' . (!empty($value) ? ' checked' : '') . '>';

        case 'date':
            return '<input type="date" ' . $attr . ' value="' . e((string) ($value ?? '')) . '">';

        case 'select':
            $allowed = $options['options'] ?? [];
            $html = '<select ' . $attr . '><option value="">— Pilih —</option>';
            foreach ($allowed as $opt) {
                $selected = ((string) $value === (string) $opt) ? ' selected' : '';
                $html .= '<option value="' . e((string) $opt) . '"' . $selected . '>' . e((string) $opt) . '</option>';
            }
            return $html . '</select>';

        case 'relation':
            $target_slug = $options['relation_target'] ?? '';
            $target = $target_slug !== '' ? get_content_type_by_slug($target_slug) : null;
            $single = !empty($options['relation_single']);
            $entries = $target !== null ? get_entries((int) $target['id'], '', 1, 200)['items'] : [];
            $current = array_map('intval', (array) $value);
            if ($single) {
                $html = '<select ' . $attr . '><option value="">— Pilih —</option>';
                foreach ($entries as $ent) {
                    $selected = in_array((int) $ent['id'], $current, true) ? ' selected' : '';
                    $html .= '<option value="' . (int) $ent['id'] . '"' . $selected . '>' . e(relation_entry_label($target, $ent)) . '</option>';
                }
                return $html . '</select>';
            }
            $name_multi = 'data[' . $key . '][]';
            $attr_multi = 'name="' . e($name_multi) . '" id="' . e($id) . '"';
            $html = '<select ' . $attr_multi . ' multiple size="6">';
            foreach ($entries as $ent) {
                $selected = in_array((int) $ent['id'], $current, true) ? ' selected' : '';
                $html .= '<option value="' . (int) $ent['id'] . '"' . $selected . '>' . e(relation_entry_label($target, $ent)) . '</option>';
            }
            return $html . '</select>';

        case 'image':
        case 'file':
            return render_field_file_input($field, $value);

        case 'gallery':
            return render_field_gallery_input($field, $value);

        default:
            $max = (int) ($options['max_length'] ?? 255);
            return '<input type="text" ' . $attr . ' value="' . e((string) ($value ?? '')) . '" maxlength="' . $max . '">';
    }
}

function render_field_file_input(array $field, mixed $value = null): string
{
    $key = $field['field_key'];
    $is_image = ($field['field_type'] ?? '') === 'image';
    $name = 'data_file[' . $key . ']';
    $accept = $is_image ? 'accept="image/png,image/jpeg,image/gif,image/webp"' : '';
    $html = '<input type="file" name="' . e($name) . '" id="field_' . e($key) . '" ' . $accept . '>';
    if (is_string($value) && $value !== '') {
        $url = upload_url($value);
        if ($is_image) {
            $html .= '<div class="current-file"><img src="' . e($url) . '" alt="preview" loading="lazy"></div>';
        } else {
            $html .= '<div class="current-file"><a href="' . e($url) . '" target="_blank" rel="noopener">' . e(basename($value)) . '</a></div>';
        }
        $html .= '<label class="keep-file"><input type="checkbox" name="data_delete_file[' . e($key) . ']" value="1"> Hapus file saat ini</label>';
    }
    return $html;
}

function render_field_gallery_input(array $field, mixed $value = null): string
{
    $key = $field['field_key'];
    $html = '<input type="file" name="data_file[' . e($key) . '][]" id="field_' . e($key) . '" multiple accept="image/png,image/jpeg,image/gif,image/webp">';
    $items = is_array($value) ? $value : [];
    if ($items !== []) {
        $html .= '<div class="gallery-preview">';
        foreach ($items as $i => $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $html .= '<div class="gallery-item">'
                . '<img src="' . e(upload_url($path)) . '" alt="gambar ' . (int) $i . '" loading="lazy">'
                . '<label class="keep-file"><input type="checkbox" name="data_delete_gallery[' . e($key) . '][]" value="' . (int) $i . '"> Hapus</label>'
                . '</div>';
        }
        $html .= '</div>';
    }
    return $html;
}

function relation_entry_label(?array $target, array $entry): string
{
    $fields = $target !== null ? get_fields((int) $target['id']) : [];
    foreach ($fields as $f) {
        if (in_array($f['field_type'], ['text', 'textarea'], true)) {
            $data = decode_entry_data($entry);
            if (isset($data[$f['field_key']]) && trim((string) $data[$f['field_key']]) !== '') {
                return '#' . $entry['id'] . ' — ' . mb_substr((string) $data[$f['field_key']], 0, 60);
            }
        }
    }
    return '#' . $entry['id'];
}

function entry_preview(array $entry, array $fields, int $count = 2): string
{
    $data = decode_entry_data($entry);
    $parts = [];
    foreach ($fields as $f) {
        if (count($parts) >= $count) {
            break;
        }
        if (!in_array($f['field_type'], ['text', 'textarea', 'select', 'number'], true)) {
            continue;
        }
        $v = $data[$f['field_key']] ?? '';
        if (is_array($v)) {
            $v = implode(', ', array_map('strval', $v));
        }
        if (trim((string) $v) === '') {
            continue;
        }
        $parts[] = e(mb_substr((string) $v, 0, 60));
    }
    return $parts === [] ? '<span class="muted">—</span>' : implode(' — ', $parts);
}

function status_badge(string $status): string
{
    $class = in_array($status, ['draft', 'published', 'archived'], true) ? $status : '';
    return '<span class="badge ' . e($class) . '">' . e($status) . '</span>';
}

function role_badge(string $role): string
{
    $class = in_array($role, ['superadmin', 'editor', 'viewer'], true) ? $role : '';
    return '<span class="badge ' . e($class) . '">' . e($role) . '</span>';
}
