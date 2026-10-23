<?php
/**
 * Daftar entri per content type.
 *
 * Tab: Semua / Published / Draft / Trash. Entri Trash adalah soft delete dan
 * tidak muncul di tab "Semua" maupun API. Aksi massal ditangani entries/bulk.php.
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
if (!in_array($status, entry_filter_statuses(), true)) {
    $status = '';
}
$trash_supported = entry_trash_supported();
if (($status === 'trash' && !$trash_supported) || (!$can_write && $status === 'trash')) {
    $status = '';
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$result = get_entries($ct_id, $status, $page, 20);
$fields = get_fields($ct_id);
$counts = count_entries_by_status($ct_id);
$all_count = (int) ($counts['draft'] ?? 0) + (int) ($counts['published'] ?? 0) + (int) ($counts['archived'] ?? 0);

$tabs = [
    '' => ['label' => 'Semua', 'count' => $all_count],
    'published' => ['label' => 'Published', 'count' => (int) ($counts['published'] ?? 0)],
    'draft' => ['label' => 'Draft', 'count' => (int) ($counts['draft'] ?? 0)],
];
if ($can_write) {
    if ($trash_supported) {
        $tabs['trash'] = ['label' => 'Trash', 'count' => (int) ($counts['trash'] ?? 0)];
    }
}

echo '<p class="muted">Content Type: <strong>' . e($ct['label']) . '</strong> (<code>' . e($ct['slug']) . '</code>)</p>';
if ($can_write) {
    echo '<div class="page-actions"><a class="btn btn-primary" href="' . e(admin_url('entries/create?content_type=' . $ct_id)) . '">+ Tambah Entri</a></div>';
}

echo '<nav class="tabs">';
foreach ($tabs as $key => $tab) {
    $url = admin_url('entries?content_type=' . $ct_id . ($key !== '' ? '&status=' . $key : ''));
    echo '<a class="tab' . ($status === $key ? ' active' : '') . '" href="' . e($url) . '">'
        . e($tab['label']) . ' <span class="tab-count">' . (int) $tab['count'] . '</span></a>';
}
echo '</nav>';

$bulk_open = $can_write && $result['items'] !== [];
if ($bulk_open) {
    echo '<form method="post" action="' . e(admin_url('entries/bulk')) . '" data-bulk id="entries-bulk">'
        . csrf_field()
        . '<input type="hidden" name="content_type" value="' . $ct_id . '">'
        . '<input type="hidden" name="return_status" value="' . e($status) . '">'
        . '<input type="hidden" name="return_page" value="' . $page . '">';
    echo '<div class="bulk-bar">'
        . '<label class="checkbox-inline"><input type="checkbox" data-check-all> Pilih semua</label>'
        . '<select name="bulk_action" aria-label="Aksi massal">'
        . '<option value="">— Pilih aksi —</option>'
        . '<option value="draft">Set status: Draft</option>'
        . '<option value="published">Set status: Published</option>'
        . '<option value="trash">Buang ke Trash</option>'
        . '<option value="restore">Pulihkan dari Trash</option>'
        . '<option value="delete">Hapus permanen</option>'
        . '</select>'
        . '<button type="submit" class="btn btn-small">Terapkan</button>'
        . '</div>';
}

if ($result['items'] === []) {
    echo '<p class="muted">Tidak ada entri pada tab ini.</p>';
} else {
    echo '<table class="table"><thead><tr>';
    if ($can_write) {
        echo '<th class="col-check"></th>';
    }
    echo '<th>ID</th><th>Preview</th><th>Status</th><th>Diperbarui</th><th></th></tr></thead><tbody>';
    foreach ($result['items'] as $entry) {
        $entry_id = (int) $entry['id'];
        $is_trash = entry_is_trashed($entry);
        $actions = '';
        if ($can_write) {
            if (!$is_trash) {
                $actions .= '<a class="btn btn-small" href="' . e(admin_url('entries/edit?id=' . $entry_id . '&content_type=' . $ct_id)) . '">Edit</a> ';
                $actions .= '<button type="submit" form="entry-row-' . $entry_id . '" name="bulk_action" value="trash" class="btn btn-small"'
                    . ' data-confirm="Buang entri #' . $entry_id . ' ke Trash?">Buang</button> ';
                $actions .= '<button type="submit" form="entry-row-' . $entry_id . '" name="bulk_action" value="delete" class="btn btn-small btn-danger"'
                    . ' data-confirm="Hapus permanen entri #' . $entry_id . '? Tindakan ini tidak bisa dibatalkan.">Hapus</button>';
            } else {
                $actions .= '<button type="submit" form="entry-row-' . $entry_id . '" name="bulk_action" value="restore" class="btn btn-small">Pulihkan</button> ';
                $actions .= '<button type="submit" form="entry-row-' . $entry_id . '" name="bulk_action" value="delete" class="btn btn-small btn-danger"'
                    . ' data-confirm="Hapus permanen entri #' . $entry_id . '? Tindakan ini tidak bisa dibatalkan.">Hapus permanen</button>';
            }
        }
        echo '<tr>';
        if ($can_write) {
            echo '<td class="col-check"><input type="checkbox" name="ids[]" value="' . $entry_id . '" aria-label="Pilih entri #' . $entry_id . '"></td>';
        }
        echo '<td>#' . $entry_id . '</td>'
            . '<td>' . entry_preview($entry, $fields) . '</td>'
            . '<td>' . status_badge($entry['status']) . '</td>'
            . '<td>' . e($entry['updated_at']) . '</td>'
            . '<td class="actions">' . $actions . '</td></tr>';
    }
    echo '</tbody></table>';
}

if ($bulk_open) {
    echo '</form>';
}

if ($result['items'] !== []) {
    echo render_pagination(admin_url('entries'), $result['page'], $result['total_pages'], ['content_type' => $ct_id, 'status' => $status]);
}

// Form tersembunyi untuk aksi per baris (tombol memakai atribut form=...).
if ($can_write) {
    foreach ($result['items'] as $entry) {
        $entry_id = (int) $entry['id'];
        echo '<form id="entry-row-' . $entry_id . '" method="post" action="' . e(admin_url('entries/bulk')) . '" class="row-action-form">'
            . csrf_field()
            . '<input type="hidden" name="content_type" value="' . $ct_id . '">'
            . '<input type="hidden" name="return_status" value="' . e($status) . '">'
            . '<input type="hidden" name="return_page" value="' . $page . '">'
            . '<input type="hidden" name="ids[]" value="' . $entry_id . '">'
            . '</form>';
    }
}

admin_footer();
