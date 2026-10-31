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
require_once APP_ROOT . '/includes/taxonomies.php';
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

// Quick Edit: hanya field teks single line, tanggal, dan taksonomi.
$quick_fields = entry_quick_edit_fields($fields);
$quick_taxonomies = get_taxonomies_with_terms($ct_id);
$can_quick_edit = $can_write && ($quick_fields !== [] || $quick_taxonomies !== []);
$entry_terms_map = $can_quick_edit ? get_entry_terms_map_bulk(array_column($result['items'], 'id')) : [];
$quick_edit_colspan = $can_write ? 6 : 5;

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
        $entry_data = decode_entry_data($entry);
        $actions = '';
        if ($can_write) {
            if (!$is_trash) {
                if ($can_quick_edit) {
                    $actions .= '<button type="button" class="btn btn-small" data-quick-edit-toggle="entry-' . $entry_id . '"'
                        . ' aria-expanded="false" aria-controls="entry-' . $entry_id . '">Quick Edit</button> ';
                }
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

        // Baris yang bisa di-expand: form Quick Edit (form asli ada di bawah tabel
        // agar tidak ada <form> bersarang di dalam form aksi massal).
        if ($can_quick_edit && !$is_trash) {
            $form_id = 'quick-edit-' . $entry_id;
            $term_map = $entry_terms_map[$entry_id] ?? [];
            echo '<tr class="quick-edit-row" id="entry-' . $entry_id . '" hidden><td colspan="' . $quick_edit_colspan . '">'
                . '<div class="quick-edit-inner">'
                . '<strong>Quick Edit #' . $entry_id . '</strong>'
                . ' <span class="muted">Status tetap <em>' . e($entry['status']) . '</em>.</span>';
            if ($quick_fields !== []) {
                echo '<div class="quick-edit-grid">';
                foreach ($quick_fields as $field) {
                    $input_id = 'q' . $entry_id . '_' . $field['field_key'];
                    echo '<div class="form-group">'
                        . '<label for="' . e($input_id) . '">' . e($field['label'])
                        . ($field['is_required'] ? ' <span class="req">*</span>' : '') . '</label>'
                        . render_quick_edit_input($field, $entry_data[$field['field_key']] ?? null, 'q' . $entry_id, $form_id)
                        . '</div>';
                }
                echo '</div>';
            }
            foreach ($quick_taxonomies as $tax) {
                $tax_id = (int) $tax['id'];
                $selected = $term_map[$tax_id] ?? [];
                $select_id = 'q' . $entry_id . '_tax_' . $tax_id;
                echo '<div class="quick-edit-tax">'
                    . '<label for="' . e($select_id) . '">' . e($tax['label']) . '</label>';
                if ($tax['terms'] === []) {
                    echo '<span class="muted">Belum ada term — <a href="' . e(admin_url('taxonomies/terms?taxonomy=' . $tax_id)) . '" target="_blank" rel="noopener">kelola term</a>.</span>';
                } else {
                    $terms_shown = array_slice($tax['terms'], 0, 200);
                    $shown_ids = array_map('intval', array_column($terms_shown, 'id'));
                    // Term terpilih di luar 200 pertama tetap dipertahankan (hidden input).
                    foreach ($selected as $selected_id) {
                        if (!in_array((int) $selected_id, $shown_ids, true)) {
                            echo '<input type="hidden" name="terms[' . $tax_id . '][]" value="' . (int) $selected_id . '" form="' . e($form_id) . '">';
                        }
                    }
                    echo '<select id="' . e($select_id) . '" name="terms[' . $tax_id . '][]" multiple size="3" form="' . e($form_id) . '">';
                    foreach ($terms_shown as $term) {
                        echo '<option value="' . (int) $term['id'] . '"'
                            . (in_array((int) $term['id'], $selected, true) ? ' selected' : '') . '>'
                            . e($term['name']) . '</option>';
                    }
                    echo '</select>';
                    if (count($tax['terms']) > count($terms_shown)) {
                        echo ' <small class="hint">Menampilkan ' . count($terms_shown) . ' dari ' . count($tax['terms']) . ' term — gunakan Edit lengkap untuk term lainnya.</small>';
                    } else {
                        echo ' <small class="hint">Ctrl/Cmd + klik untuk memilih beberapa term.</small>';
                    }
                }
                echo '</div>';
            }
            echo '<div class="quick-edit-actions">'
                . '<button type="submit" form="' . e($form_id) . '" class="btn btn-small btn-primary">Simpan</button>'
                . '<button type="button" class="btn btn-small" data-quick-edit-close="entry-' . $entry_id . '">Batal</button>'
                . '<a class="btn btn-small" href="' . e(admin_url('entries/edit?id=' . $entry_id . '&content_type=' . $ct_id)) . '">Edit lengkap</a>'
                . '</div>'
                . '</div></td></tr>';
        }
    }
    echo '</tbody></table>';
}

if ($bulk_open) {
    echo '</form>';
}

// Form Quick Edit diletakkan di luar tabel: input di baris expand memakai atribut
// form="quick-edit-N" agar tidak ada <form> bersarang di dalam form aksi massal.
if ($can_quick_edit) {
    foreach ($result['items'] as $entry) {
        if (entry_is_trashed($entry)) {
            continue;
        }
        $entry_id = (int) $entry['id'];
        echo '<form id="quick-edit-' . $entry_id . '" method="post" action="' . e(admin_url('entries/quick-edit')) . '" class="quick-edit-form">'
            . csrf_field()
            . '<input type="hidden" name="content_type" value="' . $ct_id . '">'
            . '<input type="hidden" name="id" value="' . $entry_id . '">'
            . '<input type="hidden" name="return_status" value="' . e($status) . '">'
            . '<input type="hidden" name="return_page" value="' . $page . '">'
            . ($quick_taxonomies !== [] ? '<input type="hidden" name="terms_present" value="1">' : '')
            . '</form>';
    }
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
