<?php
/**
 * Simpan Quick Edit dari tabel entri.
 *
 * Hanya field teks single line (text), tanggal (date), dan taksonomi yang
 * diproses; field lain serta status entri tidak berubah. Dipanggil dari form
 * yang di-expand per baris di admin/entries/index.php.
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
require_once APP_ROOT . '/includes/taxonomies.php';
require_once APP_ROOT . '/includes/audit_log.php';
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
if (!in_array($return_status, entry_filter_statuses(), true) || $return_status === 'trash') {
    $return_status = '';
}
$return_page = max(1, (int) ($_POST['return_page'] ?? 1));
$redirect_back = 'entries?content_type=' . $ct_id
    . ($return_status !== '' ? '&status=' . $return_status : '')
    . '&page=' . $return_page;

$id = (int) ($_POST['id'] ?? 0);
// Anti-IDOR: entri harus benar-benar milik content type yang diminta.
$entry = get_entry_for_content_type($id, $ct_id);
if ($entry === null) {
    flash_set('error', 'Entri tidak ditemukan pada content type ini.');
    redirect_admin($redirect_back);
}
if (entry_is_trashed($entry)) {
    flash_set('error', 'Entri #' . $id . ' ada di Trash. Pulihkan terlebih dahulu sebelum mengedit.');
    redirect_admin($redirect_back);
}

$fields = get_fields($ct_id);
$taxonomies = get_taxonomies($ct_id);
$quick_fields = entry_quick_edit_fields($fields);
if ($quick_fields === [] && $taxonomies === []) {
    flash_set('error', 'Content type ini tidak memiliki field teks, tanggal, atau taksonomi untuk Quick Edit.');
    redirect_admin($redirect_back);
}

// Whitelist dilakukan di update_entry_quick_fields() — key di luar text/date diabaikan.
$payload = is_array($_POST['data'] ?? null) ? $_POST['data'] : [];
$result = update_entry_quick_fields($entry, $fields, $payload);
if (!$result['ok']) {
    $messages = [];
    foreach ($result['errors'] as $field_errors) {
        foreach ((array) $field_errors as $message) {
            $messages[] = $message;
        }
    }
    flash_set('error', implode(' ', $messages));
    redirect_admin($redirect_back . '#entry-' . $id);
}

// Taksonomi: form selalu mengirim terms_present=1, sehingga "semua tidak dicentang"
// berarti mengosongkan term entri ini (sama seperti form edit penuh).
if (!empty($_POST['terms_present'])) {
    $posted_terms = is_array($_POST['terms'] ?? null) ? $_POST['terms'] : [];
    $term_ids = [];
    foreach ($taxonomies as $tax) {
        $tax_id = (int) $tax['id'];
        $ids = isset($posted_terms[$tax_id]) && is_array($posted_terms[$tax_id]) ? $posted_terms[$tax_id] : [];
        $term_ids = array_merge($term_ids, resolve_term_ids($ids, $tax_id));
    }
    set_entry_terms($id, $term_ids);
}

log_audit('quick_edit_entry', 'content_entries', (string) $id, (int) $user['id']);

if ((string) $entry['status'] === 'published') {
    webhook_queue_event('entry.updated', $ct, $id, $user, ['status' => 'published', 'via' => 'admin-quick-edit']);
}

flash_set('success', 'Entri #' . $id . ' diperbarui (quick edit).');
redirect_admin($redirect_back . '#entry-' . $id);
