<?php
/**
 * Kelola term dalam sebuah taksonomi (superadmin only).
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
require_once APP_ROOT . '/admin/partials/header.php';
require_once APP_ROOT . '/admin/partials/footer.php';

$user = require_role('superadmin');

$tax_id = (int) ($_GET['taxonomy'] ?? 0);
$tax = get_taxonomy($tax_id);
if ($tax === null) {
    http_response_code(404);
    exit('Taksonomi tidak ditemukan.');
}
$ct = get_content_type((int) $tax['content_type_id']);

$errors = [];
$form = ['name' => '', 'slug' => '', 'parent_id' => 0];
$edit_term = null;
if (isset($_GET['edit'])) {
    $edit_term = get_term((int) $_GET['edit']);
    if ($edit_term === null || (int) $edit_term['taxonomy_id'] !== $tax_id) {
        $edit_term = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = sanitize_text($_POST['action'] ?? '');

    if ($action === 'delete_term') {
        $tid = (int) ($_POST['term_id'] ?? 0);
        $term = get_term($tid);
        if ($term !== null && (int) $term['taxonomy_id'] === $tax_id) {
            delete_term($tid);
            log_audit('delete_term', 'terms', (string) $tid, (int) $user['id']);
            flash_set('success', 'Term dihapus.');
        }
        redirect_admin('content-types/terms?taxonomy=' . $tax_id);
    }

    if ($action === 'create_term' || $action === 'update_term') {
        $form['name'] = sanitize_text($_POST['name'] ?? '');
        $form['slug'] = sanitize_text($_POST['slug'] ?? '');
        $form['parent_id'] = (int) ($_POST['parent_id'] ?? 0);

        if (trim($form['name']) === '') {
            $errors['name'] = 'Nama term wajib diisi.';
        }
        if ($form['slug'] === '') {
            $form['slug'] = make_term_slug($form['name']);
        }
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,148}[a-z0-9])?$/', $form['slug'])) {
            $errors['slug'] = 'Slug hanya huruf kecil, angka, dan strip.';
        }
        $exclude_id = $action === 'update_term' && $edit_term !== null ? (int) $edit_term['id'] : null;
        if (term_slug_exists($tax_id, $form['slug'], $exclude_id)) {
            $errors['slug'] = 'Slug term sudah dipakai pada taksonomi ini.';
        }
        $parent_id = $form['parent_id'] > 0 ? $form['parent_id'] : null;
        if ($parent_id !== null && get_term($parent_id) === null) {
            $errors['parent_id'] = 'Parent tidak valid.';
        } elseif ($parent_id !== null && $action === 'update_term' && $parent_id === (int) ($edit_term['id'] ?? 0)) {
            $errors['parent_id'] = 'Term tidak bisa menjadi parent dirinya sendiri.';
        }

        if ($errors === []) {
            if ($action === 'create_term') {
                $tid = create_term($tax_id, trim($form['name']), $form['slug'], $parent_id);
                log_audit('create_term', 'terms', (string) $tid, (int) $user['id']);
                flash_set('success', 'Term "' . $form['name'] . '" dibuat.');
            } else {
                update_term((int) $edit_term['id'], trim($form['name']), $form['slug'], $parent_id);
                log_audit('update_term', 'terms', (string) $edit_term['id'], (int) $user['id']);
                flash_set('success', 'Term "' . $form['name'] . '" diperbarui.');
            }
            redirect_admin('content-types/terms?taxonomy=' . $tax_id);
        }
    }
}

$terms = get_terms($tax_id);

if ($edit_term !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $form['name'] = $edit_term['name'];
    $form['slug'] = $edit_term['slug'];
    $form['parent_id'] = (int) $edit_term['parent_id'];
}

admin_header('Term: ' . $tax['label'], 'content-types');
?>
<p class="muted">Taksonomi: <strong><?= e($tax['label']) ?></strong> (<code><?= e($tax['slug']) ?></code>) pada <strong><?= e($ct['label']) ?></strong> — <a href="<?= e(admin_url('content-types/taxonomies?content_type=' . (int) $ct['id'])) ?>">kembali</a></p>

<div class="card">
  <h2 class="section-title"><?= $edit_term !== null ? 'Edit Term: ' . e($edit_term['name']) : 'Tambah Term' ?></h2>
  <form method="post" action="<?= e(admin_url('content-types/terms?taxonomy=' . $tax_id . ($edit_term !== null ? '&edit=' . (int) $edit_term['id'] : ''))) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $edit_term !== null ? 'update_term' : 'create_term' ?>">
    <?= render_errors($errors) ?>
    <div class="grid-2">
      <div class="form-group">
        <label for="term_name">Nama</label>
        <input type="text" id="term_name" name="name" value="<?= e($form['name']) ?>" required placeholder="contoh: Berita">
      </div>
      <div class="form-group">
        <label for="term_slug">Slug</label>
        <input type="text" id="term_slug" name="slug" value="<?= e($form['slug']) ?>" required placeholder="otomatis dari nama">
      </div>
    </div>
    <?php if ($tax['is_hierarchical']): ?>
      <div class="form-group">
        <label for="term_parent">Parent</label>
        <select id="term_parent" name="parent_id">
          <option value="0">— Tanpa parent —</option>
          <?php foreach ($terms as $t): ?>
            <?php if ($edit_term !== null && (int) $t['id'] === (int) $edit_term['id']) { continue; } ?>
            <option value="<?= (int) $t['id'] ?>"<?= $form['parent_id'] === (int) $t['id'] ? ' selected' : '' ?>><?= $t['parent_id'] !== null ? '— ' : '' ?><?= e($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary"><?= $edit_term !== null ? 'Simpan Perubahan' : 'Tambah Term' ?></button>
    <?php if ($edit_term !== null): ?>
      <a class="btn" href="<?= e(admin_url('content-types/terms?taxonomy=' . $tax_id)) ?>">Batal Edit</a>
    <?php endif; ?>
  </form>
</div>

<h2 class="section-title">Daftar Term (<?= count($terms) ?>)</h2>
<?php if ($terms === []): ?>
  <p class="muted">Belum ada term. Tambahkan di atas.</p>
<?php else: ?>
<table class="table">
  <thead><tr><th>Nama</th><th>Slug</th><th>Parent</th><th>Entri</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($terms as $t): ?>
    <tr>
      <td><?= $t['parent_id'] !== null ? '— ' : '' ?><?= e($t['name']) ?></td>
      <td><code><?= e($t['slug']) ?></code></td>
      <td><?= $t['parent_id'] !== null ? '#' . (int) $t['parent_id'] : '<span class="muted">—</span>' ?></td>
      <td><?= (int) $t['entry_count'] ?></td>
      <td class="actions">
        <a class="btn btn-small" href="<?= e(admin_url('content-types/terms?taxonomy=' . $tax_id . '&edit=' . (int) $t['id'])) ?>">Edit</a>
        <form method="post" action="<?= e(admin_url('content-types/terms?taxonomy=' . $tax_id)) ?>" data-confirm="Hapus term <?= e($t['name']) ?>?" class="inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_term">
          <input type="hidden" name="term_id" value="<?= (int) $t['id'] ?>">
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
