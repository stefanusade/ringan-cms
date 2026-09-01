<?php
/**
 * Hapus entri (POST + CSRF, anti-IDOR).
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
require_once APP_ROOT . '/includes/content_entries.php';
require_once APP_ROOT . '/includes/audit_log.php';

$user = require_login();
if (!can_write_entries($user)) {
    http_response_code(403);
    exit('403 — Anda tidak memiliki akses untuk menulis entri.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_admin('entries');
}
require_csrf();

$id = (int) ($_POST['id'] ?? 0);
$ct_id = (int) ($_POST['content_type'] ?? 0);

$entry = get_entry_for_content_type($id, $ct_id);
if ($entry === null) {
    flash_set('error', 'Entri tidak ditemukan.');
    redirect_admin('entries?content_type=' . $ct_id);
}

delete_entry($id);
log_audit('delete_entry', 'content_entries', (string) $id, (int) $user['id']);
flash_set('success', 'Entri #' . $id . ' dihapus.');
redirect_admin('entries?content_type=' . $ct_id);
