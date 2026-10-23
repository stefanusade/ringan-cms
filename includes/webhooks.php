<?php
/**
 * Webhook keluar untuk memicu rebuild SSG (Astro/Next/etc.).
 *
 * Pengiriman tidak memblokir response: event masuk ke tabel
 * webhook_deliveries, lalu diproses setelah response selesai
 * (fastcgi_finish_request bila tersedia). Kegagalan dicoba ulang
 * otomatis saat ada request berikutnya, maksimal 3 percobaan.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/response.php';

const WEBHOOK_EVENT_LABELS = [
    'entry.published' => 'Entri dipublikasikan',
    'entry.updated' => 'Entri published diperbarui',
    'entry.unpublished' => 'Entri tidak lagi published',
    'entry.deleted' => 'Entri dihapus permanen',
    'entries.bulk' => 'Aksi massal entri',
    'entries.imported' => 'Import WordPress selesai',
];

const WEBHOOK_MAX_ATTEMPTS = 3;

function webhook_default_events(): array
{
    return array_keys(WEBHOOK_EVENT_LABELS);
}

function webhook_decode_events(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return webhook_default_events();
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return webhook_default_events();
    }
    return array_values(array_intersect(
        array_map('strval', $decoded),
        webhook_default_events()
    ));
}

/**
 * @return array{enabled:bool,url:string,secret:string,allow_private:bool,events:string[]}
 */
function webhook_settings(bool $refresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$refresh) {
        return $cache;
    }
    $cache = [
        'enabled' => get_setting('webhook_enabled', '0') === '1',
        'url' => trim(get_setting('webhook_url', '')),
        'secret' => get_setting('webhook_secret', ''),
        'allow_private' => get_setting('webhook_allow_private', '0') === '1',
        'events' => webhook_decode_events(get_setting('webhook_events', '')),
    ];
    return $cache;
}

function webhook_table_available(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    try {
        get_db()->query('SELECT 1 FROM webhook_deliveries LIMIT 1');
        $available = true;
    } catch (Throwable $e) {
        $available = false;
    }
    return $available;
}

/**
 * URL harus http/https. Host privat hanya diizinkan bila admin mengaktifkannya
 * (mis. SSG berjalan di jaringan Docker yang sama).
 */
function webhook_url_is_allowed(string $url, bool $allow_private): bool
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return false;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host === '') {
        return false;
    }
    if ($allow_private) {
        return true;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
    $ips = @gethostbynamel($host);
    if (!is_array($ips) || $ips === []) {
        return false;
    }
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
    }
    return true;
}

function webhook_build_payload(
    string $event,
    ?array $content_type,
    ?int $entry_id,
    ?array $actor,
    array $extra = []
): array {
    $payload = [
        'event' => $event,
        'occurred_at' => gmdate('c'),
        'site' => [
            'title' => get_setting('site_title', 'Ringan CMS'),
            'url' => BASE_URL,
        ],
        'content_type' => $content_type !== null ? [
            'id' => (int) ($content_type['id'] ?? 0),
            'slug' => (string) ($content_type['slug'] ?? ''),
            'label' => (string) ($content_type['label'] ?? ''),
        ] : null,
        'entry' => $entry_id !== null ? ['id' => $entry_id] : null,
        'actor' => $actor !== null ? [
            'id' => (int) ($actor['id'] ?? 0),
            'username' => (string) ($actor['username'] ?? ''),
        ] : null,
    ];
    return array_merge($payload, $extra);
}

/**
 * Kirim satu payload sekarang (sinkron). Dipakai oleh tes koneksi dan worker.
 *
 * @return array{ok:bool,code:int,error:string}
 */
function webhook_send_payload(
    string $url,
    string $payload,
    string $event,
    string $secret,
    bool $allow_private
): array {
    if (!webhook_url_is_allowed($url, $allow_private)) {
        return ['ok' => false, 'code' => 0, 'error' => 'URL webhook tidak diizinkan (host privat/tidak valid).'];
    }
    $headers = [
        'Content-Type: application/json',
        'X-Ringan-Event: ' . $event,
        'User-Agent: RinganCMS/' . RINGAN_CMS_VERSION . ' Webhook',
    ];
    if ($secret !== '') {
        $headers[] = 'X-Ringan-Signature: sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $body === false ? (string) curl_error($ch) : '';
        curl_close($ch);
        return [
            'ok' => $body !== false && $code >= 200 && $code < 300,
            'code' => $code,
            'error' => $error,
        ];
    }

    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        return ['ok' => false, 'code' => 0, 'error' => 'Butuh ekstensi cURL atau allow_url_fopen.'];
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $payload,
            'timeout' => 5,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $context);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', (string) $http_response_header[0], $m)) {
        $code = (int) $m[1];
    }
    return [
        'ok' => $body !== false && $code >= 200 && $code < 300,
        'code' => $code,
        'error' => $body === false ? 'Gagal menghubungi URL webhook.' : '',
    ];
}

/**
 * Masukkan event ke antrean. Tidak pernah melempar error ke request utama.
 */
