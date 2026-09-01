<?php
/**
 * Daftar user (superadmin only).
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__, 2));
}
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/permissions.php';
require_once APP_ROOT . '/includes/render.php';
require_once APP_ROOT . '/admin/partials/header.php';
require_once APP_ROOT . '/admin/partials/footer.php';

$user = require_role('superadmin');

$users = get_db()->query(
    'SELECT id, username, email, role, is_active, created_at FROM users ORDER BY created_at DESC, id DESC'
)->fetchAll();

admin_header('Users', 'users');
?>
<div class="page-actions">
  <a class="btn btn-primary" href="<?= e(admin_url('users/create')) ?>">+ Tambah User</a>
</div>
<table class="table">
  <thead>
    <tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Dibuat</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td>#<?= (int) $u['id'] ?></td>
      <td><?= e($u['username']) ?><?= (int) $u['id'] === (int) $user['id'] ? ' <span class="muted">(Anda)</span>' : '' ?></td>
      <td><?= e($u['email']) ?></td>
      <td><?= role_badge($u['role']) ?></td>
      <td><?= $u['is_active'] ? '<span class="badge active">aktif</span>' : '<span class="badge">nonaktif</span>' ?></td>
      <td><?= e($u['created_at']) ?></td>
      <td class="actions">
        <a class="btn btn-small" href="<?= e(admin_url('users/edit?id=' . (int) $u['id'])) ?>">Edit</a>
        <?php if ((int) $u['id'] !== (int) $user['id']): ?>
        <form method="post" action="<?= e(admin_url('users/delete')) ?>" data-confirm="Hapus user <?= e($u['username']) ?>? Tindakan ini tidak bisa dibatalkan." class="inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
          <button type="submit" class="btn btn-small btn-danger">Hapus</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php
admin_footer();
