<?php
/**
 * Eksekusi import WordPress bertahap (batch) setelah mapping diverifikasi.
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
require_once APP_ROOT . '/includes/wordpress_import.php';
require_once APP_ROOT . '/includes/webhooks.php';
require_once APP_ROOT . '/admin/partials/header.php';
require_once APP_ROOT . '/admin/partials/footer.php';

$user = require_login();
if (!can_write_entries($user)) {
    http_response_code(403);
    exit('403 — Anda tidak memiliki akses untuk mengimport entri.');
}

wp_import_ensure_table();

$state = wp_import_state_get();
$token = is_string($_POST['token'] ?? null)
    ? (string) $_POST['token']
    : (is_string($_GET['token'] ?? null) ? (string) $_GET['token'] : '');
$auto = !empty($_POST['auto']) || !empty($_GET['auto']);

if ($state === null || !wp_import_state_matches($state, $token)) {
    flash_set('error', 'Sesi import tidak ditemukan atau sudah berakhir.');
    redirect_admin('import');
}

$ct = get_content_type((int) $state['ct_id']);
$items_payload = wp_import_load_items($token);
if ($ct === null || $items_payload === null) {
    wp_import_destroy($token);
    flash_set('error', 'Data import tidak ditemukan atau sudah kedaluwarsa. Silakan upload ulang.');
    redirect_admin('import');
}

$total = count(wp_import_select_items($items_payload['items'], (string) $state['post_type']));
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? sanitize_text($_POST['action'] ?? '') : '';

if ($action === 'batch') {
    require_csrf();
    $fields = get_fields((int) $state['ct_id']);
    try {
        $batch = wp_import_run_batch($state, $fields, $user);
    } catch (Throwable $e) {
        log_error('Import WordPress gagal: ' . $e->getMessage(), 'import');
        flash_set('error', 'Import gagal: ' . $e->getMessage());
        redirect_admin('import/run?token=' . urlencode($token) . ($auto ? '&auto=1' : ''));
    }
    wp_import_state_update([
        'offset' => (int) $state['offset'],
        'stats' => (array) $state['stats'],
        'media_cache' => (array) $state['media_cache'],
        'finished' => (bool) $batch['finished'],
    ]);
    if ($batch['finished']) {
        $stats = (array) $state['stats'];
        log_audit('finish_wordpress_import', 'content_types', (string) $state['ct_id'], (int) $user['id']);
        log_error(sprintf(
            'Import WordPress selesai: ct=%d, imported=%d, updated=%d, skipped=%d, failed=%d, downgraded=%d',
            (int) $state['ct_id'],
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
            ]);
        }
    }
    redirect_admin('import/run?token=' . urlencode($token) . ($auto ? '&auto=1' : ''));
}

if ($action === 'finish') {
    require_csrf();
    $ct_id = (int) $state['ct_id'];
    wp_import_destroy($token);
    flash_set('success', 'Sesi import ditutup. Entri hasil import tetap tersimpan.');
    redirect_admin('entries?content_type=' . $ct_id);
}

$state = wp_import_state_get();
$stats = (array) ($state['stats'] ?? []);
$offset = (int) ($state['offset'] ?? 0);
$finished = !empty($state['finished']) || $offset >= $total;

admin_header('Import WordPress — Proses', 'import');
?>
<div class="card">
  <h2>Memproses import</h2>
  <p class="muted">
    Content type: <strong><?= e((string) $ct['label']) ?></strong>
    · Post type: <code><?= e((string) $state['post_type']) ?></code>
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
    <form method="post" action="<?= e(admin_url('import/run')) ?>" data-auto-import>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="batch">
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <?php if ($auto): ?><input type="hidden" name="auto" value="1"><?php endif; ?>
      <div class="form-group">
        <label class="checkbox-inline">
          <input type="checkbox" name="auto" value="1"<?= $auto ? ' checked' : '' ?>>
          Proses otomatis sampai selesai (<?= (int) WP_IMPORT_BATCH_SIZE ?> item per batch)
        </label>
      </div>
      <button type="submit" class="btn btn-primary">
        Proses <?= (int) min(WP_IMPORT_BATCH_SIZE, max(0, $total - $offset)) ?> item berikutnya
      </button>
      <?php if (!$auto): ?>
        <button type="submit" form="wp-import-finish" class="btn">Hentikan &amp; tutup sesi</button>
      <?php endif; ?>
    </form>
  <?php else: ?>
    <div class="alert alert-success">
      Import selesai. <?= (int) ($stats['imported'] ?? 0) ?> entri dibuat,
      <?= (int) ($stats['updated'] ?? 0) ?> diperbarui,
      <?= (int) ($stats['failed'] ?? 0) ?> gagal.
    </div>
    <a class="btn btn-primary" href="<?= e(admin_url('entries?content_type=' . (int) $ct['id'])) ?>">Lihat entri</a>
    <form method="post" action="<?= e(admin_url('import/run')) ?>" style="display:inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="finish">
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <button type="submit" class="btn">Tutup sesi</button>
    </form>
  <?php endif; ?>

  <form method="post" action="<?= e(admin_url('import/run')) ?>" id="wp-import-finish">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="finish">
    <input type="hidden" name="token" value="<?= e($token) ?>">
  </form>
</div>

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