function webhook_queue_event(
    string $event,
    ?array $content_type,
    ?int $entry_id,
    ?array $actor,
    array $extra = []
): void {
    try {
        if (!webhook_table_available()) {
            return;
        }
        $settings = webhook_settings();
        if (!$settings['enabled'] || $settings['url'] === '' || !in_array($event, $settings['events'], true)) {
            return;
        }
        $payload = webhook_build_payload($event, $content_type, $entry_id, $actor, $extra);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return;
        }
        $stmt = get_db()->prepare(
            "INSERT INTO webhook_deliveries (event, url, payload, status, attempts, available_at)
             VALUES (?, ?, ?, 'pending', 0, NOW())"
        );
        $stmt->execute([$event, $settings['url'], $json]);
        webhook_register_shutdown();
    } catch (Throwable $e) {
        log_error('Gagal mengantrekan webhook: ' . $e->getMessage(), 'webhook');
    }
}

function webhook_register_shutdown(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;
    register_shutdown_function('webhook_shutdown');
}

/**
 * Dipanggil setelah response selesai; tidak memperlambat editor.
 */
function webhook_shutdown(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
    }
    ignore_user_abort(true);
    @set_time_limit(20);
    try {
        webhook_process_queue(3);
    } catch (Throwable $e) {
        log_error('Worker webhook gagal: ' . $e->getMessage(), 'webhook');
    }
}

/**
 * Kirim antrean yang sudah jatuh tempo. Maksimal 3 percobaan per delivery.
 */
function webhook_process_queue(int $limit = 5): int
{
    $limit = max(1, min(20, $limit));
    if (!webhook_table_available()) {
        return 0;
    }
    try {
        // Pulihkan baris yang tertinggal dalam status sending.
        get_db()->exec(
            "UPDATE webhook_deliveries SET status = 'pending'
             WHERE status = 'sending' AND created_at < (NOW() - INTERVAL 5 MINUTE)"
        );
        $stmt = get_db()->prepare(
            "SELECT * FROM webhook_deliveries
             WHERE status = 'pending' AND available_at <= NOW()
             ORDER BY id ASC LIMIT $limit"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        log_error('Gagal membaca antrean webhook: ' . $e->getMessage(), 'webhook');
        return 0;
    }

    $settings = webhook_settings(true);
    $processed = 0;
    foreach ($rows as $row) {
        $claim = get_db()->prepare(
            "UPDATE webhook_deliveries SET status = 'sending', attempts = attempts + 1
             WHERE id = ? AND status = 'pending'"
        );
        $claim->execute([(int) $row['id']]);
        if ($claim->rowCount() !== 1) {
            continue;
        }
        $attempts = (int) $row['attempts'] + 1;
        $result = webhook_send_payload(
            (string) $row['url'],
            (string) $row['payload'],
            (string) $row['event'],
            (string) $settings['secret'],
            (bool) $settings['allow_private']
        );

        if ($result['ok']) {
            $update = get_db()->prepare(
                "UPDATE webhook_deliveries
                 SET status = 'delivered', response_code = ?, last_error = NULL, delivered_at = NOW()
                 WHERE id = ?"
            );
            $update->execute([$result['code'], (int) $row['id']]);
        } elseif ($attempts >= WEBHOOK_MAX_ATTEMPTS) {
            $update = get_db()->prepare(
                "UPDATE webhook_deliveries
                 SET status = 'failed', response_code = ?, last_error = ?
                 WHERE id = ?"
            );
            $update->execute([
                $result['code'] > 0 ? $result['code'] : null,
                mb_substr($result['error'] !== '' ? $result['error'] : 'Gagal mengirim webhook.', 0, 250),
                (int) $row['id'],
            ]);
        } else {
            $update = get_db()->prepare(
                "UPDATE webhook_deliveries
                 SET status = 'pending', response_code = ?, last_error = ?,
                     available_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
                 WHERE id = ?"
            );
            $update->execute([
                $result['code'] > 0 ? $result['code'] : null,
                mb_substr($result['error'] !== '' ? $result['error'] : 'Gagal mengirim webhook.', 0, 250),
                $attempts * 30,
                (int) $row['id'],
            ]);
        }
        $processed++;
    }
    return $processed;
}

/**
 * Tes koneksi memakai event webhook.test (tidak masuk antrean).
 *
 * @return array{ok:bool,code:int,error:string}
 */
function webhook_send_test(?array $actor = null): array
{
    $settings = webhook_settings(true);
    if ($settings['url'] === '') {
        return ['ok' => false, 'code' => 0, 'error' => 'URL webhook belum diisi.'];
    }
    $payload = webhook_build_payload('webhook.test', null, null, $actor, [
        'message' => 'Tes koneksi dari Ringan CMS.',
    ]);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['ok' => false, 'code' => 0, 'error' => 'Gagal membuat payload.'];
    }
    return webhook_send_payload(
        $settings['url'],
        $json,
        'webhook.test',
        $settings['secret'],
        $settings['allow_private']
    );
}

/**
 * @return array<int,array<string,mixed>>
 */
function webhook_recent_deliveries(int $limit = 20): array
{
    if (!webhook_table_available()) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    try {
        return get_db()->query(
            "SELECT * FROM webhook_deliveries ORDER BY id DESC LIMIT $limit"
        )->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}
