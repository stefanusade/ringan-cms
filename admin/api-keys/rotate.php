<?php
/**
 * Rotate API key: buat secret baru, hash lama langsung tidak berlaku.
 * Secret baru hanya disimpan sementara di session untuk ditampilkan sekali.
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__, 2));
}
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/permissions.php';
require_once APP_ROOT . '/includes/response.php';
require_once APP_ROOT . '/includes/audit_log.php';

$user = require_role('superadmin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_admin('api-keys');
}
require_csrf();

$id = (int) ($_POST['id'] ?? 0);
$stmt = get_db()->prepare('SELECT id, label FROM api_keys WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$key = $stmt->fetch();
if ($key === null) {
    flash_set('error', 'API key tidak ditemukan.');
    redirect_admin('api-keys');
}

$plain = 'rcm_' . bin2hex(random_bytes(24));
$hash = hash('sha256', $plain);

$stmt = get_db()->prepare('UPDATE api_keys SET key_hash = ?, is_active = 1 WHERE id = ?');
$stmt->execute([$hash, $id]);
log_audit('rotate_api_key', 'api_keys', (string) $id, (int) $user['id']);

$_SESSION['api_key_plain'] = [
    'label' => (string) $key['label'],
    'key' => $plain,
];

flash_set('success', 'API key "' . $key['label'] . '" berhasil dirotasi dan diaktifkan. Salin secret baru sekarang.');
redirect_admin('api-keys');
