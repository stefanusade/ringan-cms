<?php
/**
 * Import dari WordPress (WXR): upload → mapping → verifikasi field.
 * Import aktual dijalankan bertahap di admin/import/run.php.
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
require_once APP_ROOT . '/includes/taxonomies.php';
require_once APP_ROOT . '/includes/audit_log.php';
require_once APP_ROOT . '/includes/wordpress_import.php';
require_once APP_ROOT . '/admin/partials/header.php';
require_once APP_ROOT . '/admin/partials/footer.php';

$user = require_login();
if (!can_write_entries($user)) {
    http_response_code(403);
    exit('403 — Anda tidak memiliki akses untuk mengimport entri.');
}

wp_import_cleanup();
wp_import_ensure_table();

$action = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? sanitize_text($_POST['action'] ?? '')
    : (isset($_GET['resume']) ? 'resume' : '');

$content_types = get_content_types();
$state = wp_import_state_get();
$items_payload = null;
$summary = null;
$verification = null;
$fields = [];
$ct = null;
$errors = [];
$view = 'upload';

/**
 * Muat ulang state + data item; null bila sesi/token tidak valid.
 */
function wp_import_admin_load_state(array $state, string $token): ?array
{
    if (!wp_import_state_matches($state, $token)) {
        return null;
    }
    return wp_import_load_items($token);
}

if ($action === 'cancel') {
    require_csrf();
    $token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
    if ($state !== null && wp_import_state_matches($state, $token)) {
        wp_import_destroy($token);
    }
    flash_set('success', 'Sesi import dibatalkan.');
    redirect_admin('import');
}

