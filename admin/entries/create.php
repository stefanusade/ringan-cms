<?php
/**
 * Buat entri baru.
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
require_once APP_ROOT . '/includes/render.php';
require_once APP_ROOT . '/includes/upload.php';
require_once APP_ROOT . '/includes/audit_log.php';
require_once APP_ROOT . '/includes/taxonomies.php';
require_once APP_ROOT . '/admin/partials/header.php';
require_once APP_ROOT . '/admin/partials/footer.php';

$user = require_login();
if (!can_write_entries($user)) {
    http_response_code(403);
    exit('403 — Anda tidak memiliki akses untuk menulis entri.');
}

$ct_id = (int) ($_GET['content_type'] ?? 0);
$ct = get_content_type($ct_id);
if ($ct === null) {
    http_response_code(404);
    exit('Content type tidak ditemukan.');
}
$fields = get_fields($ct_id);
$taxonomies = get_taxonomies_with_terms($ct_id);
$entry_term_ids = [];

$errors = [];
$form_data = [];
$form_status = 'draft';
$upload_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $raw = is_array($_POST['data'] ?? null) ? $_POST['data'] : [];
    $files = $_FILES['data_file'] ?? [];
    // Path file yang sudah berhasil di-upload pada submit sebelumnya (dibawa via hidden field)
    $carried_files = is_array($_POST['data_file_current'] ?? null) ? $_POST['data_file_current'] : [];
    $carried_gallery = is_array($_POST['data_gallery_current'] ?? null) ? $_POST['data_gallery_current'] : [];
    $delete_files = is_array($_POST['data_delete_file'] ?? null) ? $_POST['data_delete_file'] : [];
    $delete_gallery = is_array($_POST['data_delete_gallery'] ?? null) ? $_POST['data_delete_gallery'] : [];
    $form_status = sanitize_text($_POST['status'] ?? 'draft');
    if (!in_array($form_status, entry_statuses(), true)) {
        $form_status = 'draft';
    }

    $payload = [];
    foreach ($fields as $field) {
        $key = $field['field_key'];
        $type = $field['field_type'];
        if ($type === 'gallery') {
            // Gabungkan gambar carried (submit sebelumnya) + upload baru.
            $carried_list = isset($carried_gallery[$key]) && is_array($carried_gallery[$key])
                ? array_values(array_filter(array_map('strval', $carried_gallery[$key]), fn($p) => $p !== ''))
                : [];
            $delete_ix = isset($delete_gallery[$key]) && is_array($delete_gallery[$key])
                ? array_map('intval', $delete_gallery[$key])
                : [];
            $kept = [];
            foreach ($carried_list as $gi => $gpath) {
                if (in_array($gi, $delete_ix, true)) {
                    delete_upload($gpath);
                } else {
                    $kept[] = $gpath;
                }
            }
            if (isset($files['name'][$key]) && is_array($files['name'][$key])) {
                $count = count($files['name'][$key]);
                for ($gi = 0; $gi < $count; $gi++) {
                    $gfile = [
                        'name' => $files['name'][$key][$gi],
                        'type' => $files['type'][$key][$gi] ?? '',
                        'tmp_name' => $files['tmp_name'][$key][$gi] ?? '',
                        'error' => $files['error'][$key][$gi] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $files['size'][$key][$gi] ?? 0,
                    ];
                    if ($gfile['error'] !== UPLOAD_ERR_NO_FILE) {
                        $gup = handle_upload($gfile, true);
                        if ($gup['ok']) {
                            $kept[] = $gup['path'];
                        } else {
                            $upload_errors[$key] = array_merge($upload_errors[$key] ?? [], [$gup['error']]);
                        }
                    }
                }
            }
            $payload[$key] = array_values($kept);
        } elseif (in_array($type, ['image', 'file'], true)) {
            $carried_path = isset($carried_files[$key]) && is_string($carried_files[$key])
                ? trim($carried_files[$key])
                : '';
            $delete_mark = !empty($delete_files[$key]);
            $has_file = isset($files['name'][$key]) && is_string($files['name'][$key]) && $files['name'][$key] !== '';
            if ($has_file) {
                $upload = handle_upload([
                    'name' => $files['name'][$key],
                    'type' => $files['type'][$key] ?? '',
                    'tmp_name' => $files['tmp_name'][$key] ?? '',
                    'error' => $files['error'][$key] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $files['size'][$key] ?? 0,
                ], $type === 'image');
                if ($upload['ok']) {
                    // File baru menggantikan carried lama (carried adalah orpan submit sebelumnya)
                    if ($carried_path !== '' && $carried_path !== $upload['path']) {
                        delete_upload($carried_path);
                    }
                    $payload[$key] = $upload['path'];
                } else {
                    $upload_errors[$key] = [$upload['error']];
                    // Pertahankan carried bila ada, agar tidak hilang pada re-render
                    $payload[$key] = $carried_path !== '' ? $carried_path : null;
                }
            } elseif ($delete_mark) {
                if ($carried_path !== '') {
                    delete_upload($carried_path);
                }
                $payload[$key] = null;
            } else {
                // Tidak ada file baru → adopsi carried (path dari submit sebelumnya)
                $payload[$key] = $carried_path !== '' ? $carried_path : null;
            }
        } elseif (array_key_exists($key, $raw)) {
            $payload[$key] = $raw[$key];
        } else {
            $payload[$key] = null;
        }
        $form_data[$key] = $payload[$key] ?? null;
    }

    $result = validate_entry_payload($payload, $fields, false, $form_status !== 'draft');
    $errors = $result['errors'];
    foreach ($upload_errors as $k => $msgs) {
        $errors[$k] = array_merge($errors[$k] ?? [], $msgs);
    }

    $term_ids_by_tax = collect_entry_terms_from_post($taxonomies, $_POST);

    if ($errors === []) {
        $entry_id = create_entry($ct_id, $result['data'], $form_status, (int) $user['id']);
        $all_term_ids = [];
        foreach ($term_ids_by_tax as $ids) {
            $all_term_ids = array_merge($all_term_ids, $ids);
        }
        set_entry_terms($entry_id, $all_term_ids);
        log_audit('create_entry', 'content_entries', (string) $entry_id, (int) $user['id']);
        flash_set('success', 'Entri #' . $entry_id . ' dibuat.');
        redirect_admin('entries?content_type=' . $ct_id);
    }
}

admin_header('Tambah Entri: ' . $ct['label'], 'entries');
?>
<p class="muted">Content Type: <strong><?= e($ct['label']) ?></strong> — <a href="<?= e(admin_url('entries?content_type=' . $ct_id)) ?>">kembali</a></p>
<form method="post" action="<?= e(admin_url('entries/create?content_type=' . $ct_id)) ?>" enctype="multipart/form-data" class="card">
  <?= csrf_field() ?>
  <?= render_errors($errors) ?>
  <?php foreach ($fields as $field): ?>
    <?php $value = $form_data[$field['field_key']] ?? null; ?>
    <div class="form-group">
      <label for="field_<?= e($field['field_key']) ?>"><?= e($field['label']) ?><?= $field['is_required'] ? ' <span class="req">*</span>' : '' ?></label>
      <?= render_field_input($field, $value) ?>
      <?php if (in_array($field['field_type'], ['image', 'file'], true) && is_string($value) && $value !== ''): ?>
        <input type="hidden" name="data_file_current[<?= e($field['field_key']) ?>]" value="<?= e($value) ?>">
      <?php endif; ?>
      <?php if (($field['field_type'] ?? '') === 'gallery' && is_array($value)): ?>
        <?php foreach ($value as $gpath): ?>
          <?php if (!is_string($gpath) || $gpath === '') { continue; } ?>
          <input type="hidden" name="data_gallery_current[<?= e($field['field_key']) ?>][]" value="<?= e($gpath) ?>">
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if ($taxonomies !== []): ?>
  <div class="form-group">
    <label>Taksonomi</label>
    <?php foreach ($taxonomies as $tax): ?>
      <div class="taxonomy-box">
        <strong><?= e($tax['label']) ?></strong>
        <div class="taxonomy-options">
          <?php if ($tax['terms'] === []): ?>
            <span class="muted">Belum ada term — <a href="<?= e(admin_url('content-types/terms?taxonomy=' . (int) $tax['id'])) ?>" target="_blank" rel="noopener">kelola term</a>.</span>
          <?php else: ?>
            <?php foreach ($tax['terms'] as $term): ?>
              <label class="checkbox-inline">
                <input type="checkbox" name="terms[<?= (int) $tax['id'] ?>][]" value="<?= (int) $term['id'] ?>"<?= in_array((int) $term['id'], $entry_term_ids[(int) $tax['id']] ?? [], true) ? ' checked' : '' ?>>
                <?= e($term['name']) ?>
              </label>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <small class="hint">Term baru (pisah koma): <input type="text" class="taxonomy-new" name="new_terms[<?= (int) $tax['id'] ?>]" placeholder="mis. Berita, Opini" autocomplete="off"></small>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="form-group">
    <label for="status">Status</label>
    <select id="status" name="status">
      <?php foreach (entry_statuses() as $s): ?>
        <option value="<?= e($s) ?>"<?= $form_status === $s ? ' selected' : '' ?>><?= e($s) ?></option>
      <?php endforeach; ?>
    </select>
    <small class="hint">Field bertanda * (wajib) hanya dicek saat status bukan <em>draft</em> — entri draft bisa disimpan meski field wajib kosong.</small>
  </div>
  <button type="submit" class="btn btn-primary">Simpan Entri</button>
  <a class="btn" href="<?= e(admin_url('entries?content_type=' . $ct_id)) ?>">Batal</a>
</form>
<?php
admin_footer();
