<?php
/**
 * Halaman login admin.
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/permissions.php';
require_once APP_ROOT . '/includes/settings.php';

if (current_user() !== null) {
    redirect_admin('');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'CSRF token tidak valid. Muat ulang halaman dan coba lagi.';
    } else {
        $identifier = sanitize_text($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        if ($identifier === '' || $password === '') {
            $error = 'Username/email dan password wajib diisi.';
        } else {
            $result = login_user($identifier, $password);
            if ($result === true) {
                flash_set('success', 'Selamat datang kembali!');
                redirect_admin('');
            }
            $error = $result['error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Login — <?= e(get_setting('site_title', 'Ringan CMS')) ?></title>
<?php $_site_favicon = site_favicon_url(); ?>
<?php if ($_site_favicon !== ''): ?>
<link rel="icon" href="<?= e($_site_favicon) ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?= e(BASE_URL . '/admin/assets/css/admin.css') ?>">
</head>
<body class="login-body">
<div class="login-card">
  <div class="login-brand"><?= e(get_setting('site_title', 'Ringan CMS')) ?></div>
  <?php $_site_tagline = get_setting('site_tagline'); ?>
  <?php if ($_site_tagline !== ''): ?>
    <p class="login-tagline"><?= e($_site_tagline) ?></p>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
  <?php endif; ?>
  <form method="post" action="<?= e(admin_url('login')) ?>">
    <?= csrf_field() ?>
    <div class="form-group">
      <label for="username">Username atau Email</label>
      <input type="text" id="username" name="username" required autofocus autocomplete="username">
    </div>
    <div class="form-group">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" required autocomplete="current-password">
    </div>
    <button type="submit" class="btn btn-primary btn-block">Masuk</button>
  </form>
</div>
</body>
</html>
