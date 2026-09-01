<?php
/**
 * Buat content type baru (superadmin only).
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

$errors = [];
$form = ['slug' => '', 'label' => '', 'description' => '', 'is_api_enabled' => 1];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $form['slug'] = sanitize_text($_POST['slug'] ?? '');
    $form['label'] = sanitize_text($_POST['label'] ?? '');
    $form['description'] = sanitize_text($_POST['description'] ?? '');
    $form['is_api_enabled'] = !empty($_POST['is_api_enabled']) ? 1 : 0;

    if (!validate_slug($form['slug'])) {
        $errors['slug'] = 'Slug hanya huruf kecil, angka, dan strip (contoh: artikel-berita).';
    }
    if (trim($form['label']) === '') {
        $errors['label'] = 'Label wajib diisi.';
    }
    if (content_type_slug_exists($form['slug'])) {
        $errors['slug'] = 'Slug sudah dipakai.';
    }

    if ($errors === []) {
        $ct_id = create_content_type($form['slug'], trim($form['label']), $form['description'], (bool) $form['is_api_enabled'], (int) $user['id']);
        log_audit('create_content_type', 'content_types', (string) $ct_id, (int) $user['id']);
        flash_set('success', 'Content type dibuat. Sekarang tambahkan field-nya.');
        redirect_admin('content-types/fields?content_type=' . $ct_id);
    }
}

admin_header('Buat Content Type', 'content-types');
?>
<form method="post" action="<?= e(admin_url('content-types/create')) ?>" class="card">
  <?= csrf_field() ?>
  <?= render_errors($errors) ?>
  <div class="form-group">
    <label for="label">Label</label>
    <input type="text" id="label" name="label" value="<?= e($form['label']) ?>" required placeholder="contoh: Artikel Berita">
  </div>
  <div class="form-group">
    <label for="slug">Slug</label>
    <input type="text" id="slug" name="slug" value="<?= e($form['slug']) ?>" required placeholder="contoh: artikel-berita">
    <small class="hint">Digunakan sebagai endpoint API: /api/v1/artikel-berita</small>
  </div>
  <div class="form-group">
    <label for="description">Deskripsi (opsional)</label>
    <textarea id="description" name="description" rows="3"><?= e($form['description']) ?></textarea>
  </div>
  <div class="form-group">
    <label class="checkbox-inline"><input type="checkbox" name="is_api_enabled" value="1"<?= $form['is_api_enabled'] ? ' checked' : '' ?>> Aktifkan sebagai REST API endpoint</label>
  </div>
  <button type="submit" class="btn btn-primary">Simpan</button>
  <a class="btn" href="<?= e(admin_url('content-types')) ?>">Batal</a>
</form>
<?php
admin_footer();
