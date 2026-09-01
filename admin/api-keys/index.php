<?php
/**
 * Daftar API keys (superadmin only).
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__, 2));
}
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/permissions.php';
require_once APP_ROOT . '/admin/partials/header.php';
require_once APP_ROOT . '/admin/partials/footer.php';

$user = require_role('superadmin');

$keys = get_db()->query(
    'SELECT id, label, scope, content_type_restriction, is_active, last_used_at, created_at FROM api_keys ORDER BY created_at DESC, id DESC'
)->fetchAll();

admin_header('API Keys', 'api-keys');
?>
<div class="page-actions">
  <a class="btn btn-primary" href="<?= e(admin_url('api-keys/create')) ?>">+ Buat API Key</a>
</div>
<?php if ($keys === []): ?>
  <p class="muted">Belum ada API key. Buat satu agar frontend (Astro/JS) bisa mengakses REST API.</p>
<?php else: ?>
<table class="table">
  <thead>
    <tr><th>Label</th><th>Scope</th><th>Restriksi</th><th>Status</th><th>Terakhir Dipakai</th><th>Dibuat</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($keys as $k): ?>
    <?php
    $restriction = json_decode($k['content_type_restriction'] ?? 'null', true);
    $restriction_text = is_array($restriction) && $restriction !== []
        ? implode(', ', array_map('e', $restriction))
        : '<em>semua content type</em>';
    ?>
    <tr>
      <td><?= e($k['label']) ?></td>
      <td><?= $k['scope'] === 'read_write' ? '<span class="badge">read_write</span>' : '<span class="badge">read</span>' ?></td>
      <td><?= $restriction_text ?></td>
      <td><?= $k['is_active'] ? '<span class="badge active">aktif</span>' : '<span class="badge">nonaktif</span>' ?></td>
      <td><?= $k['last_used_at'] !== null ? e($k['last_used_at']) : '<span class="muted">belum pernah</span>' ?></td>
      <td><?= e($k['created_at']) ?></td>
      <td class="actions">
        <?php if ($k['is_active']): ?>
        <form method="post" action="<?= e(admin_url('api-keys/revoke')) ?>" data-confirm="Nonaktifkan API key <?= e($k['label']) ?>? Frontend yang memakainya akan kehilangan akses." class="inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
          <button type="submit" class="btn btn-small btn-danger">Revoke</button>
        </form>
        <?php else: ?>
          <span class="muted">—</span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<?php
admin_footer();
