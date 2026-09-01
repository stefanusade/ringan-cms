<?php
/**
 * Daftar entri per content type.
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__, 2));
}
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/permissions.php';
require_once APP_ROOT . '/includes/content_types.php';
require_once APP_ROOT . '/includes/content_entries.php';
require_once APP_ROOT . '/includes/render.php';
require_once APP_ROOT . '/admin/partials/header.php';
require_once APP_ROOT . '/admin/partials/footer.php';
require_once APP_ROOT . '/admin/partials/pagination.php';

$user = require_login();

$ct_id = (int) ($_GET['content_type'] ?? 0);
$ct = $ct_id > 0 ? get_content_type($ct_id) : null;
$can_write = can_write_entries($user);

admin_header('Entries', 'entries');

if ($ct === null) {
    $types = get_content_types();
    echo '<p class="muted">Pilih content type untuk melihat entri-nya:</p>';
    if ($types === []) {
        echo '<p class="muted">Belum ada content type.</p>';
    } else {
        echo '<div class="cards-grid">';
        foreach ($types as $t) {
            echo '<a class="card card-link" href="' . e(admin_url('entries?content_type=' . (int) $t['id'])) . '">'
                . '<strong>' . e($t['label']) . '</strong><br>'
                . '<span class="muted">' . (int) $t['entry_count'] . ' entri · ' . (int) $t['field_count'] . ' field</span>'
                . '</a>';
        }
        echo '</div>';
    }
    admin_footer();
    exit;
}

$status = (string) ($_GET['status'] ?? '');
if ($status !== '' && !in_array($status, entry_statuses(), true)) {
    $status = '';
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$result = get_entries($ct_id, $status, $page, 20);
$fields = get_fields($ct_id);

echo '<p class="muted">Content Type: <strong>' . e($ct['label']) . '</strong> (<code>' . e($ct['slug']) . '</code>)</p>';
if ($can_write) {
    echo '<div class="page-actions"><a class="btn btn-primary" href="' . e(admin_url('entries/create?content_type=' . $ct_id)) . '">+ Tambah Entri</a></div>';
}

echo '<div class="filters">';
$status_links = ['' => 'Semua', 'draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'];
foreach ($status_links as $key => $label) {
    $url = admin_url('entries?content_type=' . $ct_id . ($key !== '' ? '&status=' . $key : ''));
    $cls = $status === $key ? 'btn btn-small active' : 'btn btn-small';
    echo '<a class="' . $cls . '" href="' . e($url) . '">' . $label . '</a> ';
}
echo '</div>';

if ($result['items'] === []) {
    echo '<p class="muted">Tidak ada entri.</p>';
} else {
    echo '<table class="table"><thead><tr><th>ID</th><th>Preview</th><th>Status</th><th>Diperbarui</th><th></th></tr></thead><tbody>';
    foreach ($result['items'] as $entry) {
        $actions = '';
        if ($can_write) {
            $actions .= '<a class="btn btn-small" href="' . e(admin_url('entries/edit?id=' . (int) $entry['id'] . '&content_type=' . $ct_id)) . '">Edit</a> ';
            $actions .= '<form method="post" action="' . e(admin_url('entries/delete')) . '" data-confirm="Hapus entri #' . (int) $entry['id'] . '?" class="inline-form">'
                . csrf_field()
                . '<input type="hidden" name="id" value="' . (int) $entry['id'] . '">'
                . '<input type="hidden" name="content_type" value="' . $ct_id . '">'
                . '<button type="submit" class="btn btn-small btn-danger">Hapus</button></form>';
        }
        echo '<tr>'
            . '<td>#' . (int) $entry['id'] . '</td>'
            . '<td>' . entry_preview($entry, $fields) . '</td>'
            . '<td>' . status_badge($entry['status']) . '</td>'
            . '<td>' . e($entry['updated_at']) . '</td>'
            . '<td class="actions">' . $actions . '</td></tr>';
    }
    echo '</tbody></table>';
    echo render_pagination(admin_url('entries'), $result['page'], $result['total_pages'], ['content_type' => $ct_id, 'status' => $status]);
}

admin_footer();
