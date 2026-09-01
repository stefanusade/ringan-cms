<?php
/**
 * Kelola taksonomi per content type (superadmin only).
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

$user = require_role('superadmin');

$ct_id = (int) ($_GET['content_type'] ?? 0);
$ct = get_content_type($ct_id);
if ($ct === null) {
    http_response_code(404);
    exit('Content type tidak ditemukan.');
}

$errors = [];
$form = ['label' => '', 'slug' => '', 'is_hierarchical' => 0];
$edit_tax = null;
if (isset($_GET['edit'])) {
    $edit_tax = get_taxonomy((int) $_GET['edit']);
    if ($edit_tax === null || (int) $edit_tax['content_type_id'] !== $ct_id) {
        $edit_tax = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = sanitize_text($_POST['action'] ?? '');

    if ($action === 'delete_taxonomy') {
        $tid = (int) ($_POST['taxonomy_id'] ?? 0);
        $tax = get_taxonomy($tid);
        if ($tax !== null && (int) $tax['content_type_id'] === $ct_id) {
            delete_taxonomy($tid);
            log_audit('delete_taxonomy', 'taxonomies', (string) $tid, (int) $user['id']);
            flash_set('success', 'Taksonomi beserta semua term-nya dihapus.');
        }
        redirect_admin('content-types/taxonomies?content_type=' . $ct_id);
    }

    if ($action === 'create_taxonomy' || $action === 'update_taxonomy') {
        $form['label'] = sanitize_text($_POST['label'] ?? '');
        $form['slug'] = sanitize_text($_POST['slug'] ?? '');
        $form['is_hierarchical'] = !empty($_POST['is_hierarchical']) ? 1 : 0;

        if (trim($form['label']) === '') {
            $errors['label'] = 'Label wajib diisi.';
        }
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,98}[a-z0-9])?$/', $form['slug'])) {
            $errors['slug'] = 'Slug hanya huruf kecil, angka, dan strip (contoh: category).';
        }
        $exclude_id = $action === 'update_taxonomy' && $edit_tax !== null ? (int) $edit_tax['id'] : null;
        if (taxonomy_slug_exists($ct_id, $form['slug'], $exclude_id)) {
            $errors['slug'] = 'Slug taksonomi sudah dipakai pada content type ini.';
        }

        if ($errors === []) {
            if ($action === 'create_taxonomy') {
                $tid = create_taxonomy($ct_id, $form['slug'], trim($form['label']), (bool) $form['is_hierarchical']);
                log_audit('create_taxonomy', 'taxonomies', (string) $tid, (int) $user['id']);
                flash_set('success', 'Taksonomi "' . $form['label'] . '" dibuat.');
            } else {
                update_taxonomy((int) $edit_tax['id'], $form['slug'], trim($form['label']), (bool) $form['is_hierarchical']);
                log_audit('update_taxonomy', 'taxonomies', (string) $edit_tax['id'], (int) $user['id']);
                flash_set('success', 'Taksonomi "' . $form['label'] . '" diperbarui.');
            }
            redirect_admin('content-types/taxonomies?content_type=' . $ct_id);
        }
    }
}

$taxonomies = get_taxonomies($ct_id);

if ($edit_tax !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $form['label'] = $edit_tax['label'];
    $form['slug'] = $edit_tax['slug'];
    $form['is_hierarchical'] = (int) $edit_tax['is_hierarchical'];
}

admin_header('Taksonomi: ' . $ct['label'], 'content-types');
?>
<p class="muted">Content Type: <strong><?= e($ct['label']) ?></strong> (<code><?= e($ct['slug']) ?></code>) — <a href="<?= e(admin_url('content-types')) ?>">kembali</a></p>

<div class="card">
  <h2 class="section-title"><?= $edit_tax !== null ? 'Edit Taksonomi: ' . e($edit_tax['label']) : 'Tambah Taksonomi' ?></h2>
  <form method="post" action="<?= e(admin_url('content-types/taxonomies?content_type=' . $ct_id . ($edit_tax !== null ? '&edit=' . (int) $edit_tax['id'] : ''))) ?>">
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
        <small class="hint">Dipakai di API: /<?= e(get_api_path()) ?>/post?tax=category&term=berita</small>
      </div>
    </div>
    <div class="form-group">
      <label class="checkbox-inline"><input type="checkbox" name="is_hierarchical" value="1"<?= $form['is_hierarchical'] ? ' checked' : '' ?>> Hierarkis (bisa punya parent, seperti kategori ber-tingkat)</label>
    </div>
    <button type="submit" class="btn btn-primary"><?= $edit_tax !== null ? 'Simpan Perubahan' : 'Tambah Taksonomi' ?></button>
    <?php if ($edit_tax !== null): ?>
      <a class="btn" href="<?= e(admin_url('content-types/taxonomies?content_type=' . $ct_id)) ?>">Batal Edit</a>
    <?php endif; ?>
  </form>
</div>

<h2 class="section-title">Daftar Taksonomi (<?= count($taxonomies) ?>)</h2>
<?php if ($taxonomies === []): ?>
  <p class="muted">Belum ada taksonomi. Contoh: buat taksonomi <code>category</code> (hierarkis) dan <code>tag</code> (datar).</p>
<?php else: ?>
<table class="table">
  <thead><tr><th>Label</th><th>Slug</th><th>Tipe</th><th>Term</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($taxonomies as $tax): ?>
    <tr>
      <td><?= e($tax['label']) ?></td>
      <td><code><?= e($tax['slug']) ?></code></td>
      <td><?= $tax['is_hierarchical'] ? '<span class="badge">hierarkis</span>' : '<span class="badge">datar</span>' ?></td>
      <td><?= (int) $tax['term_count'] ?></td>
      <td class="actions">
        <a class="btn btn-small" href="<?= e(admin_url('content-types/terms?taxonomy=' . (int) $tax['id'])) ?>">Terms</a>
        <a class="btn btn-small" href="<?= e(admin_url('content-types/taxonomies?content_type=' . $ct_id . '&edit=' . (int) $tax['id'])) ?>">Edit</a>
        <form method="post" action="<?= e(admin_url('content-types/taxonomies?content_type=' . $ct_id)) ?>" data-confirm="Hapus taksonomi <?= e($tax['label']) ?> beserta semua term-nya?" class="inline-form">
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
