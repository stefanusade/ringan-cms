<?php
/**
 * Validasi & sanitasi input generik.
 */

declare(strict_types=1);

/**
 * Escape output untuk HTML (XSS protection).
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Normalisasi teks biasa: trim, buang tag & control chars.
 */
function sanitize_text(mixed $value): string
{
    $value = trim((string) $value);
    $value = strip_tags($value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
    return $value;
}

/**
 * Sanitasi richtext: whitelist tag + buang atribut berbahaya (protokol & event handler).
 */
function sanitize_richtext(string $html): string
{
    $allowed = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li',
        'blockquote', 'pre', 'code', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'img',
        'table', 'thead', 'tbody', 'tr', 'th', 'td', 'figure', 'figcaption',
        'span', 'hr', 'div',
    ];
    $tags = implode('', array_map(fn(string $t): string => '<' . $t . '>', $allowed));
    $html = strip_tags($html, $tags);
    // hapus semua event handler (on*)
    $html = preg_replace('/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
    // hapus protokol berbahaya di href/src
    $html = preg_replace('/(href|src)\s*=\s*(["\'])\s*(?:javascript|vbscript|data|file)\s*:[^"\']*\2/i', '', $html) ?? $html;
    return trim($html);
}

function sanitize_boolean(mixed $value): ?bool
{
    if (is_bool($value)) {
        return $value;
    }
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value)) {
        return (int) $value !== 0;
    }
    return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on', 'ya'], true);
}

function validate_required(mixed $value): bool
{
    if ($value === null) {
        return false;
    }
    if (is_string($value)) {
        return trim($value) !== '';
    }
    if (is_array($value)) {
        return count($value) > 0;
    }
    return true;
}

function validate_email(string $value): bool
{
    return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
}

function validate_username(string $value): bool
{
    return (bool) preg_match('/^[a-zA-Z0-9_.-]{3,60}$/', $value);
}

function validate_slug(string $value): bool
{
    return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]{0,98}[a-z0-9])?$/', $value);
}

function validate_field_key(string $value): bool
{
    return (bool) preg_match('/^[a-z][a-z0-9_]{0,99}$/', $value);
}

function validate_in_enum(array $allowed, mixed $value): bool
{
    return in_array($value, $allowed, true);
}

function validate_max_length(string $value, int $max): bool
{
    return mb_strlen($value) <= $max;
}

function validate_number(mixed $value, ?float $min = null, ?float $max = null): bool
{
    if (!is_numeric($value)) {
        return false;
    }
    $num = (float) $value;
    if ($min !== null && $num < $min) {
        return false;
    }
    if ($max !== null && $num > $max) {
        return false;
    }
    return true;
}

function normalize_date(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    $value = (string) $value;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        [$y, $m, $d] = array_map('intval', explode('-', $value));
        return checkdate($m, $d, $y) ? $value : null;
    }
    $ts = strtotime($value);
    return $ts === false ? null : date('Y-m-d', $ts);
}

/**
 * Validasi nilai sebuah field terhadap definisinya.
 * Mengembalikan nilai yang sudah dibersihkan; jika gagal, push error ke $errors
 * dan mengembalikan null.
 */
function validate_field_value(array $field_def, mixed $raw, array &$errors): mixed
{
    $required = (bool) ($field_def['is_required'] ?? 0);
    $type = $field_def['field_type'] ?? 'text';
    $options = is_array($field_def['options'] ?? null) ? $field_def['options'] : [];
    $label = $field_def['label'] ?? $field_def['field_key'] ?? '';

    $empty = $raw === null
        || (is_string($raw) && trim($raw) === '')
        || (is_array($raw) && count($raw) === 0);

    if ($empty) {
        if ($required) {
            $errors[] = sprintf('Field "%s" wajib diisi.', $label);
        }
        return null;
    }

    switch ($type) {
        case 'text':
        case 'textarea':
            $value = sanitize_text($raw);
            $max = (int) ($options['max_length'] ?? ($type === 'text' ? 255 : 10000));
            if (!validate_max_length($value, $max)) {
                $errors[] = sprintf('Field "%s" maksimal %d karakter.', $label, $max);
                return null;
            }
            return $value;

        case 'richtext':
            return sanitize_richtext((string) $raw);

        case 'number':
            $min = isset($options['min']) ? (float) $options['min'] : null;
            $max = isset($options['max']) ? (float) $options['max'] : null;
            if (!validate_number($raw, $min, $max)) {
                $errors[] = sprintf('Field "%s" harus berupa angka yang valid.', $label);
                return null;
            }
            $value = $raw;
            if (is_string($value)) {
                $value = (float) $value;
                if (floor($value) === $value) {
                    $value = (int) $value;
                }
            }
            return $value;

        case 'boolean':
            return sanitize_boolean($raw);

        case 'date':
            $date = normalize_date($raw);
            if ($date === null) {
                $errors[] = sprintf('Field "%s" harus berformat tanggal YYYY-MM-DD.', $label);
                return null;
            }
            return $date;

        case 'select':
            $allowed_options = $options['options'] ?? [];
            if (!validate_in_enum($allowed_options, $raw)) {
                $errors[] = sprintf('Field "%s" berisi nilai yang tidak diizinkan.', $label);
                return null;
            }
            return $raw;

        case 'image':
        case 'file':
            // Nilai berupa path relatif (atau data URI yang diproses di layer API/admin).
            return sanitize_text((string) $raw);

        case 'relation':
            $relation_single = !empty($options['relation_single']);
            if ($relation_single) {
                $id = is_numeric($raw) ? (int) $raw : null;
                if ($id === null || $id <= 0) {
                    $errors[] = sprintf('Field "%s" harus berupa ID entry target.', $label);
                    return null;
                }
                return $id;
            }
            $ids = [];
            foreach ((array) $raw as $v) {
                if (is_numeric($v)) {
                    $ids[] = (int) $v;
                }
            }
            if (count($ids) === 0 && $required) {
                $errors[] = sprintf('Field "%s" wajib diisi.', $label);
                return null;
            }
            return array_values(array_unique($ids));

        case 'gallery':
            $items = [];
            foreach ((array) $raw as $v) {
                $v = sanitize_text($v);
                if ($v !== '') {
                    $items[] = $v;
                }
            }
            if (count($items) === 0 && $required) {
                $errors[] = sprintf('Field "%s" wajib diisi.', $label);
                return null;
            }
            return $items;

        default:
            return sanitize_text((string) $raw);
    }
}
