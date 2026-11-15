<?php
/**
 * Editor profil JSON per content type (kontrak ekspor plugin Ringan Export).
 *
 * Profil menjelaskan pemetaan field CMS → sumber WordPress (post.* / acf.*)
 * dan ditanamkan ke manifest ZIP saat plugin melakukan ekspor.
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
require_once APP_ROOT . '/includes/content_entries.php';
require_once APP_ROOT . '/includes/validation.php';
require_once APP_ROOT . '/includes/audit_log.php';
require_once APP_ROOT . '/includes/export_profile.php';
require_once APP_ROOT . '/admin/partials/header.php';
require_once APP_ROOT . '/admin/partials/footer.php';

const EXPORT_PROFILE_MAX_JSON_BYTES = 262144;

$user = require_login();

if (!can_manage_content_types($user)) {
    http_response_code(403);
    exit('403 — Hanya superadmin yang bisa mengelola profil ekspor.');
}

$ct_id = (int) ($_GET['content_type'] ?? $_POST['content_type'] ?? 0);
$ct = $ct_id > 0 ? get_content_type($ct_id) : null;
if ($ct === null) {
    flash_set('error', 'Content type tidak ditemukan.');
    redirect_admin('content-types');
}

$fields = get_fields($ct_id);
$default_profile = export_profile_default($ct, $fields);
$default_json = export_profile_json($default_profile);
$saved_profile = export_profile_get_saved($ct_id);
$storage_available = export_profiles_available();
$saved_errors = [];
if ($saved_profile !== null) {
    $saved_check = export_profile_validate($saved_profile, $ct, $fields);
    if (!$saved_check['ok']) {
        $saved_errors = array_slice($saved_check['errors'], 0, 10);
    }
}

$errors = [];
$warnings = [];
$submitted_json = null;

$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? sanitize_text($_POST['action'] ?? '') : '';
$download = isset($_GET['download']) && $_GET['download'] === '1';

/* ===== Unduh profil (tanpa output HTML lebih dulu) ===== */
if ($download) {
    $profile = export_profile_effective($ct_id);
    if ($profile === null) {
        http_response_code(404);
        exit('404 — Profil tidak tersedia.');
    }
    $filename = 'ringan-profile-' . preg_replace('/[^a-z0-9_-]/', '-', (string) $ct['slug']) . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo export_profile_json($profile);
    exit;
}

/* ===== Simpan profil dari editor ===== */
if ($action === 'save') {
    require_csrf();
    if (!$storage_available) {
        $errors[] = 'Penyimpanan profil belum aktif. Jalankan migrasi database/migrations/011_export_profiles.sql.';
    }
    $raw = is_string($_POST['profile_json'] ?? null) ? (string) $_POST['profile_json'] : '';
    $submitted_json = $raw;
    if ($raw === '') {
        $errors[] = 'JSON profil masih kosong.';
    } elseif (strlen($raw) > EXPORT_PROFILE_MAX_JSON_BYTES) {
        $errors[] = 'JSON profil terlalu besar (maks ' . (int) (EXPORT_PROFILE_MAX_JSON_BYTES / 1024) . ' KB).';
    }
    if ($errors === []) {
        $decoded = json_decode($raw, true, 64);
        if (!is_array($decoded)) {
            $errors[] = 'JSON tidak valid: ' . json_last_error_msg();
        } else {
            $validated = export_profile_validate($decoded, $ct, $fields);
            if (!$validated['ok']) {
                $errors = array_merge($errors, array_slice($validated['errors'], 0, 20));
            } elseif (export_profile_save($ct_id, $validated['profile'], (int) $user['id'])) {
                log_audit('save_export_profile', 'content_types', (string) $ct_id, (int) $user['id']);
                $message = 'Profil ekspor untuk "' . $ct['label'] . '" disimpan.';
                $added = array_slice((array) $validated['warnings'], 0, 3);
                if ($added !== []) {
                    $message .= ' Catatan: ' . implode(' ', $added);
                }
                flash_set('success', $message);
                redirect_admin('content-types/profile?content_type=' . $ct_id);
            } else {
                $errors[] = 'Gagal menyimpan profil. Periksa log aplikasi.';
            }
        }
    }
}

/* ===== Kembalikan ke profil default ===== */
if ($action === 'reset') {
    require_csrf();
    if (!$storage_available) {
        $errors[] = 'Penyimpanan profil belum aktif. Jalankan migrasi database/migrations/011_export_profiles.sql.';
    } elseif (export_profile_delete($ct_id)) {
        log_audit('reset_export_profile', 'content_types', (string) $ct_id, (int) $user['id']);
        flash_set('success', 'Profil ekspor dikembalikan ke default (dibuat dari definisi field).');
        redirect_admin('content-types/profile?content_type=' . $ct_id);
    } else {
        $errors[] = 'Gagal menghapus profil tersimpan.';
    }
}

$effective = $saved_profile !== null ? export_profile_effective($ct_id) : $default_profile;
$editor_json = $submitted_json ?? export_profile_json($effective ?? $default_profile);
$effective_fields = is_array($effective['fields'] ?? null) ? $effective['fields'] : [];
$source_choices = EXPORT_PROFILE_POST_SOURCES;

