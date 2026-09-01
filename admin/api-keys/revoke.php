<?php
/**
 * Nonaktifkan API key (POST + CSRF).
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
$stmt = get_db()->prepare('SELECT label FROM api_keys WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$key = $stmt->fetch();
if ($key === null) {
    flash_set('error', 'API key tidak ditemukan.');
    redirect_admin('api-keys');
}

$stmt = get_db()->prepare('UPDATE api_keys SET is_active = 0 WHERE id = ?');
$stmt->execute([$id]);
log_audit('revoke_api_key', 'api_keys', (string) $id, (int) $user['id']);
flash_set('success', 'API key "' . $key['label'] . '" dinonaktifkan.');
redirect_admin('api-keys');
