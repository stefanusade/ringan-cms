<?php
/**
 * Pengaturan Media: kompresi gambar & offload R2/S3-compatible.
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
require_once APP_ROOT . '/includes/s3.php';
require_once APP_ROOT . '/includes/audit_log.php';
require_once APP_ROOT . '/admin/partials/header.php';
require_once APP_ROOT . '/admin/partials/footer.php';

$user = require_role('superadmin');

$errors = [];
$form = [];
$media_keys = [
    'media_compress', 'media_max_width', 'media_quality',
    'media_offload', 'media_endpoint', 'media_region', 'media_bucket',
    'media_access_key', 'media_secret_key', 'media_keep_local', 'media_public_base',
];
foreach ($media_keys as $k) {
    $form[$k] = get_setting($k, (string) (SETTINGS_DEFAULTS[$k] ?? ''));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $action = sanitize_text($_POST['action'] ?? 'save');

    if ($action === 'test') {
        $tmp = sys_get_temp_dir() . '/rcm-s3-test-' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($tmp, 'RinganCMS connection test');
        $res = s3_put_object('rcm-connection-test.txt', $tmp, 'text/plain');
        if ($res['ok']) {
            s3_delete_object('rcm-connection-test.txt');
        }
        @unlink($tmp);
        flash_set($res['ok'] ? 'success' : 'error', $res['ok']
            ? 'Koneksi berhasil — objek uji diunggah & dihapus.'
            : 'Tes koneksi gagal: ' . $res['error']);
        redirect_admin('settings/media');
    }

    // Simpan
    $form['media_compress'] = !empty($_POST['media_compress']) ? '1' : '0';
    $form['media_max_width'] = (string) max(100, (int) ($_POST['media_max_width'] ?? 1920));
    $form['media_quality'] = (string) max(10, min(100, (int) ($_POST['media_quality'] ?? 82)));
    $form['media_offload'] = !empty($_POST['media_offload']) ? '1' : '0';
    $form['media_endpoint'] = rtrim(trim(sanitize_text($_POST['media_endpoint'] ?? '')), '/');
    $form['media_region'] = trim(sanitize_text($_POST['media_region'] ?? 'auto'));
    $form['media_bucket'] = trim(sanitize_text($_POST['media_bucket'] ?? ''));
    $form['media_access_key'] = trim((string) ($_POST['media_access_key'] ?? ''));
    $form['media_secret_key'] = (string) ($_POST['media_secret_key'] ?? '');
    $form['media_keep_local'] = !empty($_POST['media_keep_local']) ? '1' : '0';
    $form['media_public_base'] = rtrim(trim(sanitize_text($_POST['media_public_base'] ?? '')), '/');

    if ($form['media_endpoint'] !== '' && !filter_var($form['media_endpoint'], FILTER_VALIDATE_URL)) {
        $errors['media_endpoint'] = 'Endpoint tidak valid (harus URL, contoh https://xxx.r2.cloudflarestorage.com).';
    }
    if ($form['media_offload'] === '1') {
        if ($form['media_endpoint'] === '') {
            $errors['media_endpoint'] = 'Endpoint wajib diisi saat offload aktif.';
        }
        if ($form['media_bucket'] === '') {
            $errors['media_bucket'] = 'Nama bucket wajib diisi.';
        }
        if ($form['media_access_key'] === '' || $form['media_secret_key'] === '') {
            $errors['media_keys'] = 'Access key & secret key wajib diisi (atau pakai env MEDIA_ACCESS_KEY / MEDIA_SECRET_KEY).';
        }
    }
    if ($form['media_public_base'] !== '' && !filter_var($form['media_public_base'], FILTER_VALIDATE_URL)) {
        $errors['media_public_base'] = 'URL publik tidak valid.';
    }

    if ($errors === []) {
        foreach ($media_keys as $k) {
            set_setting($k, $form[$k]);
        }
        log_audit('update_media_settings', 'settings', null, (int) $user['id']);
        flash_set('success', 'Pengaturan media disimpan.');
        redirect_admin('settings/media');
    }
}

admin_header('Pengaturan Media', 'settings-media');
?>
<form method="post" action="<?= e(admin_url('settings/media')) ?>" class="card">
  <?= csrf_field() ?>
  <?= render_errors($errors) ?>

  <h2 class="section-title">Kompresi Gambar</h2>
  <div class="form-group">
    <label class="checkbox-inline"><input type="checkbox" name="media_compress" value="1"<?= $form['media_compress'] === '1' ? ' checked' : '' ?>> Kompres gambar saat upload (JPEG/WebP: quality; PNG: hanya resize)</label>
  </div>
  <div class="grid-2">
    <div class="form-group">
      <label for="media_max_width">Lebar Maksimum (px)</label>
      <input type="number" id="media_max_width" name="media_max_width" value="<?= e($form['media_max_width']) ?>" min="100">
      <small class="hint">Gambar lebih lebar akan di-resize mempertahankan rasio.</small>
    </div>
    <div class="form-group">
      <label for="media_quality">Kualitas (10–100)</label>
      <input type="number" id="media_quality" name="media_quality" value="<?= e($form['media_quality']) ?>" min="10" max="100">
    </div>
  </div>

  <h2 class="section-title">Offload Media (R2 / S3-compatible)</h2>
  <div class="form-group">
    <label class="checkbox-inline"><input type="checkbox" name="media_offload" value="1" id="media_offload"<?= $form['media_offload'] === '1' ? ' checked' : '' ?>> Salin media ke penyimpanan jarak jauh saat upload</label>
  </div>
  <div class="grid-2">
    <div class="form-group">
      <label for="media_endpoint">Endpoint</label>
      <input type="url" id="media_endpoint" name="media_endpoint" value="<?= e($form['media_endpoint']) ?>" placeholder="https://<account>.r2.cloudflarestorage.com">
    </div>
    <div class="form-group">
      <label for="media_region">Region</label>
      <input type="text" id="media_region" name="media_region" value="<?= e($form['media_region']) ?>" placeholder="auto">
      <small class="hint">Cloudflare R2: <code>auto</code>.</small>
    </div>
  </div>
  <div class="form-group">
    <label for="media_bucket">Bucket</label>
    <input type="text" id="media_bucket" name="media_bucket" value="<?= e($form['media_bucket']) ?>" placeholder="ringan-cms-media">
  </div>
  <div class="grid-2">
    <div class="form-group">
      <label for="media_access_key">Access Key ID</label>
      <input type="text" id="media_access_key" name="media_access_key" value="<?= e($form['media_access_key']) ?>" autocomplete="off">
      <small class="hint">Alternatif aman: env <code>MEDIA_ACCESS_KEY</code> (nilai DB diabaikan bila env ada).</small>
    </div>
    <div class="form-group">
      <label for="media_secret_key">Secret Access Key</label>
      <input type="password" id="media_secret_key" name="media_secret_key" value="<?= e($form['media_secret_key']) ?>" autocomplete="new-password">
      <small class="hint">Alternatif aman: env <code>MEDIA_SECRET_KEY</code>.</small>
    </div>
  </div>
  <div class="form-group">
    <label class="checkbox-inline"><input type="checkbox" name="media_keep_local" value="1"<?= $form['media_keep_local'] === '1' ? ' checked' : '' ?>> Simpan salinan lokal setelah offload</label>
    <small class="hint" style="display:block">Jika dicentang, file tetap dilayani dari <code>/files/…</code>. Jika tidak, isi <em>Public Base URL</em> agar /files/ mengalihkan ke objek jarak jauh.</small>
  </div>
  <div class="form-group">
    <label for="media_public_base">Public Base URL</label>
    <input type="url" id="media_public_base" name="media_public_base" value="<?= e($form['media_public_base']) ?>" placeholder="https://pub-xxxxxxxx.r2.dev">
    <small class="hint">Dipakai untuk redirect saat file lokal dihapus (offload penuh).</small>
  </div>

  <button type="submit" class="btn btn-primary">Simpan Pengaturan Media</button>
  <button type="submit" name="action" value="test" class="btn">Tes Koneksi (pakai setting tersimpan)</button>
</form>
<?php
admin_footer();
