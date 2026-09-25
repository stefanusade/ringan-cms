<?php
/**
 * Kelola taksonomi lintas content type (superadmin & editor).
 * Sebuah taksonomi bisa dipakai oleh satu atau banyak content type.
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
require_once APP_ROOT . '/includes/taxonomies.php';
require_once APP_ROOT . '/includes/audit_log.php';
require_once APP_ROOT . '/includes/settings.php';
require_once APP_ROOT . '/admin/partials/header.php';
require_once APP_ROOT . '/admin/partials/footer.php';

$user = require_role('superadmin', 'editor');

$all_types = get_content_types();
$filter_ct = isset($_GET['content_type']) ? (int) $_GET['content_type'] : 0;
$filter_type = $filter_ct > 0 ? get_content_type($filter_ct) : null;
if ($filter_type === null) {
    $filter_ct = 0;
}
$list_url = admin_url('taxonomies' . ($filter_ct > 0 ? '?content_type=' . $filter_ct : ''));

$errors = [];
$form = ['label' => '', 'slug' => '', 'is_hierarchical' => 0, 'content_types' => []];

$edit_tax = isset($_GET['edit']) ? get_taxonomy((int) $_GET['edit']) : null;
if ($edit_tax !== null) {
    $form['label'] = $edit_tax['label'];
    $form['slug'] = $edit_tax['slug'];
    $form['is_hierarchical'] = (int) $edit_tax['is_hierarchical'];
    $form['content_types'] = get_taxonomy_content_type_ids((int) $edit_tax['id']);
}
$form_action = $edit_tax !== null
    ? admin_url('taxonomies?edit=' . (int) $edit_tax['id'] . ($filter_ct > 0 ? '&content_type=' . $filter_ct : ''))
    : $list_url;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = sanitize_text($_POST['action'] ?? '');

    if ($action === 'delete_taxonomy') {
        $tid = (int) ($_POST['taxonomy_id'] ?? 0);
        if (get_taxonomy($tid) !== null) {
            delete_taxonomy($tid);
            log_audit('delete_taxonomy', 'taxonomies', (string) $tid, (int) $user['id']);
            flash_set('success', 'Taksonomi beserta semua term-nya dihapus.');
        }
        redirect_admin('taxonomies' . ($filter_ct > 0 ? '?content_type=' . $filter_ct : ''));
    }

    if ($action === 'create_taxonomy' || $action === 'update_taxonomy') {
        $form['label'] = sanitize_text($_POST['label'] ?? '');
        $form['slug'] = sanitize_text($_POST['slug'] ?? '');
        $form['is_hierarchical'] = !empty($_POST['is_hierarchical']) ? 1 : 0;
        $form['content_types'] = is_array($_POST['content_types'] ?? null)
            ? array_map('intval', $_POST['content_types'])
            : [];

        if (trim($form['label']) === '') {
            $errors['label'] = 'Label wajib diisi.';
        }
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,98}[a-z0-9])?$/', $form['slug'])) {
            $errors['slug'] = 'Slug hanya huruf kecil, angka, dan strip (contoh: category).';
        }
        $exclude_id = $edit_tax !== null ? (int) $edit_tax['id'] : null;
        if (!isset($errors['slug']) && taxonomy_slug_exists($form['slug'], $exclude_id)) {
            $errors['slug'] = 'Slug taksonomi sudah dipakai (slug bersifat global).';
        }
        if ($form['content_types'] === []) {
            $errors['content_types'] = 'Pilih minimal satu content type.';
        }

        if ($errors === []) {
            if ($action === 'create_taxonomy') {
                $tid = create_taxonomy($form['content_types'], $form['slug'], trim($form['label']), (bool) $form['is_hierarchical']);
                log_audit('create_taxonomy', 'taxonomies', (string) $tid, (int) $user['id']);
                flash_set('success', 'Taksonomi "' . $form['label'] . '" dibuat.');
            } else {
                update_taxonomy((int) $edit_tax['id'], $form['slug'], trim($form['label']), (bool) $form['is_hierarchical'], $form['content_types']);
                log_audit('update_taxonomy', 'taxonomies', (string) $edit_tax['id'], (int) $user['id']);
                flash_set('success', 'Taksonomi "' . $form['label'] . '" diperbarui.');
            }
            redirect_admin('taxonomies' . ($filter_ct > 0 ? '?content_type=' . $filter_ct : ''));
        }
    }
}

$taxonomies = get_all_taxonomies($filter_ct > 0 ? $filter_ct : null);

admin_header('Taxonomies', 'taxonomies');
?>
<div class="card">
  <h2 class="section-title">
    <?= $edit_tax !== null ? 'Edit Taksonomi: ' . e($edit_tax['label']) : 'Tambah Taksonomi' ?>
  </h2>
  <form method="post" action="<?= e($form_action) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $edit_tax !== null ? 'update_taxonomy' : 'create_taxonomy' ?>">
    <?= render_errors($errors) ?>
    <div class="grid-2">
      <div class="form-group">
        <label for="tax_label">Label</label>
        <input type="text" id="tax_label" name="label" value="<?= e($form['label']) ?>" required placeholder="contoh: Kategori">
      </div>
      <div class="form-group">
        <label for="tax_slug">Slug</label>
        <input type="text" id="tax_slug" name="slug" value="<?= e($form['slug']) ?>" required placeholder="contoh: category">
        <small class="hint">Global (unik) &amp; dipakai di API: /<?= e(get_api_path()) ?>/post?tax=category&amp;term=berita</small>
      </div>
    </div>
    <div class="form-group">
      <label class="checkbox-inline"><input type="checkbox" name="is_hierarchical" value="1"<?= $form['is_hierarchical'] ? ' checked' : '' ?>> Hierarkis (bisa punya parent, seperti kategori ber-tingkat)</label>
    </div>
    <div class="form-group">
      <label>Dipakai pada Content Type</label>
      <?php if ($all_types === []): ?>
        <p class="muted">Belum ada content type.</p>
      <?php else: ?>
        <div class="checkbox-list">
          <?php foreach ($all_types as $ct): ?>
            <label class="checkbox-inline">
              <input type="checkbox" name="content_types[]" value="<?= (int) $ct['id'] ?>"<?= in_array((int) $ct['id'], $form['content_types'], true) ? ' checked' : '' ?>>
              <?= e($ct['label']) ?> (<code><?= e($ct['slug']) ?></code>)
            </label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <button type="submit" class="btn btn-primary"><?= $edit_tax !== null ? 'Simpan Perubahan' : 'Tambah Taksonomi' ?></button>
    <?php if ($edit_tax !== null): ?>
      <a class="btn" href="<?= e($list_url) ?>">Batal Edit</a>
    <?php endif; ?>
  </form>
</div>

<h2 class="section-title">
  Daftar Taksonomi (<?= count($taxonomies) ?>)
  <?php if ($filter_type !== null): ?>
    — <span class="muted">difilter: <?= e($filter_type['label']) ?></span>
    <a class="btn btn-small" href="<?= e(admin_url('taxonomies')) ?>">Hapus filter</a>
  <?php endif; ?>
</h2>
<?php if ($taxonomies === []): ?>
  <p class="muted">Belum ada taksonomi. Contoh: buat taksonomi <code>category</code> (hierarkis) dan <code>tag</code> (datar), lalu pilih content type yang memakainya.</p>
<?php else: ?>
<table class="table">
  <thead><tr><th>Label</th><th>Slug</th><th>Tipe</th><th>Content Type</th><th>Term</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($taxonomies as $tax): ?>
    <?php $used_by = get_taxonomy_content_types((int) $tax['id']); ?>
    <tr>
      <td><?= e($tax['label']) ?></td>
      <td><code><?= e($tax['slug']) ?></code></td>
      <td><?= $tax['is_hierarchical'] ? '<span class="badge">hierarkis</span>' : '<span class="badge">datar</span>' ?></td>
      <td>
        <?php if ($used_by === []): ?>
          <span class="muted">— belum dipakai —</span>
        <?php else: ?>
          <?= e(implode(', ', array_map(fn($c) => (string) $c['label'], $used_by))) ?>
        <?php endif; ?>
      </td>
      <td><?= (int) $tax['term_count'] ?></td>
      <td class="actions">
        <a class="btn btn-small" href="<?= e(admin_url('taxonomies/terms?taxonomy=' . (int) $tax['id'])) ?>">Terms</a>
        <a class="btn btn-small" href="<?= e(admin_url('taxonomies?edit=' . (int) $tax['id'] . ($filter_ct > 0 ? '&content_type=' . $filter_ct : ''))) ?>">Edit</a>
        <form method="post" action="<?= e($list_url) ?>" data-confirm="Hapus taksonomi <?= e($tax['label']) ?> beserta semua term-nya?" class="inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_taxonomy">
          <input type="hidden" name="taxonomy_id" value="<?= (int) $tax['id'] ?>">
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
