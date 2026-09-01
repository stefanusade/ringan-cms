<?php
/**
 * Buat API key (superadmin only). Key asli hanya ditampilkan SEKALI.
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
require_once APP_ROOT . '/includes/content_types.php';
require_once APP_ROOT . '/includes/audit_log.php';
require_once APP_ROOT . '/admin/partials/header.php';
require_once APP_ROOT . '/admin/partials/footer.php';

$user = require_role('superadmin');

$errors = [];
$form = ['label' => '', 'scope' => 'read', 'all_types' => 1, 'content_types' => []];
$key_plain = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $form['label'] = sanitize_text($_POST['label'] ?? '');
    $form['scope'] = sanitize_text($_POST['scope'] ?? 'read');
    $form['all_types'] = !empty($_POST['all_types']) ? 1 : 0;
    $form['content_types'] = array_map('sanitize_text', (array) ($_POST['content_types'] ?? []));

    if (trim($form['label']) === '') {
        $errors['label'] = 'Label wajib diisi.';
    }
    if (!validate_in_enum(['read', 'read_write'], $form['scope'])) {
        $errors['scope'] = 'Scope tidak valid.';
    }

    $all_slugs = array_map(fn($ct) => $ct['slug'], get_content_types());
    foreach ($form['content_types'] as $slug) {
        if (!in_array($slug, $all_slugs, true)) {
            $errors['content_types'] = 'Restriksi content type tidak valid.';
            break;
        }
    }

    if ($errors === []) {
        $key_plain = 'rcm_' . bin2hex(random_bytes(24));
        $key_hash = hash('sha256', $key_plain);
        $restriction = $form['all_types'] ? null : $form['content_types'];
        $stmt = get_db()->prepare(
            'INSERT INTO api_keys (label, key_hash, scope, content_type_restriction, is_active, created_by)
             VALUES (?, ?, ?, ?, 1, ?)'
        );
        $stmt->execute([
            $form['label'],
            $key_hash,
            $form['scope'],
            $restriction === null ? null : json_encode($restriction, JSON_UNESCAPED_SLASHES),
            (int) $user['id'],
        ]);
        $key_id = (int) get_db()->lastInsertId();
        log_audit('create_api_key', 'api_keys', (string) $key_id, (int) $user['id']);
    }
}

$types = get_content_types();

admin_header('Buat API Key', 'api-keys');
?>
<?php if ($key_plain !== null): ?>
<div class="alert alert-success">
  <strong>API key berhasil dibuat!</strong><br>
  Salin key di bawah ini — <strong>tidak akan ditampilkan lagi</strong>. Simpan di tempat aman.
  <pre class="key-display"><?= e($key_plain) ?></pre>
</div>
<?php endif; ?>
<form method="post" action="<?= e(admin_url('api-keys/create')) ?>" class="card">
  <?= csrf_field() ?>
  <?= render_errors($errors) ?>
  <div class="form-group">
    <label for="label">Label</label>
    <input type="text" id="label" name="label" value="<?= e($form['label']) ?>" required placeholder="contoh: Frontend Astro Produksi">
  </div>
  <div class="form-group">
    <label for="scope">Scope</label>
    <select id="scope" name="scope">
      <option value="read"<?= $form['scope'] === 'read' ? ' selected' : '' ?>>Read only</option>
      <option value="read_write"<?= $form['scope'] === 'read_write' ? ' selected' : '' ?>>Read + Write</option>
    </select>
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
  </div>
  <button type="submit" class="btn btn-primary">Buat API Key</button>
  <a class="btn" href="<?= e(admin_url('api-keys')) ?>">Batal</a>
</form>
<?php
admin_footer();
