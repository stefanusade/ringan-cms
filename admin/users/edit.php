<?php
/**
 * Edit user (superadmin only).
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

$id = (int) ($_GET['id'] ?? 0);
$db = get_db();
$stmt = $db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$target = $stmt->fetch();
if ($target === null) {
    http_response_code(404);
    exit('User tidak ditemukan.');
}

$is_self = (int) $target['id'] === (int) $user['id'];
$errors = [];
$form = ['username' => $target['username'], 'email' => $target['email'], 'role' => $target['role'], 'is_active' => (int) $target['is_active']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $form['username'] = sanitize_text($_POST['username'] ?? '');
    $form['email'] = sanitize_text($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $form['role'] = sanitize_text($_POST['role'] ?? $target['role']);
    $form['is_active'] = !empty($_POST['is_active']) ? 1 : 0;

    if (!validate_username($form['username'])) {
        $errors['username'] = 'Username 3–60 karakter (huruf, angka, titik, underscore, strip).';
    }
    if (!validate_email($form['email'])) {
        $errors['email'] = 'Email tidak valid.';
    }
    if ($password !== '' && strlen($password) < 8) {
        $errors['password'] = 'Password minimal 8 karakter.';
    }
    if (!validate_in_enum(['superadmin', 'editor', 'viewer'], $form['role'])) {
        $errors['role'] = 'Role tidak valid.';
    }
    if ($is_self && (!$form['is_active'] || $form['role'] !== 'superadmin')) {
        $errors['self'] = 'Anda tidak bisa menonaktifkan diri sendiri atau mengubah role sendiri.';
    }

    $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE (username = ? OR email = ?) AND id <> ?');
    $stmt->execute([$form['username'], $form['email'], $id]);
    if ((int) $stmt->fetchColumn() > 0) {
        $errors['duplicate'] = 'Username atau email sudah dipakai user lain.';
    }

    if ($errors === []) {
        $params = [$form['username'], $form['email'], $form['role'], $form['is_active']];
        $sql = 'UPDATE users SET username = ?, email = ?, role = ?, is_active = ?';
        if ($password !== '') {
            $sql .= ', password_hash = ?';
            $params[] = hash_password($password);
        }
        $sql .= ' WHERE id = ?';
        $params[] = $id;
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        log_audit('update_user', 'users', (string) $id, (int) $user['id']);
        flash_set('success', 'User diperbarui.');
        redirect_admin('users');
    }
}

admin_header('Edit User: ' . $form['username'], 'users');
?>
<form method="post" action="<?= e(admin_url('users/edit?id=' . $id)) ?>" class="card">
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
    <label for="password">Password Baru (kosongkan jika tidak diganti)</label>
    <input type="password" id="password" name="password" autocomplete="new-password">
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
