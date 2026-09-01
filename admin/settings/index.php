<?php
/**
 * Pengaturan situs (judul, tagline, deskripsi, favicon) — superadmin only.
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
require_once APP_ROOT . '/includes/settings.php';
require_once APP_ROOT . '/includes/upload.php';
require_once APP_ROOT . '/includes/audit_log.php';
require_once APP_ROOT . '/admin/partials/header.php';
require_once APP_ROOT . '/admin/partials/footer.php';

$user = require_role('superadmin');

$errors = [];
$form = get_site_settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $form['site_title'] = sanitize_text($_POST['site_title'] ?? '');
    $form['site_tagline'] = sanitize_text($_POST['site_tagline'] ?? '');
    $form['site_description'] = sanitize_text($_POST['site_description'] ?? '');
    $delete_favicon = !empty($_POST['delete_favicon']);
    $has_file = !empty($_FILES['favicon']['name']);

    if (trim($form['site_title']) === '') {
        $errors['site_title'] = 'Judul situs wajib diisi.';
    }

    if ($has_file) {
        $upload = handle_upload($_FILES['favicon'], true);
        if ($upload['ok']) {
            if ($form['site_favicon'] !== '') {
                delete_upload($form['site_favicon']);
            }
            $form['site_favicon'] = $upload['path'];
        } else {
            $errors['favicon'] = $upload['error'];
        }
    } elseif ($delete_favicon) {
        if ($form['site_favicon'] !== '') {
            delete_upload($form['site_favicon']);
        }
        $form['site_favicon'] = '';
    }

    if ($errors === []) {
        foreach ($form as $key => $value) {
            set_setting($key, (string) $value);
        }
        log_audit('update_settings', 'settings', null, (int) $user['id']);
        flash_set('success', 'Pengaturan situs disimpan.');
        redirect_admin('settings');
    }
}

admin_header('Pengaturan', 'settings');
?>
<form method="post" action="<?= e(admin_url('settings')) ?>" enctype="multipart/form-data" class="card">
  <?= csrf_field() ?>
  <?= render_errors($errors) ?>
  <div class="form-group">
    <label for="site_title">Judul Situs</label>
    <input type="text" id="site_title" name="site_title" value="<?= e($form['site_title']) ?>" required>
    <small class="hint">Nama situs/website Anda.</small>
  </div>
  <div class="form-group">
    <label for="site_tagline">Tagline</label>
    <input type="text" id="site_tagline" name="site_tagline" value="<?= e($form['site_tagline']) ?>" placeholder="mis. CMS ringan untuk konten Anda">
    <small class="hint">Slogan singkat; tampil di halaman login dan tersedia via API.</small>
  </div>
  <div class="form-group">
    <label for="site_description">Deskripsi Situs</label>
    <textarea id="site_description" name="site_description" rows="3"><?= e($form['site_description']) ?></textarea>
    <small class="hint">Deskripsi umum; bisa dipakai frontend untuk meta description.</small>
  </div>
  <div class="form-group">
    <label for="favicon">Favicon</label>
    <input type="file" id="favicon" name="favicon" accept="image/png,image/jpeg,image/gif,image/webp,image/x-icon,image/vnd.microsoft.icon">
    <?php if ($form['site_favicon'] !== ''): ?>
      <div class="current-file">
        <img src="<?= e(BASE_URL . '/files/' . ltrim($form['site_favicon'], '/')) ?>" alt="favicon" width="32" height="32">
        <label class="keep-file"><input type="checkbox" name="delete_favicon" value="1"> Hapus favicon saat ini</label>
      </div>
    <?php endif; ?>
    <small class="hint">PNG, JPG, GIF, WebP, atau ICO.</small>
  </div>
  <button type="submit" class="btn btn-primary">Simpan Pengaturan</button>
</form>
<?php
admin_footer();