admin_header('Profil Ekspor — ' . (string) $ct['label'], 'content-types');
?>
<div class="page-actions">
  <a class="btn" href="<?= e(admin_url('content-types')) ?>">← Content Types</a>
  <a class="btn" href="<?= e(admin_url('content-types/fields?content_type=' . $ct_id)) ?>">Fields</a>
  <a class="btn" href="<?= e(admin_url('import/zip')) ?>">Import ZIP</a>
  <a class="btn" href="<?= e(admin_url('content-types/profile?content_type=' . $ct_id . '&download=1')) ?>">Unduh JSON</a>
</div>

<div class="card">
  <h2>Profil JSON: <?= e((string) $ct['label']) ?></h2>
  <p class="muted">
    Content type <code><?= e((string) $ct['slug']) ?></code> · <?= count($fields) ?> field ·
    Status: <?= $saved_profile !== null
        ? '<span class="badge active">profil tersimpan</span>'
        : '<span class="badge">default (belum disimpan)</span>' ?>
  </p>
  <p class="muted">
    Salin JSON di bawah ke halaman <strong>Ringan Export</strong> di WordPress.
    Plugin memakai profil ini untuk menentukan post type, field ACF, dan media yang
    diekspor. Setelah ekspor selesai, unggah ZIP-nya lewat
    <a href="<?= e(admin_url('import/zip')) ?>">Tools → Import ZIP</a>.
  </p>
  <?= render_errors($errors) ?>

  <?php if (!$storage_available): ?>
    <div class="alert alert-warning">
      Tabel <code>export_profiles</code> belum tersedia. Jalankan
      <code>database/migrations/011_export_profiles.sql</code> (atau Update dari dashboard)
      agar profil bisa disimpan. Profil default tetap dapat diunduh dan disalin.
    </div>
  <?php endif; ?>
  <?php if ($saved_errors !== []): ?>
    <div class="alert alert-warning">
      <strong>Profil tersimpan sudah tidak cocok dengan definisi field saat ini</strong>
      — halaman ini menampilkan profil default sampai Anda menyimpan perbaikan:
      <ul>
        <?php foreach ($saved_errors as $saved_error): ?>
          <li><?= e($saved_error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="wp-import-scroll">
    <table class="table">
      <thead><tr><th>Field CMS</th><th>Tipe</th><th>Wajib</th><th>Sumber WordPress</th><th>Transform</th><th>Opsi</th></tr></thead>
      <tbody>
        <?php foreach ($effective_fields as $profile_field): ?>
          <tr>
            <td><strong><?= e((string) ($profile_field['label'] ?? $profile_field['key'] ?? '')) ?></strong><br><code><?= e((string) ($profile_field['key'] ?? '')) ?></code></td>
            <td><code><?= e((string) ($profile_field['type'] ?? '')) ?></code></td>
            <td><?= !empty($profile_field['required']) ? 'Ya' : '—' ?></td>
            <td><code><?= e((string) ($profile_field['source'] ?? 'none')) ?></code></td>
            <td><code><?= e((string) ($profile_field['transform'] ?? 'auto')) ?></code></td>
            <td class="muted"><code><?= e((string) json_encode($profile_field['options'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></code></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <details class="hint">
    <summary>Sumber WordPress yang dikenal</summary>
    <ul>
      <?php foreach ($source_choices as $source_key => $source_label): ?>
        <li><code><?= e($source_key) ?></code> — <?= e($source_label) ?></li>
      <?php endforeach; ?>
      <li><code>acf.&lt;key&gt;</code> — nilai field ACF (meta) dengan key tersebut.</li>
      <li><code>none</code> — field dilewati saat ekspor.</li>
    </ul>
  </details>
</div>

<div class="card">
  <h2>Editor JSON</h2>
  <form method="post" action="<?= e(admin_url('content-types/profile?content_type=' . $ct_id)) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="content_type" value="<?= $ct_id ?>">
    <div class="form-group">
      <label for="profile_json">Profil (JSON)</label>
      <textarea id="profile_json" name="profile_json" rows="24" spellcheck="false" class="code-input" required><?= e($editor_json) ?></textarea>
      <p class="hint">
        Perubahan hanya diizinkan untuk field yang ada di content type ini.
        Field yang belum tercantum akan ditambahkan otomatis dengan sumber default.
      </p>
    </div>
    <button type="submit" class="btn btn-primary"<?= $storage_available ? '' : ' disabled' ?>>Simpan Profil</button>
    <button type="submit" form="profile-reset" class="btn"<?= ($storage_available && $saved_profile !== null) ? '' : ' disabled' ?>>Kembalikan ke Default</button>
  </form>
  <form method="post" action="<?= e(admin_url('content-types/profile?content_type=' . $ct_id)) ?>" id="profile-reset" data-confirm="Hapus profil tersimpan dan kembali ke profil default?">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reset">
    <input type="hidden" name="content_type" value="<?= $ct_id ?>">
  </form>
  <details class="hint">
    <summary>Profil default (hasil generate dari definisi field)</summary>
    <pre class="code-block"><?= e($default_json) ?></pre>
  </details>
</div>
<?php
admin_footer();