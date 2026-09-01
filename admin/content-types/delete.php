<?php
/**
 * Hapus content type (superadmin only, POST + CSRF).
 * Field & entri ikut terhapus via ON DELETE CASCADE.
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
require_once APP_ROOT . '/includes/content_types.php';
require_once APP_ROOT . '/includes/audit_log.php';

$user = require_role('superadmin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_admin('content-types');
}
require_csrf();

$id = (int) ($_POST['id'] ?? 0);
$ct = get_content_type($id);
if ($ct === null) {
    flash_set('error', 'Content type tidak ditemukan.');
    redirect_admin('content-types');
}

delete_content_type($id);
log_audit('delete_content_type', 'content_types', (string) $id, (int) $user['id']);
flash_set('success', 'Content type "' . $ct['label'] . '" beserta semua field dan entri-nya telah dihapus.');
redirect_admin('content-types');
