<?php
/**
 * Pustaka media (media library): lihat, unggah, dan hapus file.
 * Semua role bisa melihat; unggah/hapus khusus superadmin & editor.
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
require_once APP_ROOT . '/includes/upload.php';
require_once APP_ROOT . '/includes/media.php';
require_once APP_ROOT . '/includes/audit_log.php';
require_once APP_ROOT . '/admin/partials/header.php';
require_once APP_ROOT . '/admin/partials/footer.php';
require_once APP_ROOT . '/admin/partials/pagination.php';

$user = require_login();
if (!can_view_media($user)) {
    http_response_code(403);
    exit('403 — Anda tidak memiliki akses ke pustaka media.');
}
$can_manage = can_manage_media($user);

$errors = [];
$q = sanitize_text($_GET['q'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!$can_manage) {
        http_response_code(403);
        exit('403 — Anda tidak memiliki akses untuk mengubah media.');
    }
    $action = sanitize_text($_POST['action'] ?? '');

    if ($action === 'delete_media') {
        $mid = (int) ($_POST['media_id'] ?? 0);
        $item = get_media_item($mid);
        if ($item !== null) {
            // delete_upload() menghapus file fisik (termasuk offload) + baris media.
            delete_upload((string) $item['path']);
            log_audit('delete_media', 'media', (string) $mid, (int) $user['id']);
            flash_set('success', 'Media dihapus.');
        }
        redirect_admin('media' . ($q !== '' ? '?q=' . urlencode($q) : ''));
    }

    if ($action === 'upload') {
        $files = $_FILES['files'] ?? null;
        if (!is_array($files) || !is_array($files['name'] ?? null)) {
            $errors[] = 'Tidak ada file yang dipilih.';
        } else {
            $count = count($files['name']);
            $ok = 0;
            for ($i = 0; $i < $count; $i++) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $res = handle_upload([
                    'name' => $files['name'][$i],
                    'type' => $files['type'][$i] ?? '',
                    'tmp_name' => $files['tmp_name'][$i] ?? '',
                    'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $files['size'][$i] ?? 0,
                ], false, (int) $user['id']);
                if ($res['ok']) {
                    $ok++;
                } else {
                    $errors[] = basename((string) $files['name'][$i]) . ': ' . $res['error'];
                }
            }
            if ($ok > 0) {
                log_audit('upload_media', 'media', null, (int) $user['id']);
                flash_set('success', $ok . ' file berhasil diunggah.');
                redirect_admin('media');
            }
        }
    }

    if ($action === 'sync_disk') {
        $n = media_sync_from_disk();
        log_audit('sync_media', 'media', null, (int) $user['id']);
        flash_set('success', $n > 0
            ? $n . ' file lama berhasil didaftarkan ke pustaka media.'
            : 'Tidak ada file baru untuk didaftarkan.');
        redirect_admin('media');
    }
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$result = get_media_items($page, 24, $q);

admin_header('Media', 'media');
?>
<?php if ($can_manage): ?>
<div class="card">
  <h2 class="section-title">Unggah Media</h2>
  <?php if ($errors !== []): ?>
    <div class="alert alert-error"><ul>
      <?php foreach ($errors as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?>
    </ul></div>
  <?php endif; ?>
  <form method="post" action="<?= e(admin_url('media')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <div class="form-group">
      <input type="file" name="files[]" multiple>
      <small class="hint">Gambar, PDF, dokumen, zip. Maks <?= e((string) round(MAX_UPLOAD_SIZE / 1048576, 1)) ?> MB/file.</small>
    </div>
    <button type="submit" class="btn btn-primary">Unggah</button>
  </form>
</div>
<?php endif; ?>

<div class="page-actions">
  <form method="get" action="<?= e(admin_url('media')) ?>" class="inline-form">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Cari nama file...">
    <button type="submit" class="btn btn-small">Cari</button>
    <?php if ($q !== ''): ?>
      <a class="btn btn-small" href="<?= e(admin_url('media')) ?>">Reset</a>
    <?php endif; ?>
  </form>
  <span class="muted"><?= (int) $result['total'] ?> file</span>
  <?php if ($can_manage): ?>
    <form method="post" action="<?= e(admin_url('media')) ?>" data-confirm="Daftarkan file lama di storage/uploads yang belum tercatat?" class="inline-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="sync_disk">
      <button type="submit" class="btn btn-small">Sinkron file lama</button>
    </form>
  <?php endif; ?>
</div>

<?php if ($result['items'] === []): ?>
  <p class="muted">Belum ada media.</p>
<?php else: ?>
<div class="media-grid">
  <?php foreach ($result['items'] as $m): ?>
    <?php $url = BASE_URL . '/files/' . ltrim((string) $m['path'], '/'); ?>
    <div class="media-card">
      <div class="media-thumb">
        <?php if (media_is_image($m)): ?>
          <a href="<?= e($url) ?>" target="_blank" rel="noopener"><img src="<?= e($url) ?>" alt="<?= e($m['original_name']) ?>" loading="lazy"></a>
        <?php else: ?>
          <a class="media-file" href="<?= e($url) ?>" target="_blank" rel="noopener"><?= e(strtoupper(pathinfo((string) $m['path'], PATHINFO_EXTENSION))) ?></a>
        <?php endif; ?>
      </div>
      <div class="media-meta">
        <div class="media-name" title="<?= e($m['original_name']) ?>"><?= e($m['original_name']) ?></div>
        <div class="muted media-sub">
          <?= e(media_human_size((int) $m['size'])) ?>
          <?= $m['created_at'] !== '' ? ' · ' . e((string) $m['created_at']) : '' ?>
          <?= !empty($m['uploader']) ? ' · @' . e((string) $m['uploader']) : '' ?>
        </div>
        <div class="media-actions">
          <button type="button" class="btn btn-small" data-copy="<?= e($url) ?>">Copy URL</button>
          <?php if ($can_manage): ?>
            <form method="post" action="<?= e(admin_url('media')) ?>" data-confirm="Hapus media ini? File akan dihapus permanen." class="inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_media">
              <input type="hidden" name="media_id" value="<?= (int) $m['id'] ?>">
              <button type="submit" class="btn btn-small btn-danger">Hapus</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?= render_pagination(admin_url('media'), $result['page'], $result['total_pages'], $q !== '' ? ['q' => $q] : []) ?>
<?php endif; ?>
<?php
admin_footer();
