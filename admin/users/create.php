<?php
/**
 * Buat user baru (superadmin only).
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
require_once APP_ROOT . '/includes/audit_log.php';
require_once APP_ROOT . '/admin/partials/header.php';
require_once APP_ROOT . '/admin/partials/footer.php';

$user = require_role('superadmin');

$errors = [];
$form = ['username' => '', 'email' => '', 'role' => 'editor', 'is_active' => 1];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $form['username'] = sanitize_text($_POST['username'] ?? '');
    $form['email'] = sanitize_text($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $form['role'] = sanitize_text($_POST['role'] ?? 'editor');
    $form['is_active'] = !empty($_POST['is_active']) ? 1 : 0;

    if (!validate_username($form['username'])) {
        $errors['username'] = 'Username 3–60 karakter (huruf, angka, titik, underscore, strip).';
    }
    if (!validate_email($form['email'])) {
        $errors['email'] = 'Email tidak valid.';
    }
    if (strlen($password) < 8) {
        $errors['password'] = 'Password minimal 8 karakter.';
    }
    if (!validate_in_enum(['superadmin', 'editor', 'viewer'], $form['role'])) {
        $errors['role'] = 'Role tidak valid.';
    }

    $db = get_db();
    $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ? OR email = ?');
    $stmt->execute([$form['username'], $form['email']]);
    if ((int) $stmt->fetchColumn() > 0) {
        $errors['duplicate'] = 'Username atau email sudah dipakai.';
    }

    if ($errors === []) {
        $stmt = $db->prepare('INSERT INTO users (username, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$form['username'], $form['email'], hash_password($password), $form['role'], $form['is_active']]);
        $new_id = (int) $db->lastInsertId();
        log_audit('create_user', 'users', (string) $new_id, (int) $user['id']);
        flash_set('success', 'User ' . $form['username'] . ' dibuat.');
        redirect_admin('users');
    }
}

admin_header('Tambah User', 'users');
?>
<form method="post" action="<?= e(admin_url('users/create')) ?>" class="card">
  <?= csrf_field() ?>
  <?= render_errors($errors) ?>
  <div class="form-group">
    <label for="username">Username</label>
    <input type="text" id="username" name="username" value="<?= e($form['username']) ?>" required>
  </div>
  <div class="form-group">
    <label for="email">Email</label>
    <input type="email" id="email" name="email" value="<?= e($form['email']) ?>" required>
  </div>
  <div class="form-group">
    <label for="password">Password</label>
    <input type="password" id="password" name="password" required autocomplete="new-password">
  </div>
  <div class="form-group">
    <label for="role">Role</label>
    <select id="role" name="role">
      <option value="editor"<?= $form['role'] === 'editor' ? ' selected' : '' ?>>Editor (kelola konten)</option>
      <option value="viewer"<?= $form['role'] === 'viewer' ? ' selected' : '' ?>>Viewer (baca saja)</option>
      <option value="superadmin"<?= $form['role'] === 'superadmin' ? ' selected' : '' ?>>Superadmin</option>
    </select>
  </div>
  <div class="form-group">
    <label class="checkbox-inline"><input type="checkbox" name="is_active" value="1"<?= $form['is_active'] ? ' checked' : '' ?>> Aktif</label>
  </div>
  <button type="submit" class="btn btn-primary">Simpan</button>
  <a class="btn" href="<?= e(admin_url('users')) ?>">Batal</a>
</form>
<?php
admin_footer();
