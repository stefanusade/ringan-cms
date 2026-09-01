<?php
/**
 * Kelola field content type (builder field ala ACF, superadmin only).
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
require_once APP_ROOT . '/admin/partials/header.php';
require_once APP_ROOT . '/admin/partials/footer.php';

$user = require_role('superadmin');

$ct_id = (int) ($_GET['content_type'] ?? 0);
$ct = get_content_type($ct_id);
if ($ct === null) {
    http_response_code(404);
    exit('Content type tidak ditemukan.');
}

$errors = [];
$form = ['label' => '', 'field_key' => '', 'field_type' => 'text', 'is_required' => 0, 'sort_order' => 0, 'options' => []];
$edit_field = null;
if (isset($_GET['edit_field'])) {
    $edit_field = get_field((int) $_GET['edit_field']);
    if ($edit_field === null || (int) $edit_field['content_type_id'] !== $ct_id) {
        $edit_field = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = sanitize_text($_POST['action'] ?? '');

    if ($action === 'delete_field') {
        $fid = (int) ($_POST['field_id'] ?? 0);
        $field = get_field($fid);
        if ($field !== null && (int) $field['content_type_id'] === $ct_id) {
            delete_field($fid);
            log_audit('delete_field', 'content_type_fields', (string) $fid, (int) $user['id']);
            flash_set('success', 'Field dihapus.');
        }
        redirect_admin('content-types/fields?content_type=' . $ct_id);
    }

    if ($action === 'create_field' || $action === 'update_field') {
        $form['label'] = sanitize_text($_POST['label'] ?? '');
        $form['field_key'] = sanitize_text($_POST['field_key'] ?? '');
        $form['field_type'] = sanitize_text($_POST['field_type'] ?? '');
        $form['is_required'] = !empty($_POST['is_required']) ? 1 : 0;
        $form['sort_order'] = (int) ($_POST['sort_order'] ?? 0);
        $type_options = [];

        if (trim($form['label']) === '') {
            $errors['label'] = 'Label wajib diisi.';
        }
        if (!isset(field_types()[$form['field_type']])) {
            $errors['field_type'] = 'Tipe field tidak valid.';
        }
        if ($form['field_key'] === '') {
            $form['field_key'] = make_field_key($form['label']);
        }
        if (!validate_field_key($form['field_key'])) {
            $errors['field_key'] = 'Field key harus huruf kecil, angka, dan underscore (contoh: judul_artikel).';
        }
        $exclude_id = $action === 'update_field' && $edit_field !== null ? (int) $edit_field['id'] : null;
        if (field_key_exists($ct_id, $form['field_key'], $exclude_id)) {
            $errors['field_key'] = 'Field key sudah dipakai pada content type ini.';
        }

        if (in_array($form['field_type'], ['text', 'textarea'], true)) {
            $max = (int) ($_POST['max_length'] ?? 0);
            if ($max > 0) {
                $type_options['max_length'] = min(100000, $max);
            }
        }
        if ($form['field_type'] === 'number') {
            if (isset($_POST['min']) && is_numeric($_POST['min'])) {
                $type_options['min'] = (float) $_POST['min'];
            }
            if (isset($_POST['max']) && is_numeric($_POST['max'])) {
                $type_options['max'] = (float) $_POST['max'];
            }
        }
        if ($form['field_type'] === 'select') {
            $lines = preg_split('/\r\n|\r|\n/', (string) ($_POST['options_list'] ?? ''));
            $type_options['options'] = array_values(array_filter(array_map('trim', (array) $lines), fn($l) => $l !== ''));
            if ($type_options['options'] === []) {
                $errors['options_list'] = 'Tulis minimal satu pilihan (satu per baris).';
            }
        }
        if ($form['field_type'] === 'relation') {
            $target_slug = sanitize_text($_POST['relation_target'] ?? '');
            $target = $target_slug !== '' ? get_content_type_by_slug($target_slug) : null;
            if ($target === null) {
                $errors['relation_target'] = 'Pilih content type target.';
            } else {
                $type_options['relation_target'] = $target['slug'];
                $type_options['relation_single'] = !empty($_POST['relation_single']);
            }
        }
        $form['options'] = $type_options;

        if ($errors === []) {
            $sort = $form['sort_order'] > 0 ? $form['sort_order'] : count(get_fields($ct_id)) + 1;
            if ($action === 'create_field') {
                $fid = create_field($ct_id, $form['field_key'], trim($form['label']), $form['field_type'], $type_options, $sort, (bool) $form['is_required']);
                log_audit('create_field', 'content_type_fields', (string) $fid, (int) $user['id']);
                flash_set('success', 'Field "' . $form['label'] . '" ditambahkan.');
            } else {
                update_field((int) $edit_field['id'], $form['field_key'], trim($form['label']), $form['field_type'], $type_options, $sort, (bool) $form['is_required']);
                log_audit('update_field', 'content_type_fields', (string) $edit_field['id'], (int) $user['id']);
                flash_set('success', 'Field "' . $form['label'] . '" diperbarui.');
            }
            redirect_admin('content-types/fields?content_type=' . $ct_id);
        }
    }
}

$fields = get_fields($ct_id);
$other_types = array_values(array_filter(get_content_types(), fn($t) => (int) $t['id'] !== $ct_id));

if ($edit_field !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $form['label'] = $edit_field['label'];
    $form['field_key'] = $edit_field['field_key'];
    $form['field_type'] = $edit_field['field_type'];
    $form['is_required'] = (int) $edit_field['is_required'];
    $form['sort_order'] = (int) $edit_field['sort_order'];
    $form['options'] = decode_options($edit_field['options_json'] ?? null);
}

admin_header('Fields: ' . $ct['label'], 'content-types');
?>
<p class="muted">Content Type: <strong><?= e($ct['label']) ?></strong> (<code><?= e($ct['slug']) ?></code>) — <a href="<?= e(admin_url('content-types')) ?>">kembali</a></p>

<div class="card">
  <h2 class="section-title"><?= $edit_field !== null ? 'Edit Field: ' . e($edit_field['label']) : 'Tambah Field Baru' ?></h2>
  <form method="post" action="<?= e(admin_url('content-types/fields?content_type=' . $ct_id . ($edit_field !== null ? '&edit_field=' . (int) $edit_field['id'] : ''))) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $edit_field !== null ? 'update_field' : 'create_field' ?>">
    <?= render_errors($errors) ?>
    <div class="grid-2">
      <div class="form-group">
        <label for="field_label">Label</label>
        <input type="text" id="field_label" name="label" value="<?= e($form['label']) ?>" required>
      </div>
      <div class="form-group">
        <label for="field_key">Field Key</label>
        <input type="text" id="field_key" name="field_key" value="<?= e($form['field_key']) ?>" required>
        <small class="hint">Otomatis dari label. Dipakai sebagai key JSON di API.</small>
      </div>
    </div>
    <div class="grid-2">
      <div class="form-group">
        <label for="field_type">Tipe Field</label>
        <select id="field_type" name="field_type">
          <?php foreach (field_types() as $ft_key => $ft_label): ?>
            <option value="<?= e($ft_key) ?>"<?= $form['field_type'] === $ft_key ? ' selected' : '' ?>><?= e($ft_label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="sort_order">Urutan</label>
        <input type="number" id="sort_order" name="sort_order" value="<?= (int) $form['sort_order'] ?>" min="0">
      </div>
    </div>

    <div class="opt-group" data-for="text,textarea">
      <div class="form-group">
        <label for="max_length">Maks. Karakter</label>
        <input type="number" id="max_length" name="max_length" min="0" value="<?= e((string) ($form['options']['max_length'] ?? 0)) ?>">
      </div>
    </div>

    <div class="opt-group" data-for="number">
      <div class="grid-2">
        <div class="form-group">
          <label for="min">Min</label>
          <input type="number" id="min" name="min" step="any" value="<?= e((string) ($form['options']['min'] ?? '')) ?>">
        </div>
        <div class="form-group">
          <label for="max">Max</label>
          <input type="number" id="max" name="max" step="any" value="<?= e((string) ($form['options']['max'] ?? '')) ?>">
        </div>
      </div>
    </div>

    <div class="opt-group" data-for="select">
      <div class="form-group">
        <label for="options_list">Pilihan (satu per baris)</label>
        <textarea id="options_list" name="options_list" rows="4"><?= e(implode("\n", $form['options']['options'] ?? [])) ?></textarea>
      </div>
    </div>

    <div class="opt-group" data-for="relation">
      <div class="grid-2">
        <div class="form-group">
          <label for="relation_target">Content Type Target</label>
          <select id="relation_target" name="relation_target">
            <option value="">— Pilih —</option>
            <?php foreach ($other_types as $t): ?>
              <option value="<?= e($t['slug']) ?>"<?= ($form['options']['relation_target'] ?? '') === $t['slug'] ? ' selected' : '' ?>><?= e($t['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="checkbox-inline"><input type="checkbox" name="relation_single" value="1"<?= !empty($form['options']['relation_single']) ? ' checked' : '' ?>> Relasi tunggal (satu entry)</label>
        </div>
      </div>
    </div>

    <div class="form-group">
      <label class="checkbox-inline"><input type="checkbox" name="is_required" value="1"<?= $form['is_required'] ? ' checked' : '' ?>> Wajib diisi</label>
    </div>

    <button type="submit" class="btn btn-primary"><?= $edit_field !== null ? 'Simpan Perubahan' : 'Tambah Field' ?></button>
    <?php if ($edit_field !== null): ?>
      <a class="btn" href="<?= e(admin_url('content-types/fields?content_type=' . $ct_id)) ?>">Batal Edit</a>
    <?php endif; ?>
  </form>
</div>

<h2 class="section-title">Daftar Field (<?= count($fields) ?>)</h2>
<?php if ($fields === []): ?>
  <p class="muted">Belum ada field. Tambahkan di atas.</p>
<?php else: ?>
<table class="table">
  <thead><tr><th>Urutan</th><th>Field Key</th><th>Label</th><th>Tipe</th><th>Wajib</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($fields as $f): ?>
    <tr>
      <td><?= (int) $f['sort_order'] ?></td>
      <td><code><?= e($f['field_key']) ?></code></td>
      <td><?= e($f['label']) ?></td>
      <td><?= e(field_types()[$f['field_type']] ?? $f['field_type']) ?></td>
      <td><?= $f['is_required'] ? 'Ya' : 'Tidak' ?></td>
      <td class="actions">
        <a class="btn btn-small" href="<?= e(admin_url('content-types/fields?content_type=' . $ct_id . '&edit_field=' . (int) $f['id'])) ?>">Edit</a>
        <form method="post" action="<?= e(admin_url('content-types/fields?content_type=' . $ct_id)) ?>" data-confirm="Hapus field <?= e($f['label']) ?>?" class="inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_field">
          <input type="hidden" name="field_id" value="<?= (int) $f['id'] ?>">
          <button type="submit" class="btn btn-small btn-danger">Hapus</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<?php
admin_footer();