if ($action === 'analyze') {
    require_csrf();
    $ct_id = (int) ($_POST['content_type'] ?? 0);
    $ct = get_content_type($ct_id);
    if ($ct === null) {
        $errors[] = 'Pilih content type tujuan yang valid.';
    } else {
        $stored = wp_import_store_upload($_FILES['wxr'] ?? [], (int) $user['id']);
        if (!$stored['ok']) {
            $errors[] = $stored['error'];
        } else {
            $payload = wp_import_load_items($stored['token']);
            if ($payload === null) {
                $errors[] = 'Gagal membaca data hasil upload.';
            } else {
                $counts = wp_import_post_type_counts($payload['items']);
                $post_type = isset($counts['post']) ? 'post' : (string) array_key_first($counts);
                $fields = get_fields($ct_id);
                $summary = wp_import_meta_summary($payload['items'], $post_type);
                $state = [
                    'token' => $stored['token'],
                    'ct_id' => $ct_id,
                    'ct_slug' => (string) $ct['slug'],
                    'ct_label' => (string) $ct['label'],
                    'post_type' => $post_type,
                    'blog_url' => (string) $payload['blog_url'],
                    'site_url' => (string) $payload['site_url'],
                    'mapping' => wp_import_default_mapping($fields, $summary),
                    'options' => [
                        'status_mode' => 'map',
                        'default_status' => 'draft',
                        'fallback_draft' => true,
                        'download_media' => true,
                        'import_terms' => true,
                        'create_terms' => false,
                        'duplicates' => 'skip',
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
                wp_import_state_start($state);
                $items_payload = $payload;
                $view = 'mapping';
            }
        }
    }
}

if (in_array($action, ['resume', 'remap', 'verify', 'start'], true)) {
    if ($state === null) {
        $errors[] = 'Tidak ada sesi import aktif. Silakan upload file WXR.';
    } else {
        $token = in_array($action, ['remap', 'verify', 'start'], true)
            ? (is_string($_POST['token'] ?? null) ? $_POST['token'] : '')
            : (string) $state['token'];
        $items_payload = wp_import_admin_load_state($state, $token);
        if ($items_payload === null) {
            $errors[] = 'Sesi import tidak valid atau data sudah kedaluwarsa. Silakan upload ulang.';
            wp_import_state_clear();
        } else {
            $ct = get_content_type((int) $state['ct_id']);
            if ($ct === null) {
                $errors[] = 'Content type tujuan sudah tidak ada.';
                wp_import_destroy($token);
            } else {
                $fields = get_fields((int) $state['ct_id']);
                $counts = wp_import_post_type_counts($items_payload['items']);
                $post_type = (string) $state['post_type'];

                if ($action === 'remap') {
                    require_csrf();
                    $requested = sanitize_text($_POST['post_type'] ?? '');
                    if (!isset($counts[$requested])) {
                        $errors[] = 'Post type tidak ditemukan pada file WXR.';
                    } else {
                        $post_type = $requested;
                        $summary = wp_import_meta_summary($items_payload['items'], $post_type);
                        $mapping = wp_import_default_mapping($fields, $summary);
                        $state['post_type'] = $post_type;
                        $state['mapping'] = $mapping;
                        wp_import_state_update(['post_type' => $post_type, 'mapping' => $mapping]);
                        flash_set('success', 'Post type diganti ke "' . $post_type . '"; mapping direset.');
                    }
                }

                if ($action === 'verify' || $action === 'start') {
                    require_csrf();
                    $summary = wp_import_meta_summary($items_payload['items'], $post_type);
                    $mapping_raw = is_array($_POST['map'] ?? null) ? $_POST['map'] : [];
                    $mapping = wp_import_clean_mapping($mapping_raw, $fields, $summary);

                    $options = [
                        'status_mode' => ($_POST['status_mode'] ?? 'map') === 'fixed' ? 'fixed' : 'map',
                        'default_status' => in_array($_POST['default_status'] ?? '', entry_statuses(), true)
                            ? (string) $_POST['default_status']
                            : 'draft',
                        'fallback_draft' => !empty($_POST['fallback_draft']),
                        'download_media' => !empty($_POST['download_media']),
                        'import_terms' => !empty($_POST['import_terms']),
                        'create_terms' => !empty($_POST['create_terms']) && can_manage_taxonomies($user),
                        'duplicates' => in_array($_POST['duplicates'] ?? '', ['skip', 'update', 'duplicate'], true)
                            ? (string) $_POST['duplicates']
                            : 'skip',
                    ];

                    if ($action === 'verify') {
                        $state['mapping'] = $mapping;
                        $state['options'] = $options;
                        wp_import_state_update(['mapping' => $mapping, 'options' => $options]);
                        $verification = wp_import_verify($fields, $mapping, $items_payload['items'], array_merge($options, [
                            'post_type' => $post_type,
                            'blog_url' => (string) $state['blog_url'],
                        ]));
                        $view = $verification['ok'] ? 'verify' : 'mapping';
                    } else {
                        // Start: verifikasi ulang di server, jangan percaya tombol di UI.
                        $verify_options = (array) $state['options'];
                        $verification = wp_import_verify($fields, (array) $state['mapping'], $items_payload['items'], array_merge($verify_options, [
                            'post_type' => $post_type,
                            'blog_url' => (string) $state['blog_url'],
                        ]));
                        if (!$verification['ok']) {
                            $view = 'mapping';
                            $errors[] = 'Verifikasi masih gagal; perbaiki mapping sebelum memulai import.';
                        } else {
                            wp_import_state_update([
                                'offset' => 0,
                                'media_cache' => [],
                                'finished' => false,
                                'stats' => [
                                    'imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0,
                                    'downgraded' => 0, 'failures' => [], 'warnings' => [],
                                ],
                                'started_at' => time(),
                            ]);
                            log_audit('start_wordpress_import', 'content_types', (string) $state['ct_id'], (int) $user['id']);
                            redirect_admin('import/run?token=' . urlencode((string) $state['token']));
                        }
                    }
                }

                if ($summary === null) {
                    $summary = wp_import_meta_summary($items_payload['items'], $post_type);
                }
                if ($view === 'upload') {
                    $view = 'mapping';
                }
                $state = wp_import_state_get();
            }
        }
    }
}

/* ===== Data untuk render ===== */

$counts = [];
$choices = [];
$mapping = [];
$options = [
    'status_mode' => 'map', 'default_status' => 'draft', 'fallback_draft' => true,
    'download_media' => true, 'import_terms' => true, 'create_terms' => false, 'duplicates' => 'skip',
];
if ($items_payload !== null && $state !== null) {
    $counts = wp_import_post_type_counts($items_payload['items']);
    $summary = $summary ?? wp_import_meta_summary($items_payload['items'], (string) $state['post_type']);
    $choices = wp_import_source_choices($summary);
    $mapping = is_array($state['mapping'] ?? null) ? $state['mapping'] : [];
    $options = array_merge($options, is_array($state['options'] ?? null) ? $state['options'] : []);
}

$taxonomies_for_ct = $ct !== null ? get_taxonomies((int) $ct['id']) : [];

function wp_import_badge(string $status): string
{
    return match ($status) {
        'ok' => '<span class="badge active">OK</span>',
        'warn' => '<span class="badge">Perhatian</span>',
        'error' => '<span class="badge danger">Error</span>',
        'skip' => '<span class="badge">Dilewati</span>',
        default => '<span class="badge">' . e($status) . '</span>',
    };
}

admin_header('Import WordPress', 'import');
?>
<div class="card">
  <h2>Import dari WordPress</h2>
  <p class="muted">
    Unggah file <strong>WXR</strong> (Tools → Export di WordPress). Post, meta ACF, dan
    lampiran/media dipetakan ke field content type tujuan. Import baru berjalan setelah
    mapping diverifikasi.
  </p>
  <?= render_errors($errors) ?>
  <?php if (!wp_import_refs_available()): ?>
    <div class="alert alert-warning">
      Tabel <code>import_refs</code> belum tersedia (jalankan migrasi
      <code>database/migrations/008_wordpress_import.sql</code>). Import tetap bisa
      dijalankan, tetapi opsi anti-duplikat (lewati/perbarui) tidak aktif.
    </div>
  <?php endif; ?>
  <ul class="hint">
    <li>Ukuran maksimal file: <?= e((string) round(wp_import_max_bytes() / 1048576, 1)) ?> MB (<?= e((string) WP_IMPORT_MAX_ITEMS) ?> item).</li>
    <li>Format: WXR 1.2. DOCTYPE/ENTITY ditolak.</li>
    <li>Nilai ACF repeater tidak dipetakan otomatis (CMS belum punya tipe repeater).</li>
  </ul>
  <?php if ($content_types === []): ?>
    <p class="muted">Belum ada content type. <a href="<?= e(admin_url('content-types/create')) ?>">Buat content type</a> terlebih dahulu.</p>
  <?php else: ?>
  <form method="post" action="<?= e(admin_url('import')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="analyze">
    <div class="grid-2">
      <div class="form-group">
        <label for="content_type">Content type tujuan</label>
        <select id="content_type" name="content_type" required>
          <?php foreach ($content_types as $item): ?>
            <option value="<?= (int) $item['id'] ?>"><?= e($item['label']) ?> (<?= e($item['slug']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="wxr">File WXR (.xml)</label>
        <input type="file" id="wxr" name="wxr" accept=".xml,text/xml,application/xml" required>
      </div>
    </div>
    <button type="submit" class="btn btn-primary">Upload &amp; Analisis</button>
    <?php if ($state !== null): ?>
      <a class="btn" href="<?= e(admin_url('import?resume=1')) ?>">Lanjutkan sesi import</a>
    <?php endif; ?>
  </form>
  <?php endif; ?>
</div>

<?php if ($view !== 'upload' && $state !== null && $items_payload !== null && $ct !== null): ?>

<div class="card">
  <h2>Ringkasan file</h2>
  <p class="muted">
    Situs: <strong><?= e((string) ($items_payload['blog_url'] !== '' ? $items_payload['blog_url'] : $items_payload['site_url'])) ?></strong>
    · Content type tujuan: <strong><?= e((string) $ct['label']) ?></strong>
    · Total item: <strong><?= e((string) count($items_payload['items'])) ?></strong>
  </p>
  <table class="table">
    <thead><tr><th>Post type</th><th>Jumlah item</th></tr></thead>
    <tbody>
      <?php foreach ($counts as $type => $count): ?>
        <tr><td><code><?= e((string) $type) ?></code></td><td><?= (int) $count ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <form method="post" action="<?= e(admin_url('import')) ?>" class="inline-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="remap">
    <input type="hidden" name="token" value="<?= e((string) $state['token']) ?>">
    <label for="post_type">Import post type:</label>
    <select id="post_type" name="post_type">
      <?php foreach ($counts as $type => $count): ?>
        <option value="<?= e((string) $type) ?>"<?= (string) $state['post_type'] === (string) $type ? ' selected' : '' ?>>
          <?= e((string) $type) ?> (<?= (int) $count ?>)
        </option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn">Ganti &amp; reset mapping</button>
  </form>
</div>

<?php if ($summary['keys'] !== []): ?>
<div class="card">
  <h2>Meta key terdeteksi (ACF/custom fields)</h2>
  <p class="muted">
    Meta yang diawali <code>_</code> disembunyikan (field key internal ACF). Repeater:
    <?= $summary['repeaters'] === [] ? '<em>tidak ada</em>' : '<code>' . e(implode(', ', array_keys($summary['repeaters']))) . '</code>' ?>
  </p>
  <div class="wp-import-scroll">
    <table class="table">
      <thead><tr><th>Meta key</th><th>Terisi</th><th>Contoh nilai</th></tr></thead>
      <tbody>
        <?php foreach ($summary['keys'] as $meta_key => $info): ?>
          <tr>
            <td><code><?= e((string) $meta_key) ?></code></td>
            <td><?= (int) $info['count'] ?></td>
            <td class="muted"><?= e(implode(' · ', $info['samples'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($verification !== null && $verification['ok']): ?>
<div class="card">
  <h2>Verifikasi berhasil</h2>
  <p class="muted">
    <?= (int) $verification['items_total'] ?> item siap diproses
    (status: <?= e(implode(', ', array_map(
        static fn(string $k, int $v): string => $k . '=' . $v,
        array_keys($verification['statuses']),
        array_values($verification['statuses'])
    ))) ?>).
  </p>
  <?php if ($verification['warnings'] !== []): ?>
    <div class="alert alert-warning">
      <strong>Perhatian (tidak memblokir):</strong>
      <ul><?php foreach ($verification['warnings'] as $warning): ?><li><?= e($warning) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
  <table class="table">
    <thead><tr><th>Field</th><th>Tipe</th><th>Sumber</th><th>Nilai</th><th>Status</th><th>Catatan</th></tr></thead>
    <tbody>
      <?php foreach ($verification['fields'] as $row): ?>
        <tr>
          <td><?= e((string) $row['label']) ?><?= $row['required'] ? ' <span class="req">*</span>' : '' ?></td>
          <td><code><?= e((string) $row['type']) ?></code></td>
          <td><code><?= e((string) ($row['source'] !== '' ? $row['source'] : '—')) ?></code></td>
          <td><?= (int) $row['filled'] ?>/<?= (int) $row['total'] ?></td>
          <td><?= wp_import_badge((string) $row['status']) ?></td>
          <td class="muted"><?= e(implode('; ', (array) $row['notes'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <form method="post" action="<?= e(admin_url('import')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="start">
    <input type="hidden" name="token" value="<?= e((string) $state['token']) ?>">
    <button type="submit" class="btn btn-primary">Mulai import (<?= (int) $verification['items_total'] ?> item)</button>
    <a class="btn" href="<?= e(admin_url('import?resume=1')) ?>">Kembali ke mapping</a>
  </form>
</div>
<?php else: ?>

<div class="card">
  <h2>Mapping field</h2>
  <?php if ($verification !== null && !$verification['ok']): ?>
    <div class="alert alert-error">
      <strong>Verifikasi gagal — import belum bisa dijalankan:</strong>
      <ul><?php foreach ($verification['blocking'] as $message): ?><li><?= e($message) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= e(admin_url('import')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="verify">
    <input type="hidden" name="token" value="<?= e((string) $state['token']) ?>">

    <div class="wp-import-scroll">
      <table class="table">
        <thead><tr><th>Field CMS</th><th>Tipe</th><th>Wajib</th><th>Sumber WordPress</th></tr></thead>
        <tbody>
          <?php foreach ($fields as $field): ?>
            <?php
            $key = (string) $field['field_key'];
            $row = $verification['fields'][$key] ?? null;
            ?>
            <tr>
              <td>
                <strong><?= e((string) $field['label']) ?></strong><br>
                <code><?= e($key) ?></code>
              </td>
              <td><code><?= e((string) $field['field_type']) ?></code></td>
              <td><?= $field['is_required'] ? 'Ya' : '—' ?></td>
              <td>
                <select name="map[<?= e($key) ?>]">
                  <option value="">— lewati —</option>
                  <?php $selected = (string) ($mapping[$key] ?? ''); ?>
                  <?php foreach ($choices as $source => $label): ?>
                    <option value="<?= e((string) $source) ?>"<?= $selected === (string) $source ? ' selected' : '' ?>><?= e((string) $label) ?></option>
                  <?php endforeach; ?>
                </select>
                <?php if ($row !== null): ?>
                  <div><?= wp_import_badge((string) $row['status']) ?>
                    <span class="muted"><?= (int) $row['filled'] ?>/<?= (int) $row['total'] ?> terisi</span>
                  </div>
                  <?php if ($row['notes'] !== []): ?>
                    <small class="hint"><?= e(implode('; ', (array) $row['notes'])) ?></small>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <h3>Opsi import</h3>
    <div class="grid-2">
      <div class="form-group">
        <label for="status_mode">Status entri</label>
        <select id="status_mode" name="status_mode">
          <option value="map"<?= ($options['status_mode'] ?? 'map') === 'map' ? ' selected' : '' ?>>Ikuti status WordPress (publish → published, lainnya → draft)</option>
          <option value="fixed"<?= ($options['status_mode'] ?? '') === 'fixed' ? ' selected' : '' ?>>Paksa semua ke status di bawah</option>
        </select>
      </div>
      <div class="form-group">
        <label for="default_status">Status default (mode paksa)</label>
        <select id="default_status" name="default_status">
          <?php foreach (entry_statuses() as $status): ?>
            <option value="<?= e($status) ?>"<?= ($options['default_status'] ?? 'draft') === $status ? ' selected' : '' ?>><?= e($status) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label class="checkbox-inline"><input type="checkbox" name="fallback_draft" value="1"<?= !empty($options['fallback_draft']) ? ' checked' : '' ?>> Simpan sebagai draft bila field wajib belum lengkap (bukan dilewati)</label>
    </div>
    <div class="form-group">
      <label class="checkbox-inline"><input type="checkbox" name="download_media" value="1"<?= !empty($options['download_media']) ? ' checked' : '' ?>> Unduh gambar/file dari WordPress ke media library</label>
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
    <div class="form-group">
      <label for="duplicates">Bila post sudah pernah diimport</label>
      <select id="duplicates" name="duplicates">
        <option value="skip"<?= ($options['duplicates'] ?? 'skip') === 'skip' ? ' selected' : '' ?>>Lewati (aman untuk import ulang)</option>
        <option value="update"<?= ($options['duplicates'] ?? '') === 'update' ? ' selected' : '' ?>>Perbarui entri yang ada</option>
        <option value="duplicate"<?= ($options['duplicates'] ?? '') === 'duplicate' ? ' selected' : '' ?>>Buat entri baru (duplikat)</option>
      </select>
    </div>

    <button type="submit" class="btn btn-primary">Verifikasi mapping</button>
    <button type="submit" form="wp-import-cancel" class="btn">Batalkan sesi</button>
  </form>
  <form method="post" action="<?= e(admin_url('import')) ?>" id="wp-import-cancel">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="cancel">
    <input type="hidden" name="token" value="<?= e((string) $state['token']) ?>">
  </form>
</div>

<?php endif; ?>
<?php endif; ?>
<?php
admin_footer();
