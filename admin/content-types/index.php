<?php
/**
 * Daftar content types (superadmin only).
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__, 2));
}
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/permissions.php';
require_once APP_ROOT . '/includes/content_types.php';
require_once APP_ROOT . '/admin/partials/header.php';
require_once APP_ROOT . '/admin/partials/footer.php';

$user = require_login();

if (!can_manage_content_types($user)) {
    http_response_code(403);
    exit('403 — Hanya superadmin yang bisa mengelola content types.');
}

$types = get_content_types();

admin_header('Content Types', 'content-types');
?>
<div class="page-actions">
  <a class="btn btn-primary" href="<?= e(admin_url('content-types/create')) ?>">+ Buat Content Type</a>
</div>
<?php if ($types === []): ?>
  <p class="muted">Belum ada content type. Buat satu untuk mulai mendefinisikan struktur konten (mirip Custom Post Type + field ACF).</p>
<?php else: ?>
<table class="table">
  <thead>
    <tr><th>Label</th><th>Slug</th><th>Fields</th><th>Entries</th><th>API</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($types as $ct): ?>
    <tr>
      <td><?= e($ct['label']) ?></td>
      <td><code><?= e($ct['slug']) ?></code></td>
      <td><?= (int) $ct['field_count'] ?></td>
      <td><?= (int) $ct['entry_count'] ?></td>
      <td><?= $ct['is_api_enabled'] ? '<span class="badge active">aktif</span>' : '<span class="badge">mati</span>' ?></td>
      <td class="actions">
        <a class="btn btn-small" href="<?= e(admin_url('entries?content_type=' . (int) $ct['id'])) ?>">Entries</a>
        <a class="btn btn-small" href="<?= e(admin_url('content-types/fields?content_type=' . (int) $ct['id'])) ?>">Fields</a>
        <a class="btn btn-small" href="<?= e(admin_url('content-types/taxonomies?content_type=' . (int) $ct['id'])) ?>">Taxonomies</a>
        <a class="btn btn-small" href="<?= e(admin_url('content-types/edit?id=' . (int) $ct['id'])) ?>">Edit</a>
        <form method="post" action="<?= e(admin_url('content-types/delete')) ?>" data-confirm="Hapus content type <?= e($ct['label']) ?> beserta SEMUA field dan entri-nya?" class="inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $ct['id'] ?>">
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
