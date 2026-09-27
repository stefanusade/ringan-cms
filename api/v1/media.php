<?php
/**
 * Endpoint API: unggah media (file/gambar) untuk dipakai frontend.
 *
 * POST /api/v1/media  (multipart/form-data)
 *   - file  : wajib, satu file
 *   - kind  : opsional, "image" (khusus gambar) | "file" (default, whitelist umum)
 *
 * Auth: X-API-Key dengan scope read_write, atau Bearer JWT role editor/superadmin.
 * Auth & rate limit sudah dilakukan oleh api/v1/index.php ($auth & $GLOBALS['api_user_id']).
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/upload.php';
require_once dirname(__DIR__, 2) . '/includes/media.php';
require_once dirname(__DIR__, 2) . '/includes/audit_log.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('method_not_allowed', 'Method tidak diizinkan. Gunakan POST.', 405);
}

// Wajib punya hak tulis.
if (($auth['type'] ?? '') === 'jwt') {
    if (!in_array($auth['user']['role'] ?? '', ['superadmin', 'editor'], true)) {
        json_error('forbidden', 'Anda tidak memiliki akses unggah.', 403);
    }
} elseif (($auth['api_key']['scope'] ?? '') !== 'read_write') {
    json_error('forbidden', 'API key tidak memiliki scope read_write.', 403);
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    json_error('validation_error', 'Field "file" wajib diisi (multipart/form-data).', 400);
}

$kind = strtolower(trim((string) ($_POST['kind'] ?? 'file')));
if (!in_array($kind, ['image', 'file'], true)) {
    $kind = 'file';
}

$upload = handle_upload($_FILES['file'], $kind === 'image', $GLOBALS['api_user_id'] ?? null);
if (!$upload['ok']) {
    json_error('validation_error', 'Gagal mengunggah: ' . $upload['error'], 400);
}

$path = (string) $upload['path'];
log_audit('api_upload_media', 'media', null, $GLOBALS['api_user_id'] ?? null);

json_response([
    'success' => true,
    'data' => [
        'path' => $path,
        'url' => BASE_URL . '/files/' . ltrim($path, '/'),
        'mime' => (string) ($upload['mime'] ?? ''),
        'name' => basename((string) ($_FILES['file']['name'] ?? $path)),
        'size' => (int) ($_FILES['file']['size'] ?? 0),
    ],
], 201);
