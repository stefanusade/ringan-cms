<?php
/**
 * Aksi massal entri: trash (soft delete), restore, set status, hapus permanen.
 * Dipakai juga oleh tombol aksi per baris di daftar entri.
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
require_once APP_ROOT . '/includes/content_entries.php';
require_once APP_ROOT . '/includes/entries_bulk.php';
require_once APP_ROOT . '/includes/webhooks.php';

$user = require_login();
if (!can_write_entries($user)) {
    http_response_code(403);
    exit('403 — Anda tidak memiliki akses untuk mengubah entri.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_admin('entries');
}
require_csrf();

$ct_id = (int) ($_POST['content_type'] ?? 0);
$ct = get_content_type($ct_id);
if ($ct === null) {
    flash_set('error', 'Content type tidak ditemukan.');
    redirect_admin('entries');
}

$return_status = sanitize_text($_POST['return_status'] ?? '');
if (!in_array($return_status, entry_filter_statuses(), true)) {
    $return_status = '';
}
$return_page = max(1, (int) ($_POST['return_page'] ?? 1));
$redirect = 'entries?content_type=' . $ct_id
    . ($return_status !== '' ? '&status=' . $return_status : '')
    . '&page=' . $return_page;

$ids = $_POST['ids'] ?? [];
if (!is_array($ids)) {
    $ids = [];
}
$bulk_action = sanitize_text($_POST['bulk_action'] ?? '');

try {
    $result = bulk_entries_action(
        $ct_id,
        $ids,
        $bulk_action,
        get_fields($ct_id),
        (int) $user['id']
    );
} catch (InvalidArgumentException | RuntimeException $e) {
    flash_set('error', $e->getMessage());
    redirect_admin('entries?content_type=' . $ct_id);
}

if ($result['done'] === 0 && $result['skipped'] === 0) {
    flash_set('error', 'Pilih minimal satu entri.');
    redirect_admin($redirect);
}

$message = $result['done'] . ' entri diproses.';
if ($result['done'] > 0 && $bulk_action !== 'restore') {
    webhook_queue_event('entries.bulk', $ct, null, $user, [
        'action' => $bulk_action,
        'count' => (int) $result['done'],
        'ids' => array_slice($result['changed'], 0, 100),
    ]);
}
if ($result['skipped'] > 0) {
    $message .= ' ' . $result['skipped'] . ' dilewati.';
}
flash_set($result['skipped'] > 0 ? 'warning' : 'success', $message);
if ($result['notes'] !== []) {
    flash_set('info', implode(' ', array_slice($result['notes'], 0, 3)));
}

redirect_admin($redirect);
