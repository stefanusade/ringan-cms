<?php
/**
 * Router utama API v1. Dipanggil oleh front controller public/index.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

api_cors_headers();
api_ip_rate_limit();

$route = ltrim((string) ($GLOBALS['api_route_path'] ?? ''), '/');
$segments = array_values(array_filter(explode('/', $route), fn($s) => $s !== ''));
$slug = $segments[0] ?? '';
$id = null;

if ($slug === '') {
    json_error('not_found', 'Endpoint tidak ditemukan.', 404);
}

if ($slug === 'auth') {
    require_once __DIR__ . '/auth.php';
    exit;
}

if ($slug === 'site') {
    // Info situs publik (judul, tagline, deskripsi, favicon) — tanpa API key.
    require_once dirname(__DIR__, 2) . '/includes/settings.php';
    if (count($segments) > 1) {
        json_error('not_found', 'Endpoint tidak ditemukan.', 404);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        json_error('method_not_allowed', 'Method tidak diizinkan.', 405);
    }
    $settings = get_site_settings();
    json_response([
        'success' => true,
        'data' => [
            'title' => $settings['site_title'],
            'tagline' => $settings['site_tagline'],
            'description' => $settings['site_description'],
            'favicon_url' => $settings['site_favicon'] !== '' ? BASE_URL . '/files/' . ltrim($settings['site_favicon'], '/') : '',
            'site_url' => BASE_URL,
        ],
    ]);
}

$taxonomies_request = false;
if (isset($segments[1])) {
    if ($segments[1] === 'taxonomies' && count($segments) === 2) {
        $taxonomies_request = true;
    } elseif (!ctype_digit($segments[1])) {
        json_error('not_found', 'Endpoint tidak ditemukan.', 404);
    } else {
        $id = (int) $segments[1];
    }
}
if (count($segments) > 2) {
    json_error('not_found', 'Endpoint tidak ditemukan.', 404);
}

$auth = api_authenticate();
$GLOBALS['api_user_id'] = ($auth['type'] ?? '') === 'jwt' ? (int) $auth['user']['id'] : null;

$rate_key_id = ($auth['type'] ?? '') === 'api_key' ? (int) $auth['api_key']['id'] : null;
if (api_rate_limited($rate_key_id)) {
    json_error('rate_limited', 'Terlalu banyak permintaan. Silakan coba lagi nanti.', 429);
}
record_api_request($rate_key_id);

require_once __DIR__ . '/content-type.php';
