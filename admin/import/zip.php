<?php
/**
 * Import ZIP dari plugin WordPress "Ringan Export".
 * Upload → validasi manifest/profil → pratinjau → import bertahap.
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
require_once APP_ROOT . '/includes/upload.php';
require_once APP_ROOT . '/includes/media.php';
require_once APP_ROOT . '/includes/taxonomies.php';
require_once APP_ROOT . '/includes/audit_log.php';
require_once APP_ROOT . '/includes/export_profile.php';
require_once APP_ROOT . '/includes/wordpress_import.php';
require_once APP_ROOT . '/includes/zip_import.php';
require_once APP_ROOT . '/admin/partials/header.php';
require_once APP_ROOT . '/admin/partials/footer.php';

$user = require_login();
if (!can_write_entries($user)) {
    http_response_code(403);
    exit('403 — Anda tidak memiliki akses untuk mengimport entri.');
}

zip_import_cleanup();
wp_import_ensure_table();

$action = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? sanitize_text($_POST['action'] ?? '')
    : (isset($_GET['resume']) ? 'resume' : '');

$state = zip_import_state_get();
$manifest = null;
$summary = null;
$ct = null;
$profile = null;
$errors = [];
$view = 'upload';

if ($action === 'cancel') {
    require_csrf();
    $token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
    if ($state !== null && zip_import_state_matches($state, $token)) {
        zip_import_destroy($token);
    }
    flash_set('success', 'Sesi import ZIP dibatalkan.');
    redirect_admin('import/zip');
}

if ($action === 'analyze') {
    require_csrf();
    $stored = zip_import_store_upload($_FILES['zip'] ?? [], (int) $user['id']);
    if (!$stored['ok']) {
        $errors[] = $stored['error'];
    } else {
        $manifest = zip_import_load_manifest((string) $stored['token']);
        $ct = get_content_type((int) $stored['ct_id']);
        if ($manifest === null || $ct === null) {
            $errors[] = 'Gagal membaca data ZIP hasil upload. Silakan ulangi.';
            zip_import_remove_dir(zip_import_token_path((string) $stored['token']));
        } else {
            $profile = (array) $stored['profile'];
            $state = [
                'token' => (string) $stored['token'],
                'ct_id' => (int) $stored['ct_id'],
                'ct_slug' => (string) $stored['ct_slug'],
                'ct_label' => (string) $stored['ct_label'],
                'profile' => $profile,
                'source_site' => (string) $stored['source_site'],
                'site_name' => (string) $stored['site_name'],
                'warnings' => (array) $stored['warnings'],
                'options' => [
                    'duplicates' => (string) ($profile['options']['duplicates'] ?? 'skip'),
                    'fallback_draft' => true,
                    'import_terms' => true,
                    'create_terms' => false,
                    'force_draft' => false,
                ],
                'offset' => 0,
                'media_cache' => [],
                'stats' => [
                    'imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0,
                    'downgraded' => 0, 'failures' => [], 'warnings' => [],
                ],
                'created_at' => time(),
                'finished' => false,
            ];
            zip_import_state_start($state);
            $summary = zip_import_summary($manifest);
            $view = 'preview';
        }
    }
}

if ($action === 'resume') {
    if ($state === null) {
        $errors[] = 'Tidak ada sesi import ZIP aktif. Silakan upload file ZIP.';
    } else {
        $manifest = zip_import_load_manifest((string) $state['token']);
        $ct = get_content_type((int) $state['ct_id']);
        if ($manifest === null || $ct === null) {
            zip_import_destroy((string) $state['token']);
            $errors[] = 'Sesi import ZIP sudah kedaluwarsa. Silakan upload ulang.';
            $state = null;
        } else {
            $profile = is_array($state['profile'] ?? null) ? $state['profile'] : [];
            $summary = zip_import_summary($manifest);
            $view = 'preview';
        }
    }
}

if ($action === 'start') {
    require_csrf();
    $token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
    if ($state === null || !zip_import_state_matches($state, $token)) {
        $errors[] = 'Sesi import ZIP tidak valid atau sudah berakhir. Silakan upload ulang.';
    } else {
        $manifest = zip_import_load_manifest($token);
        $ct = get_content_type((int) $state['ct_id']);
        if ($manifest === null || $ct === null) {
            zip_import_destroy($token);
            $errors[] = 'Data import ZIP tidak ditemukan. Silakan upload ulang.';
        } else {
            $fields = get_fields((int) $ct['id']);
            $validated = export_profile_validate((array) ($manifest['profile'] ?? []), $ct, $fields);
            if (!$validated['ok']) {
                $errors[] = 'Profil pada ZIP sudah tidak cocok dengan content type: '
                    . implode(' ', array_slice($validated['errors'], 0, 5));
                $profile = is_array($state['profile'] ?? null) ? $state['profile'] : [];
                $summary = zip_import_summary($manifest);
                $view = 'preview';
            } else {
                $options = [
                    'duplicates' => in_array($_POST['duplicates'] ?? '', ['skip', 'update', 'duplicate'], true)
                        ? (string) $_POST['duplicates']
                        : 'skip',
                    'fallback_draft' => !empty($_POST['fallback_draft']),
                    'import_terms' => !empty($_POST['import_terms']),
                    'create_terms' => !empty($_POST['create_terms']) && can_manage_taxonomies($user),
                    'force_draft' => !empty($_POST['force_draft']),
                ];
                zip_import_state_update([
                    'profile' => $validated['profile'],
                    'options' => $options,
                    'offset' => 0,
                    'media_cache' => [],
                    'finished' => false,
                    'stats' => [
                        'imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0,
                        'downgraded' => 0, 'failures' => [], 'warnings' => [],
                    ],
                    'started_at' => time(),
                ]);
                log_audit('start_wordpress_zip_import', 'content_types', (string) $ct['id'], (int) $user['id']);
                redirect_admin('import/zip-run?token=' . urlencode($token));
            }
        }
    }
}

/* ===== Data untuk render ===== */
$state = zip_import_state_get();
$profile = $profile ?? (is_array($state['profile'] ?? null) ? $state['profile'] : null);
$profile_fields = is_array($profile['fields'] ?? null) ? $profile['fields'] : [];
$preview_entries = is_array($manifest['entries'] ?? null) ? array_slice($manifest['entries'], 0, 10) : [];
$taxonomies_for_ct = $ct !== null ? get_taxonomies((int) $ct['id']) : [];
$options = array_merge([
    'duplicates' => 'skip', 'fallback_draft' => true, 'import_terms' => true,
    'create_terms' => false, 'force_draft' => false,
], is_array($state['options'] ?? null) ? $state['options'] : []);
$zip_warnings = is_array($state['warnings'] ?? null) ? array_values($state['warnings']) : [];

