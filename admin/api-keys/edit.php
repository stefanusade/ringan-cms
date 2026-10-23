<?php
/**
 * Edit API key (superadmin only). Secret TIDAK berubah di sini —
 * gunakan tombol Rotate untuk membuat secret baru.
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
require_once APP_ROOT . '/includes/validation.php';
require_once APP_ROOT . '/includes/content_types.php';
require_once APP_ROOT . '/includes/audit_log.php';
require_once APP_ROOT . '/admin/partials/header.php';
require_once APP_ROOT . '/admin/partials/footer.php';

$user = require_role('superadmin');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = get_db()->prepare('SELECT * FROM api_keys WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$key = $stmt->fetch();
if ($key === null) {
    flash_set('error', 'API key tidak ditemukan.');
    redirect_admin('api-keys');
}

$restriction = json_decode($key['content_type_restriction'] ?? 'null', true);
$form = [
    'label' => (string) $key['label'],
    'scope' => (string) $key['scope'],
    'all_types' => $key['content_type_restriction'] === null ? 1 : 0,
    'content_types' => is_array($restriction) ? array_map('strval', $restriction) : [],
    'is_active' => (int) $key['is_active'],
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $form['label'] = sanitize_text($_POST['label'] ?? '');
    $form['scope'] = sanitize_text($_POST['scope'] ?? 'read');
    $form['all_types'] = !empty($_POST['all_types']) ? 1 : 0;
    $form['content_types'] = array_map('sanitize_text', (array) ($_POST['content_types'] ?? []));
    $form['is_active'] = !empty($_POST['is_active']) ? 1 : 0;

    if (trim($form['label']) === '') {
        $errors['label'] = 'Label wajib diisi.';
    }
    if (!validate_in_enum(['read', 'read_write'], $form['scope'])) {
        $errors['scope'] = 'Scope tidak valid.';
    }

    $all_slugs = array_map(fn(array $ct): string => (string) $ct['slug'], get_content_types());
    foreach ($form['content_types'] as $slug) {
        if (!in_array($slug, $all_slugs, true)) {
            $errors['content_types'] = 'Restriksi content type tidak valid.';
            break;
        }
    }

    if ($errors === []) {
        $restriction_value = $form['all_types']
            ? null
            : json_encode(array_values(array_unique($form['content_types'])), JSON_UNESCAPED_SLASHES);
        $update = get_db()->prepare(
            'UPDATE api_keys SET label = ?, scope = ?, content_type_restriction = ?, is_active = ? WHERE id = ?'
        );
        $update->execute([
            $form['label'],
            $form['scope'],
            $restriction_value,
            $form['is_active'],
            $id,
        ]);
        log_audit('update_api_key', 'api_keys', (string) $id, (int) $user['id']);
        flash_set('success', 'API key "' . $form['label'] . '" diperbarui. Secret tidak berubah — gunakan Rotate untuk membuat secret baru.');
        redirect_admin('api-keys');
    }
}

$types = get_content_types();

admin_header('Edit API Key', 'api-keys');
?>
<p class="muted">Secret key tidak dapat ditampilkan kembali. Gunakan tombol <strong>Rotate</strong> di daftar API Keys untuk membuat secret baru.</p>
<form method="post" action="<?= e(admin_url('api-keys/edit?id=' . $id)) ?>" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $id ?>">
  <?= render_errors($errors) ?>
  <div class="form-group">
    <label for="label">Label</label>
    <input type="text" id="label" name="label" value="<?= e($form['label']) ?>" required>
  </div>
  <div class="form-group">
    <label for="scope">Scope</label>
    <select id="scope" name="scope">
      <option value="read"<?= $form['scope'] === 'read' ? ' selected' : '' ?>>Read only</option>
      <option value="read_write"<?= $form['scope'] === 'read_write' ? ' selected' : '' ?>>Read + Write</option>
    </select>
  </div>
  <div class="form-group">
    <label class="checkbox-inline"><input type="checkbox" name="is_active" value="1"<?= $form['is_active'] ? ' checked' : '' ?>> Key aktif</label>
  </div>
  <div class="form-group">
    <label class="checkbox-inline"><input type="checkbox" name="all_types" value="1" id="all_types"<?= $form['all_types'] ? ' checked' : '' ?>> Akses ke semua content type</label>
  </div>
  <div class="form-group" id="restriction_box"<?= $form['all_types'] ? ' style="display:none"' : '' ?>>
    <label>Batasi ke content type tertentu:</label>
    <?php foreach ($types as $ct): ?>
      <label class="checkbox-inline">
        <input type="checkbox" name="content_types[]" value="<?= e($ct['slug']) ?>"<?= in_array($ct['slug'], $form['content_types'], true) ? ' checked' : '' ?>> <?= e($ct['label']) ?>
      </label>
    <?php endforeach; ?>
    <small class="hint">Jika tidak ada yang dicentang, key tidak punya akses ke content type mana pun.</small>
  </div>
  <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
  <a class="btn" href="<?= e(admin_url('api-keys')) ?>">Batal</a>
</form>
<?php
admin_footer();
