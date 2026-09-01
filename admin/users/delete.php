<?php
/**
 * Hapus user (superadmin only, POST + CSRF).
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
    redirect_admin('users');
}
require_csrf();

$id = (int) ($_POST['id'] ?? 0);
if ($id === (int) $user['id']) {
    flash_set('error', 'Anda tidak bisa menghapus akun sendiri.');
    redirect_admin('users');
}

$db = get_db();
$stmt = $db->prepare('SELECT username, role FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$target = $stmt->fetch();
if ($target === null) {
    flash_set('error', 'User tidak ditemukan.');
    redirect_admin('users');
}
if ($target['role'] === 'superadmin') {
    $cnt = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'superadmin'")->fetchColumn();
    if ($cnt <= 1) {
        flash_set('error', 'Tidak bisa menghapus satu-satunya superadmin.');
        redirect_admin('users');
    }
}

$stmt = $db->prepare('DELETE FROM users WHERE id = ?');
$stmt->execute([$id]);
log_audit('delete_user', 'users', (string) $id, (int) $user['id']);
flash_set('success', 'User dihapus.');
redirect_admin('users');