admin_header('Import ZIP WordPress', 'import-zip');
?>
<div class="card">
  <h2>Import ZIP dari plugin Ringan Export</h2>
  <p class="muted">
    Unggah ZIP hasil ekspor plugin <strong>Ringan Export</strong> di WordPress.
    ZIP berisi manifest JSON + field ACF + media (gambar/file) sehingga tidak perlu
    mengunduh media satu per satu. Profil JSON di dalam ZIP menentukan content type
    tujuan; content type dan field-nya harus sudah ada di CMS.
  </p>
  <?= render_errors($errors) ?>
  <?php if (!class_exists('ZipArchive')): ?>
    <div class="alert alert-error">
      Ekstensi PHP <code>ZipArchive</code> (ext-zip) belum aktif. Import ZIP tidak bisa dijalankan.
    </div>
  <?php endif; ?>
  <?php if (!export_profiles_available()): ?>
    <div class="alert alert-warning">
      Tabel <code>export_profiles</code> belum tersedia (jalankan migrasi
      <code>database/migrations/011_export_profiles.sql</code>). Profil default tetap
      bisa dibuat, tetapi penyimpanan profil dari editor tidak aktif.
    </div>
  <?php endif; ?>
  <ul class="hint">
    <li>Ukuran maksimal ZIP: <?= e((string) round(zip_import_max_bytes() / 1048576, 1)) ?> MB; maksimal <?= e((string) ZIP_IMPORT_MAX_ENTRIES) ?> entry.</li>
    <li>Hanya <code>manifest.json</code> dan <code>media/*</code> yang diterima; file lain di ZIP ditolak.</li>
    <li>Media divalidasi ulang dengan <code>finfo</code>; tipe yang tidak diizinkan CMS akan dilewati.</li>
    <li>Buat profil di <strong>Content Types → Profil</strong> lalu salin ke plugin WordPress.</li>
  </ul>
  <form method="post" action="<?= e(admin_url('import/zip')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="analyze">
    <div class="form-group">
      <label for="zip">File ZIP ekspor</label>
      <input type="file" id="zip" name="zip" accept=".zip,application/zip" required>
    </div>
    <button type="submit" class="btn btn-primary"<?= class_exists('ZipArchive') ? '' : ' disabled' ?>>Upload &amp; Analisis</button>
    <?php if ($state !== null): ?>
      <a class="btn" href="<?= e(admin_url('import/zip?resume=1')) ?>">Lanjutkan sesi import</a>
    <?php endif; ?>
  </form>
</div>

<?php if ($view === 'preview' && $state !== null && $manifest !== null && $ct !== null && $profile !== null): ?>

<div class="card">
  <h2>Ringkasan ZIP</h2>
  <p class="muted">
    Content type tujuan: <strong><?= e((string) $ct['label']) ?></strong> (<code><?= e((string) $ct['slug']) ?></code>)
    · Sumber: <strong><?= e((string) ($summary['site_name'] !== '' ? $summary['site_name'] : ($summary['site_url'] !== '' ? $summary['site_url'] : 'WordPress'))) ?></strong>
    · Post type: <code><?= e((string) ($summary['post_type'] !== '' ? $summary['post_type'] : '—')) ?></code>
  </p>
  <table class="table">
    <thead><tr><th>Entry</th><th>Media</th><th>Ukuran media</th><th>Field profil</th><th>Diekspor</th></tr></thead>
    <tbody>
      <tr>
        <td><?= (int) $summary['entries'] ?></td>
        <td><?= (int) $summary['media_count'] ?></td>
        <td><?= e(media_human_size((int) $summary['media_bytes'])) ?></td>
        <td><?= count($profile_fields) ?></td>
        <td class="muted"><?= e((string) ($summary['exported_at'] !== '' ? $summary['exported_at'] : '—')) ?></td>
      </tr>
    </tbody>
  </table>
  <?php if (($summary['statuses'] ?? []) !== []): ?>
    <p class="muted">
      Status entry:
      <?php foreach ($summary['statuses'] as $status => $count): ?>
        <span class="badge <?= e((string) $status) ?>"><?= e((string) $status) ?>: <?= (int) $count ?></span>
      <?php endforeach; ?>
    </p>
  <?php endif; ?>
  <?php if (($zip_warnings ?? []) !== []): ?>
    <div class="alert alert-warning">
      <strong>Perhatian dari validasi ZIP:</strong>
      <ul><?php foreach ($zip_warnings as $warning): ?><li><?= e((string) $warning) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Profil field</h2>
  <p class="muted">
    Profil ini tertanam di ZIP. Sumber hanya dipakai plugin WordPress saat ekspor;
    CMS mengimpor nilai akhir sesuai tipe field.
  </p>
  <div class="wp-import-scroll">
    <table class="table">
      <thead><tr><th>Field CMS</th><th>Tipe</th><th>Wajib</th><th>Sumber WordPress</th><th>Transform</th></tr></thead>
      <tbody>
        <?php foreach ($profile_fields as $profile_field): ?>
          <tr>
            <td><strong><?= e((string) ($profile_field['label'] ?? $profile_field['key'])) ?></strong><br><code><?= e((string) $profile_field['key']) ?></code></td>
            <td><code><?= e((string) $profile_field['type']) ?></code></td>
            <td><?= !empty($profile_field['required']) ? 'Ya' : '—' ?></td>
            <td><code><?= e((string) ($profile_field['source'] ?? 'none')) ?></code></td>
            <td><code><?= e((string) ($profile_field['transform'] ?? 'auto')) ?></code></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h2>Pratinjau entry (<?= count($preview_entries) ?> pertama)</h2>
  <?php if ($preview_entries === []): ?>
    <p class="muted">Tidak ada entry pada ZIP.</p>
  <?php else: ?>
    <div class="wp-import-scroll">
      <table class="table">
        <thead><tr><th>Judul</th><th>Source ID</th><th>Status</th><th>Field terisi</th><th>Term</th></tr></thead>
        <tbody>
          <?php foreach ($preview_entries as $entry): ?>
            <tr>
              <td><?= e((string) ($entry['title'] ?? '')) ?></td>
              <td><code><?= e((string) ($entry['source_id'] ?? '')) ?></code></td>
              <td><span class="badge <?= e((string) ($entry['status'] ?? 'draft')) ?>"><?= e((string) ($entry['status'] ?? 'draft')) ?></span></td>
              <td><?= is_array($entry['fields'] ?? null) ? count($entry['fields']) : 0 ?></td>
              <td><?= is_array($entry['terms'] ?? null) ? count($entry['terms']) : 0 ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Opsi import</h2>
  <?php $resume_offset = (int) ($state['offset'] ?? 0); ?>
  <?php if (!empty($state['started_at']) && $resume_offset > 0 && empty($state['finished'])): ?>
    <div class="alert alert-warning">
      Sesi ini sudah berjalan: <strong><?= $resume_offset ?></strong> dari
      <?= (int) $summary['entries'] ?> entry sudah diproses.
      <a class="btn btn-small" href="<?= e(admin_url('import/zip-run?token=' . urlencode((string) $state['token']))) ?>">Lanjutkan import</a>
      atau mulai ulang dengan opsi di bawah (entry yang sudah masuk akan dilewati bila memilih "Lewati").
    </div>
  <?php endif; ?>
  <form method="post" action="<?= e(admin_url('import/zip')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="start">
    <input type="hidden" name="token" value="<?= e((string) $state['token']) ?>">

    <div class="form-group">
      <label for="duplicates">Bila post sudah pernah diimport</label>
      <select id="duplicates" name="duplicates">
        <option value="skip"<?= $options['duplicates'] === 'skip' ? ' selected' : '' ?>>Lewati (aman untuk import ulang)</option>
        <option value="update"<?= $options['duplicates'] === 'update' ? ' selected' : '' ?>>Perbarui entri yang ada</option>
        <option value="duplicate"<?= $options['duplicates'] === 'duplicate' ? ' selected' : '' ?>>Buat entri baru (duplikat)</option>
      </select>
    </div>
    <div class="form-group">
      <label class="checkbox-inline"><input type="checkbox" name="fallback_draft" value="1"<?= !empty($options['fallback_draft']) ? ' checked' : '' ?>> Simpan sebagai draft bila field wajib belum lengkap (bukan dilewati)</label>
    </div>
    <div class="form-group">
      <label class="checkbox-inline"><input type="checkbox" name="force_draft" value="1"<?= !empty($options['force_draft']) ? ' checked' : '' ?>> Paksa semua entry menjadi draft</label>
    </div>
    <?php if ($taxonomies_for_ct !== []): ?>
      <div class="form-group">
        <label class="checkbox-inline"><input type="checkbox" name="import_terms" value="1"<?= !empty($options['import_terms']) ? ' checked' : '' ?>> Petakan kategori/tag WordPress ke taksonomi dengan slug sama (<?= e(implode(', ', array_map(static fn(array $t): string => (string) $t['slug'], $taxonomies_for_ct))) ?>)</label>
      </div>
      <?php if (can_manage_taxonomies($user)): ?>
        <div class="form-group">
          <label class="checkbox-inline"><input type="checkbox" name="create_terms" value="1"<?= !empty($options['create_terms']) ? ' checked' : '' ?>> Buat term baru bila belum ada</label>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <button type="submit" class="btn btn-primary">Mulai import (<?= (int) $summary['entries'] ?> entry)</button>
    <button type="submit" form="zip-import-cancel" class="btn">Batalkan sesi</button>
  </form>
  <form method="post" action="<?= e(admin_url('import/zip')) ?>" id="zip-import-cancel">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="cancel">
    <input type="hidden" name="token" value="<?= e((string) $state['token']) ?>">
  </form>
</div>

<?php endif; ?>
<?php
admin_footer();
