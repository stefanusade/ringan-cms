<?php
/**
 * Pengaturan Webhook: kirim event ke SSG (Astro/Next) untuk rebuild.
 * Superadmin only. Delivery berjalan di antrean setelah response selesai.
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
require_once APP_ROOT . '/includes/settings.php';
require_once APP_ROOT . '/includes/webhooks.php';
require_once APP_ROOT . '/includes/audit_log.php';
require_once APP_ROOT . '/admin/partials/header.php';
require_once APP_ROOT . '/admin/partials/footer.php';

$user = require_role('superadmin');

$settings = webhook_settings(true);
$errors = [];
$action = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? sanitize_text($_POST['action'] ?? 'save') : '';

if ($action === 'save') {
    require_csrf();
    $enabled = !empty($_POST['webhook_enabled']);
    $url = trim(sanitize_text($_POST['webhook_url'] ?? ''));
    $allow_private = !empty($_POST['webhook_allow_private']);
    $events = array_values(array_intersect(
        array_map('strval', (array) ($_POST['events'] ?? [])),
        webhook_default_events()
    ));
    $secret = (string) $settings['secret'];
    $posted_secret = trim((string) ($_POST['webhook_secret'] ?? ''));
    if (!empty($_POST['clear_secret'])) {
        $secret = '';
    } elseif ($posted_secret !== '') {
        $secret = $posted_secret;
    }

    if ($url !== '' && !webhook_url_is_allowed($url, $allow_private)) {
        $errors['webhook_url'] = 'URL harus http/https dan host publik. Aktifkan "Izinkan host privat" bila SSG berjalan di jaringan internal.';
    }
    if ($enabled && $url === '') {
        $errors['webhook_url'] = 'Isi URL webhook terlebih dahulu.';
    }

    if ($errors === []) {
        set_setting('webhook_enabled', $enabled ? '1' : '0');
        set_setting('webhook_url', $url);
        set_setting('webhook_secret', $secret);
        set_setting('webhook_allow_private', $allow_private ? '1' : '0');
        set_setting('webhook_events', json_encode($events, JSON_UNESCAPED_SLASHES));
        log_audit('update_webhook_settings', 'settings', null, (int) $user['id']);
        flash_set('success', 'Pengaturan webhook disimpan.');
        redirect_admin('settings/webhooks');
    }
    $settings = [
        'enabled' => $enabled,
        'url' => $url,
        'secret' => $secret,
        'allow_private' => $allow_private,
        'events' => $events,
    ];
}

if ($action === 'test') {
    require_csrf();
    $result = webhook_send_test($user);
    if ($result['ok']) {
        flash_set('success', 'Tes webhook berhasil (HTTP ' . (int) $result['code'] . ').');
    } else {
        flash_set('error', 'Tes webhook gagal' . ($result['code'] > 0 ? ' (HTTP ' . (int) $result['code'] . ')' : '') . ': ' . $result['error']);
    }
    redirect_admin('settings/webhooks');
}

if ($action === 'retry') {
    require_csrf();
    $processed = webhook_process_queue(5);
    flash_set('success', $processed . ' delivery webhook diproses.');
    redirect_admin('settings/webhooks');
}

$deliveries = webhook_recent_deliveries(20);
$pending = 0;
if (webhook_table_available()) {
    try {
        $pending = (int) get_db()->query(
            "SELECT COUNT(*) FROM webhook_deliveries WHERE status IN ('pending','sending')"
        )->fetchColumn();
    } catch (Throwable $e) {
        $pending = 0;
    }
}

admin_header('Pengaturan Webhook', 'settings-webhooks');
?>
<?php if (!webhook_table_available()): ?>
  <div class="alert alert-warning">
    Tabel <code>webhook_deliveries</code> belum tersedia. Jalankan migrasi
    <code>database/migrations/010_webhooks.sql</code> agar webhook bisa dikirim.
  </div>
<?php endif; ?>

<form method="post" action="<?= e(admin_url('settings/webhooks')) ?>" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <?= render_errors($errors) ?>

  <div class="form-group">
    <label class="checkbox-inline">
      <input type="checkbox" name="webhook_enabled" value="1"<?= $settings['enabled'] ? ' checked' : '' ?>>
      Aktifkan webhook
    </label>
  </div>

  <div class="form-group">
    <label for="webhook_url">URL webhook</label>
    <input type="url" id="webhook_url" name="webhook_url" value="<?= e($settings['url']) ?>" maxlength="500" placeholder="https://contoh.com/api/rebuild">
    <small class="hint">SSG menerima POST JSON. Contoh: endpoint rebuild Astro/Next, Netlify build hook, atau Cloudflare Pages deploy hook.</small>
  </div>

  <div class="form-group">
    <label for="webhook_secret">Secret (HMAC SHA-256)</label>
    <input type="password" id="webhook_secret" name="webhook_secret" value="" placeholder="<?= $settings['secret'] !== '' ? '•••••••• (kosongkan untuk mempertahankan)' : 'opsional' ?>" autocomplete="new-password">
    <label class="checkbox-inline"><input type="checkbox" name="clear_secret" value="1"> Hapus secret</label>
    <small class="hint">Jika diisi, setiap request menyertakan header <code>X-Ringan-Signature: sha256=HMAC(body, secret)</code>.</small>
  </div>

  <div class="form-group">
    <label class="checkbox-inline">
      <input type="checkbox" name="webhook_allow_private" value="1"<?= $settings['allow_private'] ? ' checked' : '' ?>>
      Izinkan host privat/localhost
    </label>
    <small class="hint">Aktifkan bila SSG berjalan di jaringan internal (Docker/VPS yang sama).</small>
  </div>

  <div class="form-group">
    <label>Kirim webhook saat</label>
    <?php foreach (WEBHOOK_EVENT_LABELS as $event => $label): ?>
      <label class="checkbox-inline">
        <input type="checkbox" name="events[]" value="<?= e($event) ?>"<?= in_array($event, $settings['events'], true) ? ' checked' : '' ?>>
        <?= e($label) ?> <code><?= e($event) ?></code>
      </label>
    <?php endforeach; ?>
  </div>

  <button type="submit" class="btn btn-primary">Simpan Pengaturan</button>
</form>

<div class="card">
  <h2>Tes &amp; antrean</h2>
  <p class="muted">
    Webhook dikirim setelah response selesai (tidak menahan editor). Kegagalan dicoba ulang otomatis,
    maksimal <?= (int) WEBHOOK_MAX_ATTEMPTS ?> kali. Antrean menunggu: <strong><?= (int) $pending ?></strong>.
    Tes memakai pengaturan yang sudah tersimpan.
  </p>
  <form method="post" action="<?= e(admin_url('settings/webhooks')) ?>" class="inline-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="test">
    <button type="submit" class="btn">Kirim Tes (webhook.test)</button>
  </form>
  <form method="post" action="<?= e(admin_url('settings/webhooks')) ?>" class="inline-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="retry">
    <button type="submit" class="btn">Proses Antrean Sekarang</button>
  </form>
</div>

<div class="card">
  <h2>Riwayat delivery terakhir</h2>
  <?php if ($deliveries === []): ?>
    <p class="muted">Belum ada delivery.</p>
  <?php else: ?>
    <div class="wp-import-scroll">
      <table class="table">
        <thead><tr><th>ID</th><th>Event</th><th>Status</th><th>HTTP</th><th>Percobaan</th><th>Dibuat</th><th>Error</th></tr></thead>
        <tbody>
          <?php foreach ($deliveries as $row): ?>
            <?php
            $badge = match ((string) $row['status']) {
                'delivered' => '<span class="badge active">delivered</span>',
                'failed' => '<span class="badge danger">failed</span>',
                'sending' => '<span class="badge">sending</span>',
                default => '<span class="badge">pending</span>',
            };
            ?>
            <tr>
              <td>#<?= (int) $row['id'] ?></td>
              <td><code><?= e((string) $row['event']) ?></code></td>
              <td><?= $badge ?></td>
              <td><?= $row['response_code'] !== null ? (int) $row['response_code'] : '—' ?></td>
              <td><?= (int) $row['attempts'] ?></td>
              <td><?= e((string) $row['created_at']) ?></td>
              <td class="muted"><?= e((string) ($row['last_error'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php
admin_footer();
