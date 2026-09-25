<?php
/**
 * Endpoint AJAX (admin) untuk mengunggah gambar yang disisipkan ke field
 * richtext — mis. hasil copy-paste / screenshot.
 *
 * Dilindungi: wajib login + hak tulis entri + token CSRF. Validasi MIME asli
 * dilakukan oleh handle_upload() (finfo_file, whitelist ekstensi, nama acak,
 * disimpan di storage/uploads di luar webroot).
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/permissions.php';
require_once APP_ROOT . '/includes/response.php';
require_once APP_ROOT . '/includes/upload.php';

// Endpoint AJAX: selalu balas JSON (tidak redirect ke halaman login).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('method_not_allowed', 'Metode tidak diizinkan.', 405);
}

$user = current_user();
if ($user === null) {
    json_error('unauthenticated', 'Silakan login terlebih dahulu.', 401);
}
if (!can_write_entries($user)) {
    json_error('forbidden', 'Anda tidak memiliki akses untuk mengunggah media.', 403);
}

if (!verify_csrf()) {
    json_error('csrf_invalid', 'CSRF token tidak valid. Muat ulang halaman.', 419);
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    json_error('no_file', 'Tidak ada file yang dikirim.', 400);
}

$upload = handle_upload($_FILES['file'], true, (int) $user['id']);
if (!$upload['ok']) {
    json_error('upload_failed', $upload['error'], 400);
}

json_response([
    'success' => true,
    'data' => [
        'path' => $upload['path'],
        // URL /files/ tetap dipakai meski file di-offload (route akan mengalihkan
        // ke media_public_base bila salinan lokal tidak ada).
        'url' => BASE_URL . '/files/' . ltrim($upload['path'], '/'),
    ],
]);
