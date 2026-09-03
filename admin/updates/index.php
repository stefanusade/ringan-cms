<?php
/**
 * Halaman pembaruan Ringan CMS (superadmin only).
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
require_once APP_ROOT . '/includes/updater.php';
require_once APP_ROOT . '/includes/audit_log.php';
require_once APP_ROOT . '/admin/partials/header.php';
require_once APP_ROOT . '/admin/partials/footer.php';

$user = require_role('superadmin');

$result = null;
$manifest = is_update_available();
$history = get_update_history();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $action = sanitize_text($_POST['action'] ?? '');
    if ($action === 'do_update') {
        log_audit('start_update', 'system', null, (int) $user['id']);
        $result = do_update();
        $manifest = is_update_available();
    } elseif ($action === 'check_now') {
        clear_update_cache();
        $manifest = is_update_available(true);
        $history = get_update_history(true);
        flash_set('info', 'Pemeriksaan pembaruan selesai.');
    }
}

admin_header('Pembaruan', 'updates');
?>
<p class="muted">Versi terpasang: <strong><?= e(cms_version()) ?></strong></p>

<?php if ($result !== null): ?>
  <?php if ($result['ok']): ?>
    <div class="alert alert-success">
      <strong><?= e($result['message']) ?></strong>
      <?php if (!empty($result['backup'])): ?>
        <br>Backup otomatis: <code><?= e(basename($result['backup'])) ?></code> di <code>storage/backups/</code>. Hapus setelah dipastikan stabil.
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="alert alert-error"><?= e($result['message']) ?></div>
  <?php endif; ?>
  <?php if (!empty($result['migrations'])): ?>
    <h2 class="section-title">Hasil Migrasi Database</h2>
    <table class="table"><thead><tr><th>File</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($result['migrations'] as $m): ?>
      <tr><td><?= e($m['file']) ?></td><td><?= $m['ok'] ? '<span class="check-ok">OK</span>' : '<span class="check-bad">' . e($m['error']) . '</span>' ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
<?php endif; ?>

<div class="card">
  <h2 class="section-title">Status Pembaruan</h2>
  <?php if (update_url_setting() === ''): ?>
    <p class="muted">Pemeriksaan pembaruan <strong>nonaktif</strong> — atur <em>Update URL</em> di halaman Settings untuk mengaktifkannya.</p>
    <p class="hint">Format manifest: <code>{ "version": "1.2.0", "url": "https://…/paket.zip", "checksum": "sha256-hex", "changelog": "…" }</code></p>
  <?php elseif ($manifest !== null): ?>
    <div class="alert alert-info">
      <strong>Versi <?= e($manifest['version']) ?> tersedia.</strong>
      <?php if ($manifest['changelog'] !== ''): ?>
        <div style="margin-top:8px; white-space:pre-wrap;"><?= e($manifest['changelog']) ?></div>
      <?php endif; ?>
    </div>
    <form method="post" action="<?= e(admin_url('updates')) ?>" data-confirm="Mulai update ke versi <?= e($manifest['version']) ?>? Backup otomatis dibuat sebelum update. Jangan tutup halaman selama proses berjalan.">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="do_update">
      <button type="submit" class="btn btn-primary">Update Sekarang ke v<?= e($manifest['version']) ?></button>
    </form>
  <?php else: ?>
    <p class="muted">Anda sudah memakai versi terbaru (<?= e(cms_version()) ?>).</p>
  <?php endif; ?>
  <form method="post" action="<?= e(admin_url('updates')) ?>" style="margin-top:16px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="check_now">
    <button type="submit" class="btn">Periksa Ulang Sekarang</button>
  </form>
</div>

<div class="card">
  <h2 class="section-title">What's New / Changelog</h2>
  <?php if ($history !== []): ?>
    <?php foreach ($history as $entry): ?>
      <?php $entry_new = version_compare($entry['version'], cms_version(), '>'); ?>
      <div class="changelog-entry<?= $entry_new ? ' is-new' : '' ?>">
        <div class="changelog-head">
          <strong>v<?= e($entry['version']) ?></strong>
          <?php if ($entry_new): ?>
            <span class="badge">Baru</span>
          <?php endif; ?>
          <?php if ($entry['prerelease']): ?>
            <span class="badge">prerelease</span>
          <?php endif; ?>
          <span class="muted">— <?= e(substr($entry['date'], 0, 10)) ?></span>
        </div>
        <?php if ($entry['body'] !== ''): ?>
          <div class="changelog-body"><?= render_changelog_markdown($entry['body']) ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php elseif ($manifest !== null && $manifest['changelog'] !== ''): ?>
    <div class="changelog-body"><?= nl2br(e($manifest['changelog'])) ?></div>
  <?php else: ?>
    <p class="muted">Riwayat rilis tidak tersedia (update nonaktif atau manifest kustom tanpa changelog).</p>
  <?php endif; ?>
</div>
<?php
admin_footer();
