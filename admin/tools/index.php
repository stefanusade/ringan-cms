<?php
/**
 * Tools — kumpulan utilitas admin.
 *
 * Tambahkan utilitas baru ke array $tools; menu Tools di sidebar otomatis
 * menampung submenu-nya (lihat admin_menu_items()).
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
require_once APP_ROOT . '/admin/partials/header.php';
require_once APP_ROOT . '/admin/partials/footer.php';

$user = require_login();

$tools = [];
if (can_write_entries($user)) {
    $tools[] = [
        'title' => 'WordPress Import',
        'description' => 'Impor post, halaman, dan meta ACF dari file WXR ke content type pilihan. '
            . 'Mapping field diverifikasi sebelum eksekusi, media dapat diunduh otomatis, '
            . 'dan import ulang tidak menduplikasi entri.',
        'url' => admin_url('import'),
        'button' => 'Buka Import',
    ];
}

admin_header('Tools', 'tools');
?>
<h2 class="section-title">Utilitas</h2>
<?php if ($tools === []): ?>
  <p class="muted">Belum ada tool yang tersedia untuk role Anda.</p>
<?php else: ?>
  <div class="grid-2">
    <?php foreach ($tools as $tool): ?>
      <div class="card">
        <h3><?= e($tool['title']) ?></h3>
        <p class="muted"><?= e($tool['description']) ?></p>
        <a class="btn btn-primary" href="<?= e($tool['url']) ?>"><?= e($tool['button']) ?></a>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php
admin_footer();
