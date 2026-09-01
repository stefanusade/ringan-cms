<?php
/**
 * Dashboard admin.
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/permissions.php';
require_once APP_ROOT . '/includes/response.php';
require_once APP_ROOT . '/includes/content_types.php';
require_once APP_ROOT . '/includes/settings.php';
require_once APP_ROOT . '/includes/updater.php';
require_once APP_ROOT . '/includes/content_entries.php';
require_once APP_ROOT . '/includes/render.php';
require_once APP_ROOT . '/admin/partials/header.php';
require_once APP_ROOT . '/admin/partials/footer.php';

$user = require_login();

$db = get_db();
$stats = [
    'content_types' => count_content_types(),
    'entries' => (int) $db->query('SELECT COUNT(*) FROM content_entries')->fetchColumn(),
    'entries_published' => (int) $db->query("SELECT COUNT(*) FROM content_entries WHERE status = 'published'")->fetchColumn(),
    'users' => (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'api_keys' => (int) $db->query('SELECT COUNT(*) FROM api_keys WHERE is_active = 1')->fetchColumn(),
];

$recent = $db->query(
    'SELECT ce.*, ct.label AS content_type_label, ct.slug AS content_type_slug
     FROM content_entries ce
     JOIN content_types ct ON ct.id = ce.content_type_id
     ORDER BY ce.updated_at DESC
     LIMIT 10'
)->fetchAll();

admin_header('Dashboard', 'dashboard');
?>
<?php if (is_superadmin($user)): ?>
  <?php $update = is_update_available(); ?>
  <?php if ($update !== null): ?>
    <div class="alert alert-info">
      Versi <strong><?= e($update['version']) ?></strong> tersedia. <a href="<?= e(admin_url('updates')) ?>">Update sekarang →</a>
    </div>
  <?php elseif (update_url_setting() === ''): ?>
    <p class="muted" style="font-size:.85rem">Pemeriksaan pembaruan nonaktif — atur <em>Update URL</em> di <a href="<?= e(admin_url('settings')) ?>">Settings</a>.</p>
  <?php endif; ?>
<?php endif; ?>
<div class="stats-grid">
  <div class="stat"><div class="stat-value"><?= (int) $stats['content_types'] ?></div><div class="stat-label">Content Types</div></div>
  <div class="stat"><div class="stat-value"><?= (int) $stats['entries'] ?></div><div class="stat-label">Total Entries</div></div>
  <div class="stat"><div class="stat-value"><?= (int) $stats['entries_published'] ?></div><div class="stat-label">Published</div></div>
  <div class="stat"><div class="stat-value"><?= (int) $stats['users'] ?></div><div class="stat-label">Users</div></div>
  <?php if (is_superadmin($user)): ?>
    <div class="stat"><div class="stat-value"><?= (int) $stats['api_keys'] ?></div><div class="stat-label">API Keys Aktif</div></div>
  <?php endif; ?>
</div>

<h2 class="section-title">Entri Terbaru</h2>
<?php if ($recent === []): ?>
  <p class="muted">Belum ada entri. <?php if (can_manage_content_types($user)): ?><a href="<?= e(admin_url('content-types')) ?>">Buat content type</a> dulu, lalu tambahkan entri.<?php endif; ?></p>
<?php else: ?>
<table class="table">
  <thead><tr><th>ID</th><th>Content Type</th><th>Preview</th><th>Status</th><th>Diperbarui</th></tr></thead>
  <tbody>
  <?php foreach ($recent as $entry): ?>
    <?php $fields = get_fields((int) $entry['content_type_id']); ?>
    <tr>
      <td>#<?= (int) $entry['id'] ?></td>
      <td><?= e($entry['content_type_label']) ?></td>
      <td><?= entry_preview($entry, $fields) ?></td>
      <td><?= status_badge($entry['status']) ?></td>
      <td><?= e($entry['updated_at']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<?php
admin_footer();
