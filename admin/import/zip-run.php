<?php
/**
 * Eksekusi import ZIP (plugin Ringan Export) bertahap setelah sesi diverifikasi.
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
require_once APP_ROOT . '/includes/validation.php';
require_once APP_ROOT . '/includes/upload.php';
require_once APP_ROOT . '/includes/taxonomies.php';
require_once APP_ROOT . '/includes/audit_log.php';
require_once APP_ROOT . '/includes/export_profile.php';
require_once APP_ROOT . '/includes/wordpress_import.php';
require_once APP_ROOT . '/includes/zip_import.php';
require_once APP_ROOT . '/includes/webhooks.php';
require_once APP_ROOT . '/admin/partials/header.php';
require_once APP_ROOT . '/admin/partials/footer.php';

$user = require_login();
if (!can_write_entries($user)) {
    http_response_code(403);
    exit('403 — Anda tidak memiliki akses untuk mengimport entri.');
}

wp_import_ensure_table();

$state = zip_import_state_get();
$token = is_string($_POST['token'] ?? null)
    ? (string) $_POST['token']
    : (is_string($_GET['token'] ?? null) ? (string) $_GET['token'] : '');
$auto = !empty($_POST['auto']) || !empty($_GET['auto']);

if ($state === null || !zip_import_state_matches($state, $token)) {
    flash_set('error', 'Sesi import ZIP tidak ditemukan atau sudah berakhir.');
    redirect_admin('import/zip');
}

$ct = get_content_type((int) ($state['ct_id'] ?? 0));
$manifest = zip_import_load_manifest($token);
if ($ct === null || $manifest === null) {
    zip_import_destroy($token);
    flash_set('error', 'Data import ZIP tidak ditemukan atau sudah kedaluwarsa. Silakan upload ulang.');
    redirect_admin('import/zip');
}

$total = is_array($manifest['entries'] ?? null) ? count($manifest['entries']) : 0;
if ($total === 0) {
    zip_import_destroy($token);
    flash_set('error', 'ZIP tidak memuat entry yang bisa diimport.');
    redirect_admin('import/zip');
}

$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? sanitize_text($_POST['action'] ?? '') : '';

if ($action === 'batch') {
    require_csrf();
    $fields = get_fields((int) $ct['id']);
    try {
        $batch = zip_import_run_batch($state, $fields, $user);
    } catch (Throwable $e) {
        log_error('Import ZIP gagal: ' . $e->getMessage(), 'import');
        flash_set('error', 'Import ZIP gagal karena kesalahan internal. Periksa log aplikasi, lalu lanjutkan sesi.');
        redirect_admin('import/zip-run?token=' . urlencode($token) . ($auto ? '&auto=1' : ''));
    }
    zip_import_state_update([
        'offset' => (int) $state['offset'],
        'stats' => (array) $state['stats'],
        'media_cache' => (array) $state['media_cache'],
        'finished' => (bool) $batch['finished'],
    ]);
    if ($batch['finished']) {
        $stats = (array) $state['stats'];
        log_audit('finish_wordpress_zip_import', 'content_types', (string) $ct['id'], (int) $user['id']);
        log_error(sprintf(
            'Import ZIP selesai: ct=%d, imported=%d, updated=%d, skipped=%d, failed=%d, downgraded=%d',
            (int) $ct['id'],
            (int) ($stats['imported'] ?? 0),
            (int) ($stats['updated'] ?? 0),
            (int) ($stats['skipped'] ?? 0),
            (int) ($stats['failed'] ?? 0),
            (int) ($stats['downgraded'] ?? 0)
        ), 'import');
        $imported = (int) ($stats['imported'] ?? 0);
        $updated = (int) ($stats['updated'] ?? 0);
        if ($imported + $updated > 0) {
            webhook_queue_event('entries.imported', $ct, null, $user, [
                'imported' => $imported,
                'updated' => $updated,
                'source' => 'wordpress_zip',
            ]);
        }
    }
    redirect_admin('import/zip-run?token=' . urlencode($token) . ($auto ? '&auto=1' : ''));
}

if ($action === 'finish') {
    require_csrf();
    $ct_id = (int) $ct['id'];
    zip_import_destroy($token);
    flash_set('success', 'Sesi import ZIP ditutup. Entri dan media hasil import tetap tersimpan.');
    redirect_admin('entries?content_type=' . $ct_id);
}

$state = zip_import_state_get();
if ($state === null) {
    flash_set('error', 'Sesi import ZIP sudah berakhir.');
    redirect_admin('import/zip');
}

$stats = (array) ($state['stats'] ?? []);
$offset = (int) ($state['offset'] ?? 0);
$finished = !empty($state['finished']) || $offset >= $total;
$next_count = (int) min(ZIP_IMPORT_BATCH_SIZE, max(0, $total - $offset));

admin_header('Import ZIP — Proses', 'import-zip');
?>
<div class="card">
  <h2>Memproses import ZIP</h2>
  <p class="muted">
    Content type: <strong><?= e((string) $ct['label']) ?></strong>
    · Sumber: <strong><?= e((string) ($state['site_name'] ?? '') !== '' ? (string) $state['site_name'] : ((string) ($state['source_site'] ?? '') !== '' ? (string) $state['source_site'] : 'WordPress')) ?></strong>
    · Token: <code><?= e($token) ?></code>
  </p>
  <p>
    <progress max="<?= max(1, $total) ?>" value="<?= $offset ?>"></progress>
    <strong><?= $offset ?></strong> / <?= $total ?> item
  </p>

  <table class="table">
    <thead><tr><th>Diimport</th><th>Diperbarui</th><th>Dilewati</th><th>Gagal</th><th>Jadi draft</th></tr></thead>
    <tbody>
      <tr>
        <td><?= (int) ($stats['imported'] ?? 0) ?></td>
        <td><?= (int) ($stats['updated'] ?? 0) ?></td>
        <td><?= (int) ($stats['skipped'] ?? 0) ?></td>
        <td><?= (int) ($stats['failed'] ?? 0) ?></td>
        <td><?= (int) ($stats['downgraded'] ?? 0) ?></td>
      </tr>
    </tbody>
  </table>

  <?php if (!$finished): ?>
    <form method="post" action="<?= e(admin_url('import/zip-run')) ?>" data-auto-import>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="batch">
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <div class="form-group">
        <label class="checkbox-inline">
          <input type="checkbox" name="auto" value="1"<?= $auto ? ' checked' : '' ?>>
          Proses otomatis sampai selesai (<?= (int) ZIP_IMPORT_BATCH_SIZE ?> item per batch)
        </label>
        <?php if ($auto): ?>
          <p class="hint">Halaman ini lanjut sendiri tiap ±0,5 detik. Lepas centang di atas sebelum lanjutan berikutnya bila ingin berhenti otomatis.</p>
        <?php endif; ?>
      </div>
      <button type="submit" class="btn btn-primary">
        Proses <?= $next_count ?> item berikutnya
      </button>
      <button type="submit" form="zip-run-finish" class="btn">Hentikan &amp; tutup sesi</button>
    </form>
  <?php else: ?>
    <div class="alert alert-success">
      Import ZIP selesai. <?= (int) ($stats['imported'] ?? 0) ?> entri dibuat,
      <?= (int) ($stats['updated'] ?? 0) ?> diperbarui,
      <?= (int) ($stats['skipped'] ?? 0) ?> dilewati,
      <?= (int) ($stats['failed'] ?? 0) ?> gagal.
    </div>
    <a class="btn btn-primary" href="<?= e(admin_url('entries?content_type=' . (int) $ct['id'])) ?>">Lihat entri</a>
    <form method="post" action="<?= e(admin_url('import/zip-run')) ?>" style="display:inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="finish">
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <button type="submit" class="btn">Tutup sesi &amp; hapus file sementara</button>
    </form>
  <?php endif; ?>
</div>

<form method="post" action="<?= e(admin_url('import/zip-run')) ?>" id="zip-run-finish">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="finish">
  <input type="hidden" name="token" value="<?= e($token) ?>">
</form>

<?php if (($stats['failures'] ?? []) !== []): ?>
<div class="card">
  <h2>Item gagal</h2>
  <div class="wp-import-scroll">
    <table class="table">
      <thead><tr><th>Judul</th><th>Alasan</th></tr></thead>
      <tbody>
        <?php foreach (array_slice((array) $stats['failures'], 0, 50) as $failure): ?>
          <tr>
            <td><?= e((string) ($failure['title'] ?? '')) ?></td>
            <td class="muted"><?= e((string) ($failure['reason'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if (($stats['warnings'] ?? []) !== []): ?>
<div class="card">
  <h2>Peringatan</h2>
  <ul class="hint">
    <?php foreach (array_slice((array) $stats['warnings'], 0, 50) as $warning): ?>
      <li><?= e((string) $warning) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
<script src="<?= e(BASE_URL . '/admin/assets/js/import.js?v=' . RINGAN_CMS_VERSION) ?>"></script>
<?php
admin_footer();