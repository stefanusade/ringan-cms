<?php
/**
 * Front controller — SATU-SATUNYA entry point publik.
 * Pada deployment, webroot HANYA folder /public.
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_PUBLIC', __DIR__);

require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/includes/response.php';
require_once APP_ROOT . '/includes/settings.php';

send_security_headers();
apply_site_timezone();

$api_base = '/' . ltrim(get_api_path(), '/');

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$base_path = (string) parse_url(BASE_URL, PHP_URL_PATH);
if ($base_path !== '' && $base_path !== '/' && str_starts_with($path, $base_path)) {
    $path = substr($path, strlen($base_path));
}
$path = '/' . ltrim($path, '/');

/* ===== Aset statis & file upload ===== */
if (str_starts_with($path, '/admin/assets/')) {
    serve_static_file(APP_ROOT . '/admin/assets', substr($path, strlen('/admin/assets/')));
}
if (str_starts_with($path, '/files/')) {
    serve_upload_file(substr($path, strlen('/files/')));
}

$path = rtrim($path, '/');
if ($path === '') {
    $path = '/';
}

if ($path === '/') {
    redirect(BASE_URL . '/admin');
}

/* ===== REST API (prefix diatur di Settings → api_path) ===== */
if ($path === $api_base) {
    $GLOBALS['api_route_path'] = '';
    require APP_ROOT . '/api/v1/index.php';
}
if (str_starts_with($path, $api_base . '/')) {
    $GLOBALS['api_route_path'] = substr($path, strlen($api_base));
    require APP_ROOT . '/api/v1/index.php';
}

/* ===== Wizard instalasi ===== */
if ($path === '/install') {
    require APP_ROOT . '/install/index.php';
    exit;
}

/* ===== Admin (routing via front controller) ===== */
$admin_routes = [
    '/admin' => 'admin/index.php',
    '/admin/login' => 'admin/login.php',
    '/admin/logout' => 'admin/logout.php',
    '/admin/users' => 'admin/users/index.php',
    '/admin/users/create' => 'admin/users/create.php',
    '/admin/users/edit' => 'admin/users/edit.php',
    '/admin/users/delete' => 'admin/users/delete.php',
    '/admin/content-types' => 'admin/content-types/index.php',
    '/admin/content-types/create' => 'admin/content-types/create.php',
    '/admin/content-types/edit' => 'admin/content-types/edit.php',
    '/admin/content-types/fields' => 'admin/content-types/fields.php',
    '/admin/content-types/delete' => 'admin/content-types/delete.php',
    '/admin/content-types/taxonomies' => 'admin/content-types/taxonomies.php',
    '/admin/content-types/terms' => 'admin/content-types/terms.php',
    '/admin/entries' => 'admin/entries/index.php',
    '/admin/entries/create' => 'admin/entries/create.php',
    '/admin/entries/edit' => 'admin/entries/edit.php',
    '/admin/entries/delete' => 'admin/entries/delete.php',
    '/admin/api-keys' => 'admin/api-keys/index.php',
    '/admin/api-keys/create' => 'admin/api-keys/create.php',
    '/admin/api-keys/revoke' => 'admin/api-keys/revoke.php',
    '/admin/settings' => 'admin/settings/index.php',
    '/admin/settings/media' => 'admin/settings/media.php',
    '/admin/updates' => 'admin/updates/index.php',
];

if (isset($admin_routes[$path])) {
    $target = APP_ROOT . '/' . $admin_routes[$path];
    if (is_file($target)) {
        require $target;
        exit;
    }
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo '404 — Halaman tidak ditemukan.';

/**
 * Sajikan aset statis admin dengan validasi path & whitelist ekstensi.
 */
function serve_static_file(string $root, string $relative): void
{
    $relative = ltrim(str_replace('\\', '/', $relative), '/');
    if ($relative === '' || str_contains($relative, '..')) {
        http_response_code(404);
        exit;
    }
    $base = realpath($root);
    $file = realpath($root . '/' . $relative);
    if ($base === false || $file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
        http_response_code(404);
        exit;
    }
    $mimes = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'woff2' => 'font/woff2',
        'ico' => 'image/x-icon',
    ];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!isset($mimes[$ext])) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $mimes[$ext]);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=3600');
    readfile($file);
    exit;
}

/**
 * Sajikan file upload dari storage/uploads (di luar webroot) dengan
 * validasi path ketat & tanpa eksekusi script.
 */
function serve_upload_file(string $relative): void
{
    $relative = ltrim(str_replace('\\', '/', $relative), '/');
    if ($relative === '' || str_contains($relative, '..')) {
        http_response_code(404);
        exit;
    }
    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'ico', 'pdf', 'txt', 'csv', 'zip', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
    $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) {
        http_response_code(404);
        exit;
    }
    $base = realpath(UPLOAD_DIR);
    $file = realpath(UPLOAD_DIR . '/' . $relative);
    $local_ok = $base !== false && $file !== false
        && str_starts_with($file, $base . DIRECTORY_SEPARATOR)
        && is_file($file);
    if (!$local_ok) {
        // File lokal tidak ada — alihkan ke URL publik bila offload aktif.
        $public_base = trim((string) media_config('public_base', ''));
        if (media_offload_active() && $public_base !== '') {
            redirect(rtrim($public_base, '/') . '/' . ltrim($relative, '/'));
        }
        http_response_code(404);
        exit;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file);
    if ($mime === false) {
        $mime = 'application/octet-stream';
    }
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . basename($file) . '"');
    header('Cache-Control: public, max-age=86400');
    readfile($file);
    exit;
}
