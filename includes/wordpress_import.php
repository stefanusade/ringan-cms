<?php
/**
 * Import konten WordPress (file WXR) ke content type Ringan CMS.
 *
 * Alur kerja: upload → analisis item & meta ACF → verifikasi mapping field →
 * import bertahap (batch). Semua data tetap melewati validate_entry_payload()
 * sehingga whitelist field, validasi tipe, dan sanitasi XSS tetap berlaku.
 *
 * Catatan keamanan: parser XML di file ini sengaja tidak memakai ext-xml.
 * DOCTYPE/ENTITY ditolak sebelum parsing sehingga tidak ada entity expansion
 * (XXE maupun billion laughs). File WXR sendiri hanya dibaca dari
 * storage/tmp/import (di luar webroot) dengan nama acak.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/content_types.php';
require_once __DIR__ . '/content_entries.php';
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/taxonomies.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/audit_log.php';

const WP_IMPORT_SESSION_KEY = 'ringan_wp_import';
const WP_IMPORT_BATCH_SIZE = 20;
const WP_IMPORT_MAX_ITEMS = 5000;
const WP_IMPORT_MAX_VALUE = 262144;
const WP_IMPORT_MEDIA_LIMIT = 20;
const WP_IMPORT_MEDIA_PER_REQUEST = 40;
const WP_IMPORT_TIME_LIMIT = 20;

/* =========================================================================
 * Batas & penyimpanan sementara
 * ========================================================================= */

function wp_import_max_bytes(): int
{
    return max(1048576, (int) env('WP_IMPORT_MAX_BYTES', '33554432'));
}

/**
 * Direktori kerja import (di luar webroot, gitignored lewat /storage/tmp/).
 */
function wp_import_dir(): string
{
    $dir = APP_ROOT . '/storage/tmp/import';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Gagal membuat direktori import.');
    }
    return $dir;
}

/**
 * Hapus berkas import yang lebih tua dari 24 jam.
 */
function wp_import_cleanup(int $max_age = 86400): void
{
    try {
        $files = glob(wp_import_dir() . '/*.json') ?: [];
    } catch (Throwable $e) {
        return;
    }
    $limit = time() - $max_age;
    foreach ($files as $file) {
        $mtime = @filemtime($file);
        if ($mtime !== false && $mtime < $limit) {
            @unlink($file);
        }
    }
}

/**
 * Tabel pemetaan sumber → entry, untuk mencegah duplikasi saat import diulang.
 */
function wp_import_ensure_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        get_db()->exec(
            'CREATE TABLE IF NOT EXISTS import_refs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source VARCHAR(50) NOT NULL,
                source_site VARCHAR(190) NOT NULL DEFAULT \'\',
                source_id VARCHAR(100) NOT NULL,
                content_type_id INT UNSIGNED NOT NULL,
                entry_id INT UNSIGNED NOT NULL,
                imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_import_ref (source, source_site, source_id, content_type_id),
                KEY idx_import_entry (entry_id),
                CONSTRAINT fk_import_entry FOREIGN KEY (entry_id) REFERENCES content_entries(id) ON DELETE CASCADE,
                CONSTRAINT fk_import_ct FOREIGN KEY (content_type_id) REFERENCES content_types(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        // User DB dengan least privilege (tanpa CREATE) tetap bisa import;
        // halaman menampilkan peringatan anti-duplikat nonaktif.
    }
}

/* =========================================================================
 * Parsing WXR (XML) — tanpa ext-xml, aman terhadap XXE
 * ========================================================================= */

/**
 * Decode entitas XML standar + numerik. Tidak ada DTD, jadi tidak ada entitas lain.
 */
function wp_import_xml_decode(string $text): string
{
    if ($text === '' || strpos($text, '&') === false) {
        return $text;
    }
    $decoded = preg_replace_callback(
        '/&(#x[0-9A-Fa-f]+|#[0-9]+|[A-Za-z][A-Za-z0-9]*);/',
        static function (array $m): string {
            $entity = $m[1];
            if ($entity[0] === '#') {
                $code = ($entity[1] === 'x' || $entity[1] === 'X')
                    ? (int) hexdec(substr($entity, 2))
                    : (int) substr($entity, 1);
                if ($code <= 0 || $code > 0x10FFFF) {
                    return '';
                }
                return mb_chr($code, 'UTF-8') ?: '';
            }
            return match ($entity) {
                'amp' => '&',
                'lt' => '<',
                'gt' => '>',
                'quot' => '"',
                'apos' => "'",
                default => $m[0],
            };
        },
        $text
    );
    return $decoded ?? $text;
}

/**
 * Parse dokumen WXR menjadi struktur netral.
 *
 * @return array{wxr_version:string,title:string,site_url:string,blog_url:string,items:array}
 * @throws RuntimeException
 */
function wp_import_parse_xml(string $xml): array
{
    if (strlen($xml) > wp_import_max_bytes()) {
        throw new RuntimeException('File melebihi batas ukuran.');
    }

    $stack = [['name' => '#document', 'attrs' => [], 'text' => '', 'children' => []]];
    $len = strlen($xml);
    $pos = 0;

    while ($pos < $len) {
        $lt = strpos($xml, '<', $pos);
        if ($lt === false) {
            $stack[count($stack) - 1]['text'] .= wp_import_xml_decode(substr($xml, $pos));
            break;
        }
        if ($lt > $pos) {
            $stack[count($stack) - 1]['text'] .= wp_import_xml_decode(substr($xml, $pos, $lt - $pos));
        }

        if (substr($xml, $lt, 9) === '<![CDATA[') {
            $end = strpos($xml, ']]>', $lt + 9);
            if ($end === false) {
                throw new RuntimeException('CDATA tidak tertutup.');
            }
            // CDATA tidak boleh di-decode: isinya literal.
            $stack[count($stack) - 1]['text'] .= substr($xml, $lt + 9, $end - $lt - 9);
            $pos = $end + 3;
            continue;
        }
        if (substr($xml, $lt, 4) === '<!--') {
            $end = strpos($xml, '-->', $lt + 4);
            if ($end === false) {
                throw new RuntimeException('Komentar XML tidak tertutup.');
            }
            $pos = $end + 3;
            continue;
        }
        if (substr($xml, $lt, 2) === '<?') {
            $end = strpos($xml, '?>', $lt + 2);
            if ($end === false) {
                throw new RuntimeException('Processing instruction tidak tertutup.');
            }
            $pos = $end + 2;
            continue;
        }
        if (substr($xml, $lt, 2) === '<!') {
            // DOCTYPE/ENTITY ditolak di posisi dokumen, tetapi teks di dalam CDATA
            // (mis. artikel berisi contoh <!DOCTYPE html>) tetap aman karena
            // CDATA ditangani lebih dulu dan tidak diinterpretasi.
            throw new RuntimeException('Deklarasi XML (DOCTYPE/ENTITY) tidak diizinkan.');
        }

        $gt = strpos($xml, '>', $lt);
        if ($gt === false) {
            throw new RuntimeException('Tag XML tidak tertutup.');
        }
        $raw = trim(substr($xml, $lt + 1, $gt - $lt - 1));
        if ($raw === '') {
            throw new RuntimeException('Tag XML kosong.');
        }
        $self_closing = str_ends_with($raw, '/');
        if ($self_closing) {
            $raw = rtrim(substr($raw, 0, -1));
        }

        if ($raw !== '' && $raw[0] === '/') {
            $name = trim(substr($raw, 1));
            if (count($stack) <= 1) {
                throw new RuntimeException('Tag penutup tidak terduga: </' . $name . '>.');
            }
            $node = array_pop($stack);
            if ($node['name'] !== $name) {
                throw new RuntimeException(sprintf('Tag tidak cocok: </%s> menutup <%s>.', $name, $node['name']));
            }
            $stack[count($stack) - 1]['children'][] = $node;
        } else {
            if (!preg_match('/^([A-Za-z_][A-Za-z0-9_.:-]*)(.*)$/s', $raw, $m)) {
                throw new RuntimeException('Nama tag XML tidak valid.');
            }
            $name = $m[1];
            $attrs = [];
            if (preg_match_all('/([A-Za-z_][A-Za-z0-9_.:-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/s', $m[2], $am, PREG_SET_ORDER)) {
                foreach ($am as $attr) {
                    $attrs[$attr[1]] = wp_import_xml_decode($attr[2] ?? $attr[3] ?? '');
                }
            }
            $stack[] = ['name' => $name, 'attrs' => $attrs, 'text' => '', 'children' => []];
            if ($self_closing) {
                $node = array_pop($stack);
                $stack[count($stack) - 1]['children'][] = $node;
            }
        }
        $pos = $gt + 1;
    }

    if (count($stack) !== 1) {
        throw new RuntimeException('Dokumen XML tidak seimbang (ada tag yang belum ditutup).');
    }

    $rss = wp_import_find_child($stack[0], 'rss');
    if ($rss === null) {
        throw new RuntimeException('Bukan file ekspor WXR: tag <rss> tidak ditemukan.');
    }
    $channel = wp_import_find_child($rss, 'channel');
    if ($channel === null) {
        throw new RuntimeException('Bukan file ekspor WXR: tag <channel> tidak ditemukan.');
    }

    $items = [];
    foreach ($channel['children'] as $child) {
        if ($child['name'] === 'item') {
            $items[] = wp_import_normalize_item($child);
        }
    }

    return [
        'wxr_version' => trim(wp_import_child_text($channel, 'wp:wxr_version')),
        'title' => trim(wp_import_child_text($channel, 'title')),
        'site_url' => trim(wp_import_child_text($channel, 'wp:base_site_url')),
        'blog_url' => trim(wp_import_child_text($channel, 'wp:base_blog_url')),
        'items' => $items,
    ];
}

function wp_import_find_child(array $node, string $name): ?array
{
    foreach ($node['children'] as $child) {
        if ($child['name'] === $name) {
            return $child;
        }
    }
    return null;
}

function wp_import_child_text(array $node, string $name): string
{
    $child = wp_import_find_child($node, $name);
    return $child === null ? '' : (string) $child['text'];
}

/**
 * @return array<string,mixed>
 */
function wp_import_normalize_item(array $node): array
{
    $meta_raw = [];
    $meta = [];
    $terms = [];
    foreach ($node['children'] as $child) {
        if ($child['name'] === 'wp:postmeta') {
            $key = trim(wp_import_child_text($child, 'wp:meta_key'));
            if ($key === '') {
                continue;
            }
            $value = (string) wp_import_child_text($child, 'wp:meta_value');
            $meta_raw[$key] = $value;
            $meta[$key] = wp_import_safe_unserialize($value);
        } elseif ($child['name'] === 'category') {
            $terms[] = [
                'domain' => (string) ($child['attrs']['domain'] ?? ''),
                'slug' => (string) ($child['attrs']['nicename'] ?? ''),
                'name' => trim((string) $child['text']),
            ];
        }
    }

    return [
        'wp_id' => trim(wp_import_child_text($node, 'wp:post_id')),
        'post_type' => trim(wp_import_child_text($node, 'wp:post_type')) ?: 'post',
        'status' => strtolower(trim(wp_import_child_text($node, 'wp:status'))),
        'title' => trim(wp_import_child_text($node, 'title')),
        'slug' => trim(wp_import_child_text($node, 'wp:post_name')),
        'link' => trim(wp_import_child_text($node, 'link')),
        'date' => trim(wp_import_child_text($node, 'wp:post_date')),
        'date_gmt' => trim(wp_import_child_text($node, 'wp:post_date_gmt')),
        'modified' => trim(wp_import_child_text($node, 'wp:post_modified')),
        'author' => trim(wp_import_child_text($node, 'dc:creator')),
        'content' => trim(wp_import_child_text($node, 'content:encoded')),
        'excerpt' => trim(wp_import_child_text($node, 'excerpt:encoded')),
        'mime' => trim(wp_import_child_text($node, 'wp:post_mime_type')),
        'attachment_url' => trim(wp_import_child_text($node, 'wp:attachment_url')),
        'meta_raw' => $meta_raw,
        'meta' => $meta,
        'terms' => $terms,
    ];
}

/**
 * Unserialize terbatas: hanya array/scalar, tanpa objek (cegah object injection).
 */
function wp_import_safe_unserialize(string $raw): mixed
{
    $raw = trim($raw);
    if ($raw === '' || strlen($raw) > WP_IMPORT_MAX_VALUE) {
        return $raw;
    }
    if (str_contains($raw, 'O:') && preg_match('/O:\d+:"/', $raw)) {
        return $raw;
    }
    if (!preg_match('/^(?:a:\d+:\{|s:\d+:|i:-?\d+;|d:[-\d.Ee+]+;|b:[01];|N;)/', $raw)) {
        return $raw;
    }
    $value = @unserialize($raw, ['allowed_classes' => false]);
    if ($value === false && !preg_match('/^b:0;$/', $raw)) {
        return $raw;
    }
    if (is_object($value)) {
        return $raw;
    }
    return $value;
}

function wp_import_parse_file(string $path): array
{
    $xml = @file_get_contents($path);
    if ($xml === false) {
        throw new RuntimeException('Gagal membaca file WXR.');
    }
    if (strlen($xml) > wp_import_max_bytes()) {
        throw new RuntimeException('File melebihi batas ukuran.');
    }
    $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml) ?? $xml;
    return wp_import_parse_xml($xml);
}

/* =========================================================================
 * Upload, penyimpanan item, dan state sesi
 * ========================================================================= */

/**
 * Validasi file WXR yang diunggah lalu simpan item hasil parsing sebagai JSON.
 *
 * @return array{ok:true,token:string,count:int,site_url:string,blog_url:string,wxr_version:string}|array{ok:false,error:string}
 */
function wp_import_store_upload(array $file, int $user_id): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = match ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Ukuran file melebihi batas server.',
            UPLOAD_ERR_PARTIAL => 'File hanya ter-upload sebagian.',
            UPLOAD_ERR_NO_FILE => 'Pilih file WXR terlebih dahulu.',
            UPLOAD_ERR_NO_TMP_DIR => 'Direktori temporary server tidak tersedia.',
            UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file di server.',
            UPLOAD_ERR_EXTENSION => 'Upload diblokir oleh ekstensi server.',
            default => 'Terjadi kesalahan saat upload.',
        };
        return ['ok' => false, 'error' => $error];
    }
    if (($file['size'] ?? 0) <= 0) {
        return ['ok' => false, 'error' => 'File kosong.'];
    }
    if (($file['size'] ?? 0) > wp_import_max_bytes()) {
        return ['ok' => false, 'error' => 'Ukuran file maksimal ' . round(wp_import_max_bytes() / 1048576, 1) . ' MB.'];
    }
    if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        return ['ok' => false, 'error' => 'File upload tidak valid.'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file((string) $file['tmp_name']);
    if (!in_array($mime, ['text/xml', 'application/xml', 'text/plain', 'text/html', 'application/octet-stream'], true)) {
        return ['ok' => false, 'error' => 'File harus berupa XML (WXR).'];
    }

    $xml = @file_get_contents((string) $file['tmp_name'], false, null, 0, wp_import_max_bytes() + 1);
    if ($xml === false) {
        return ['ok' => false, 'error' => 'Gagal membaca file upload.'];
    }
    if (strlen($xml) > wp_import_max_bytes()) {
        return ['ok' => false, 'error' => 'Ukuran file melebihi batas.'];
    }
    $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml) ?? $xml;
    $xml = ltrim($xml, " \t\r\n");
    if (!str_starts_with($xml, '<?xml') && !str_starts_with($xml, '<rss')) {
        return ['ok' => false, 'error' => 'File bukan XML/WXR yang valid.'];
    }

    try {
        $parsed = wp_import_parse_xml($xml);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Gagal membaca WXR: ' . $e->getMessage()];
    }
    if ($parsed['items'] === []) {
        return ['ok' => false, 'error' => 'File WXR tidak berisi item.'];
    }
    if (count($parsed['items']) > WP_IMPORT_MAX_ITEMS) {
        return ['ok' => false, 'error' => 'Terlalu banyak item (maks ' . WP_IMPORT_MAX_ITEMS . '). Pecah file WXR menjadi beberapa bagian.'];
    }

    $token = bin2hex(random_bytes(16));
    $payload = [
        'site_url' => $parsed['site_url'],
        'blog_url' => $parsed['blog_url'],
        'title' => $parsed['title'],
        'wxr_version' => $parsed['wxr_version'],
        'items' => $parsed['items'],
    ];
    if (!wp_import_save_items($token, $payload)) {
        return ['ok' => false, 'error' => 'Gagal menyimpan data hasil parsing.'];
    }

    return [
        'ok' => true,
        'token' => $token,
        'count' => count($parsed['items']),
        'site_url' => $parsed['site_url'],
        'blog_url' => $parsed['blog_url'],
        'wxr_version' => $parsed['wxr_version'],
    ];
}

function wp_import_items_path(string $token): string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return '';
    }
    return APP_ROOT . '/storage/tmp/import/' . $token . '.json';
}

function wp_import_save_items(string $token, array $payload): bool
{
    $path = wp_import_items_path($token);
    if ($path === '') {
        return false;
    }
    try {
        wp_import_dir();
    } catch (Throwable $e) {
        log_error('Direktori import tidak bisa dibuat: ' . $e->getMessage(), 'import');
        return false;
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        return false;
    }
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0640);
    return @rename($tmp, $path);
}

/**
 * @return array{site_url:string,blog_url:string,title:string,wxr_version:string,items:array}|null
 */
function wp_import_load_items(string $token): ?array
{
    $path = wp_import_items_path($token);
    if ($path === '' || !is_file($path)) {
        return null;
    }
    $json = @file_get_contents($path);
    if ($json === false) {
        return null;
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) && isset($decoded['items']) && is_array($decoded['items']) ? $decoded : null;
}

function wp_import_destroy(string $token): void
{
    $path = wp_import_items_path($token);
    if ($path !== '') {
        @unlink($path);
    }
    wp_import_state_clear();
}

function wp_import_state_start(array $state): void
{
    start_session();
    $existing = $_SESSION[WP_IMPORT_SESSION_KEY] ?? null;
    if (is_array($existing) && isset($existing['token'], $state['token']) && $existing['token'] !== $state['token']) {
        $old_path = wp_import_items_path((string) $existing['token']);
        if ($old_path !== '') {
            @unlink($old_path);
        }
    }
    $_SESSION[WP_IMPORT_SESSION_KEY] = $state;
}

function wp_import_state_get(): ?array
{
    start_session();
    $state = $_SESSION[WP_IMPORT_SESSION_KEY] ?? null;
    return is_array($state) && isset($state['token']) ? $state : null;
}

function wp_import_state_update(array $patch): void
{
    $state = wp_import_state_get();
    if ($state === null) {
        return;
    }
    $_SESSION[WP_IMPORT_SESSION_KEY] = array_merge($state, $patch);
}

function wp_import_state_clear(): void
{
    start_session();
    unset($_SESSION[WP_IMPORT_SESSION_KEY]);
}

function wp_import_state_matches(array $state, string $token): bool
{
    return isset($state['token']) && is_string($state['token']) && hash_equals($state['token'], $token);
}

/* =========================================================================
 * Analisis item & pemetaan field
 * ========================================================================= */

/**
 * @return array<string,int> post_type => jumlah
 */
function wp_import_post_type_counts(array $items): array
{
    $counts = [];
    foreach ($items as $item) {
        $type = (string) ($item['post_type'] ?? 'post');
        if ($type === '') {
            $type = 'post';
        }
        $counts[$type] = ($counts[$type] ?? 0) + 1;
    }
    arsort($counts);
    return $counts;
}

/**
 * Item yang akan diimport: sesuai post type, tanpa status sampah.
 */
function wp_import_select_items(array $items, string $post_type): array
{
    $skip = ['trash', 'auto-draft', 'inherit', 'spam'];
    $selected = [];
    foreach ($items as $item) {
        if ((string) ($item['post_type'] ?? 'post') !== $post_type) {
            continue;
        }
        if (in_array((string) ($item['status'] ?? ''), $skip, true)) {
            continue;
        }
        $selected[] = $item;
    }
    return $selected;
}

/**
 * Ringkasan meta key untuk UI pemetaan.
 *
 * @return array{keys:array<string,array{count:int,samples:string[],serialized:bool,array:bool}>,repeaters:array<string,int>,total_items:int}
 */
function wp_import_meta_summary(array $items, string $post_type): array
{
    $keys = [];
    $repeaters = [];
    $selected = wp_import_select_items($items, $post_type);
    foreach ($selected as $item) {
        foreach ($item['meta_raw'] ?? [] as $key => $raw) {
            $key = (string) $key;
            if ($key === '' || $key[0] === '_') {
                continue; // meta internal ACF (field key) & WP
            }
            if (!isset($keys[$key])) {
                $keys[$key] = ['count' => 0, 'samples' => [], 'serialized' => false, 'array' => false];
            }
            $value = (string) $raw;
            if (trim($value) !== '') {
                $keys[$key]['count']++;
            }
            if (count($keys[$key]['samples']) < 3 && $value !== '') {
                $keys[$key]['samples'][] = mb_substr($value, 0, 120);
            }
            if (preg_match('/^(?:a:\d+:\{|s:\d+:|i:-?\d+;|d:|b:[01];)/', trim($value))) {
                $keys[$key]['serialized'] = true;
            }
            if (is_array($item['meta'][$key] ?? null)) {
                $keys[$key]['array'] = true;
            }
            if (preg_match('/^(.+?)_\d+_[A-Za-z0-9_]+$/', $key, $m)) {
                $repeaters[$m[1]] = ($repeaters[$m[1]] ?? 0) + 1;
            }
        }
    }
    ksort($keys);
    ksort($repeaters);
    return ['keys' => $keys, 'repeaters' => $repeaters, 'total_items' => count($selected)];
}

/**
 * Sumber yang boleh dipakai di mapping.
 *
 * @return array<string,string> source => label
 */
function wp_import_source_choices(array $summary): array
{
    $choices = [
        'post:title' => 'Judul post',
        'post:content' => 'Konten (HTML)',
        'post:excerpt' => 'Excerpt',
        'post:slug' => 'Slug',
        'post:date' => 'Tanggal publish',
        'post:modified' => 'Tanggal modifikasi',
        'post:status' => 'Status WordPress',
        'post:author' => 'Author',
    ];
    foreach ($summary['keys'] as $key => $info) {
        $label = 'Meta: ' . $key . ' (' . (int) $info['count'] . ' nilai)';
        if ($info['array']) {
            $label .= ' [array]';
        } elseif ($info['serialized']) {
            $label .= ' [serialized]';
        }
        $choices['meta:' . $key] = $label;
    }
    return $choices;
}

function wp_import_source_is_valid(string $source, array $summary): bool
{
    if (!str_starts_with($source, 'meta:')) {
        return array_key_exists($source, wp_import_source_choices(['keys' => [], 'repeaters' => [], 'total_items' => 0]));
    }
    $key = substr($source, 5);
    return isset($summary['keys'][$key]);
}

/**
 * Tebak mapping awal berdasarkan nama field/key meta.
 *
 * @return array<string,string> field_key => source
 */
function wp_import_default_mapping(array $fields, array $summary): array
{
    $aliases = [
        'title' => 'post:title', 'judul' => 'post:title', 'post_title' => 'post:title', 'nama' => 'post:title',
        'content' => 'post:content', 'body' => 'post:content', 'konten' => 'post:content', 'isi' => 'post:content',
        'excerpt' => 'post:excerpt', 'ringkasan' => 'post:excerpt',
        'slug' => 'post:slug', 'post_name' => 'post:slug',
        'date' => 'post:date', 'tanggal' => 'post:date', 'published_at' => 'post:date', 'publish_date' => 'post:date',
        'status' => 'post:status', 'author' => 'post:author', 'penulis' => 'post:author',
    ];
    $mapping = [];
    foreach ($fields as $field) {
        $key = (string) $field['field_key'];
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $key));
        $normalized = trim($normalized, '_');
        if (isset($summary['keys'][$key])) {
            $mapping[$key] = 'meta:' . $key;
        } elseif (isset($aliases[$normalized])) {
            $mapping[$key] = $aliases[$normalized];
        } elseif (isset($summary['keys'][$normalized])) {
            $mapping[$key] = 'meta:' . $normalized;
        } else {
            $mapping[$key] = '';
        }
    }
    return $mapping;
}

function wp_import_clean_mapping(array $raw, array $fields, array $summary): array
{
    $allowed = wp_import_source_choices($summary);
    $mapping = [];
    foreach ($fields as $field) {
        $key = (string) $field['field_key'];
        $source = isset($raw[$key]) && is_string($raw[$key]) ? trim($raw[$key]) : '';
        $mapping[$key] = ($source !== '' && isset($allowed[$source])) ? $source : '';
    }
    return $mapping;
}

function wp_import_extract_source(array $item, string $source): mixed
{
    if (str_starts_with($source, 'meta:')) {
        return $item['meta'][substr($source, 5)] ?? null;
    }
    return match ($source) {
        'post:title' => $item['title'] ?? '',
        'post:content' => $item['content'] ?? '',
        'post:excerpt' => $item['excerpt'] ?? '',
        'post:slug' => $item['slug'] ?? '',
        'post:date' => $item['date'] ?? '',
        'post:modified' => $item['modified'] ?? '',
        'post:status' => $item['status'] ?? '',
        'post:author' => $item['author'] ?? '',
        default => null,
    };
}

/**
 * ACF date picker menyimpan 'Ymd' (mis. 20240315).
 */
function wp_import_normalize_date(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\d{8}$/', $value)) {
        $date = DateTimeImmutable::createFromFormat('!Ymd', $value);
        if ($date instanceof DateTimeImmutable && $date->format('Ymd') === $value) {
            return $date->format('Y-m-d');
        }
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
        return normalize_date(substr($value, 0, 10));
    }
    return normalize_date($value);
}

/**
 * Ubah nilai mentah menjadi bentuk yang diterima validate_field_value().
 * Nilai yang tidak valid untuk tipe opsional dikembalikan null + warning.
 */
function wp_import_coerce_value(array $field, mixed $value, array &$warnings): mixed
{
    $type = (string) ($field['field_type'] ?? 'text');
    $options = decode_options($field['options_json'] ?? null);
    $label = (string) ($field['label'] ?? $field['field_key'] ?? '');

    if (is_array($value)) {
        $flat = [];
        array_walk_recursive($value, static function ($v) use (&$flat): void {
            if (is_scalar($v)) {
                $flat[] = (string) $v;
            }
        });
        $value = implode(', ', $flat);
    }
    if (is_bool($value)) {
        $value = $value ? '1' : '0';
    }
    if (!is_scalar($value)) {
        return null;
    }
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    switch ($type) {
        case 'text':
        case 'textarea':
            $max = (int) ($options['max_length'] ?? ($type === 'text' ? 255 : 10000));
            $clean = sanitize_text($value);
            if (mb_strlen($clean) > $max) {
                $clean = mb_substr($clean, 0, $max);
                $warnings[] = sprintf('Field "%s" dipotong ke %d karakter.', $label, $max);
            }
            return $clean;

        case 'richtext':
            return $value;

        case 'number':
            if (!is_numeric($value)) {
                $warnings[] = sprintf('Field "%s" berisi nilai non-angka dan dikosongkan.', $label);
                return null;
            }
            return $value;

        case 'boolean':
            $bool = sanitize_boolean($value);
            if ($bool === null) {
                $warnings[] = sprintf('Field "%s" berisi nilai boolean tidak dikenal dan dikosongkan.', $label);
                return null;
            }
            return $bool ? 1 : 0;

        case 'date':
            $date = wp_import_normalize_date($value);
            if ($date === null) {
                $warnings[] = sprintf('Field "%s" berisi tanggal tidak valid dan dikosongkan.', $label);
                return null;
            }
            return $date;

        case 'select':
            $allowed = $options['options'] ?? [];
            if (!validate_in_enum($allowed, $value)) {
                $warnings[] = sprintf('Field "%s" berisi nilai di luar pilihan dan dikosongkan.', $label);
                return null;
            }
            return $value;

        case 'relation':
            $warnings[] = sprintf('Field relasi "%s" belum didukung oleh import WordPress; dilewati.', $label);
            return null;

        default:
            return sanitize_text($value);
    }
}

/* =========================================================================
 * Media (gambar/file ACF)
 * ========================================================================= */

/**
 * Peta item attachment WXR: wp_id => url/file/name.
 *
 * @return array<string,array{url:string,file:string,name:string,mime:string}>
 */
function wp_import_attachments(array $items, string $blog_url): array
{
    $map = [];
    $base = rtrim($blog_url, '/');
    foreach ($items as $item) {
        if ((string) ($item['post_type'] ?? '') !== 'attachment') {
            continue;
        }
        $id = (string) ($item['wp_id'] ?? '');
        if ($id === '' || !ctype_digit($id)) {
            continue;
        }
        $file = (string) ($item['meta_raw']['_wp_attached_file'] ?? '');
        $url = (string) ($item['attachment_url'] ?? '');
        if ($url === '' && $file !== '' && $base !== '') {
            $url = $base . '/wp-content/uploads/' . ltrim($file, '/');
        }
        $map[$id] = [
            'url' => $url,
            'file' => $file,
            'name' => $file !== '' ? basename($file) : basename((string) parse_url($url, PHP_URL_PATH)),
            'mime' => (string) ($item['mime'] ?? ''),
        ];
    }
    return $map;
}

/**
 * Normalisasi nilai field image/file/gallery ACF menjadi daftar referensi.
 *
 * @return array<int,array{id:int,url:string,file:string,name:string}>
 */
function wp_import_media_refs(mixed $value): array
{
    if ($value === null || $value === '' || $value === []) {
        return [];
    }
    if (is_array($value)) {
        $refs = [];
        $is_single = isset($value['url']) || isset($value['id']) || isset($value['ID']) || isset($value['filename']);
        if ($is_single) {
            $refs[] = [
                'id' => (int) ($value['id'] ?? $value['ID'] ?? 0),
                'url' => (string) ($value['url'] ?? ''),
                'file' => (string) ($value['filename'] ?? ''),
                'name' => (string) ($value['filename'] ?? ''),
            ];
            return $refs;
        }
        foreach ($value as $entry) {
            foreach (wp_import_media_refs($entry) as $ref) {
                $refs[] = $ref;
            }
        }
        return $refs;
    }
    if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
        return [['id' => (int) trim((string) $value), 'url' => '', 'file' => '', 'name' => '']];
    }
    if (!is_string($value)) {
        return [];
    }
    $value = trim($value);
    if ($value === '') {
        return [];
    }
    if (preg_match('/^\d+(\s*,\s*\d+)+$/', $value)) {
        $refs = [];
        foreach (explode(',', $value) as $id) {
            $refs[] = ['id' => (int) trim($id), 'url' => '', 'file' => '', 'name' => ''];
        }
        return $refs;
    }
    if (preg_match('#^https?://#i', $value)) {
        return [['id' => 0, 'url' => $value, 'file' => '', 'name' => basename((string) parse_url($value, PHP_URL_PATH))]];
    }
    return [['id' => 0, 'url' => '', 'file' => $value, 'name' => basename($value)]];
}

function wp_import_ref_url(array $ref, array $attachments, string $blog_url): ?string
{
    if (($ref['url'] ?? '') !== '') {
        return (string) $ref['url'];
    }
    $id = (string) ($ref['id'] ?? 0);
    if ($id !== '0' && isset($attachments[$id]) && $attachments[$id]['url'] !== '') {
        return $attachments[$id]['url'];
    }
    $file = (string) ($ref['file'] ?? '');
    if ($file !== '' && $blog_url !== '') {
        return rtrim($blog_url, '/') . '/wp-content/uploads/' . ltrim($file, '/');
    }
    return null;
}

/**
 * Tolak URL non-publik (SSRF): hanya http/https, tanpa kredensial, IP publik.
 */
function wp_import_url_is_public(string $url): bool
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
    if (isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true)) {
        return false;
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host === '') {
        return false;
    }
    static $host_cache = [];
    if (isset($host_cache[$host])) {
        return $host_cache[$host];
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return $host_cache[$host] = wp_import_ip_is_public($host);
    }
    $ips = @gethostbynamel($host);
    if (!is_array($ips) || $ips === []) {
        return $host_cache[$host] = false;
    }
    foreach ($ips as $ip) {
        if (!wp_import_ip_is_public($ip)) {
            return $host_cache[$host] = false;
        }
    }
    return $host_cache[$host] = true;
}

function wp_import_ip_is_public(string $ip): bool
{
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

/**
 * Ambil URL dengan batas ukuran, tanpa redirect.
 *
 * @return array{ok:true,body:string}|array{ok:false,error:string}
 */
function wp_import_http_get(string $url, int $max_bytes): array
{
    if (!wp_import_url_is_public($url)) {
        return ['ok' => false, 'error' => 'URL media tidak diizinkan (host privat/tidak valid).'];
    }
    if (function_exists('curl_init')) {
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'RinganCMS/' . RINGAN_CMS_VERSION . ' WordPress-Import',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAXFILESIZE => $max_bytes,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $max_bytes): int {
                if (strlen($body) + strlen($chunk) > $max_bytes) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($ok === false || $code !== 200 || $body === '') {
            return ['ok' => false, 'error' => 'Gagal mengunduh media.'];
        }
        return ['ok' => true, 'body' => $body];
    }

    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        return ['ok' => false, 'error' => 'Unduh media butuh cURL atau allow_url_fopen.'];
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'follow_location' => 0,
            'max_redirects' => 0,
            'user_agent' => 'RinganCMS/' . RINGAN_CMS_VERSION . ' WordPress-Import',
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $handle = @fopen($url, 'rb', false, $context);
    if ($handle === false) {
        return ['ok' => false, 'error' => 'Gagal mengunduh media.'];
    }
    $body = '';
    while (!feof($handle)) {
        $chunk = fread($handle, 65536);
        if ($chunk === false) {
            fclose($handle);
            return ['ok' => false, 'error' => 'Gagal membaca media.'];
        }
        $body .= $chunk;
        if (strlen($body) > $max_bytes) {
            fclose($handle);
            return ['ok' => false, 'error' => 'Media melebihi batas ukuran.'];
        }
    }
    fclose($handle);
    return $body === '' ? ['ok' => false, 'error' => 'Media kosong.'] : ['ok' => true, 'body' => $body];
}

/**
 * Unduh & simpan satu referensi media. Hasil di-cache per URL/ID selama sesi.
 */
function wp_import_resolve_media(
    array $ref,
    array $attachments,
    string $blog_url,
    bool $download,
    int $user_id,
    array &$cache,
    array &$warnings,
    bool $image_only
): ?string {
    $url = wp_import_ref_url($ref, $attachments, $blog_url);
    if ($url === null || $url === '') {
        $warnings[] = 'Referensi media tidak ditemukan di file WXR (export media sertakan lampiran).';
        return null;
    }
    $cache_key = sha1($url);
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }
    if (!$download) {
        return null;
    }
    static $downloads = 0;
    if ($downloads >= WP_IMPORT_MEDIA_PER_REQUEST) {
        $warnings[] = 'Batas unduhan media per batch tercapai; media sisanya diproses pada batch berikutnya.';
        return null;
    }
    $downloads++;
    $max = min(MAX_UPLOAD_SIZE, 10485760);
    $response = wp_import_http_get($url, $max);
    if (!$response['ok']) {
        $warnings[] = 'Media dilewati: ' . $response['error'];
        return null;
    }
    $name = (string) ($ref['name'] ?? '');
    if ($name === '' && isset($attachments[(string) ($ref['id'] ?? '0')])) {
        $name = (string) $attachments[(string) $ref['id']]['name'];
    }
    $saved = save_binary_upload($response['body'], $image_only, $user_id, $name);
    if (!$saved['ok']) {
        $warnings[] = 'Media dilewati: ' . $saved['error'];
        return null;
    }
    if (count($cache) < 2000) {
        $cache[$cache_key] = $saved['path'];
    }
    return $saved['path'];
}

/* =========================================================================
 * Verifikasi mapping (wajib sebelum import)
 * ========================================================================= */

/**
 * @return array{ok:bool,blocking:string[],warnings:string[],fields:array,items_total:int,statuses:array<string,int>}
 */
function wp_import_verify(array $fields, array $mapping, array $items, array $options): array
{
    $post_type = (string) ($options['post_type'] ?? 'post');
    $blog_url = (string) ($options['blog_url'] ?? '');
    $download_media = !empty($options['download_media']);

    $result = [
        'ok' => true,
        'blocking' => [],
        'warnings' => [],
        'fields' => [],
        'items_total' => 0,
        'statuses' => [],
    ];

    $selected = wp_import_select_items($items, $post_type);
    $result['items_total'] = count($selected);
    if ($fields === []) {
        $result['blocking'][] = 'Content type tujuan belum memiliki field; tambahkan field terlebih dahulu.';
        $result['ok'] = false;
        return $result;
    }
    if ($selected === []) {
        $result['blocking'][] = 'Tidak ada item dengan post type "' . $post_type . '" pada file ini.';
        $result['ok'] = false;
        return $result;
    }

    foreach ($selected as $item) {
        $status = wp_import_status_for($item, $options);
        $result['statuses'][$status] = ($result['statuses'][$status] ?? 0) + 1;
    }

    $attachments = wp_import_attachments($items, $blog_url);
    $summary = wp_import_meta_summary($items, $post_type);
    $samples = array_slice($selected, 0, 100);
    $sample_count = count($samples);

    foreach ($fields as $field) {
        $key = (string) $field['field_key'];
        $type = (string) $field['field_type'];
        $required = (bool) $field['is_required'];
        $source = trim((string) ($mapping[$key] ?? ''));

        $row = [
            'key' => $key,
            'label' => (string) $field['label'],
            'type' => $type,
            'required' => $required,
            'source' => $source,
            'filled' => 0,
            'total' => $sample_count,
            'status' => 'ok',
            'notes' => [],
            'samples' => [],
        ];

        if ($source === '') {
            if ($required) {
                $row['status'] = 'error';
                $row['notes'][] = 'Field wajib belum dipetakan.';
                $result['blocking'][] = sprintf('Field wajib "%s" belum dipetakan ke sumber WordPress.', $field['label']);
            } else {
                $row['status'] = 'skip';
                $row['notes'][] = 'Tidak dipetakan (dilewati).';
            }
            $result['fields'][$key] = $row;
            continue;
        }

        if (!wp_import_source_is_valid($source, $summary)) {
            $row['status'] = 'error';
            $row['notes'][] = 'Sumber tidak dikenal: ' . $source;
            $result['blocking'][] = sprintf('Mapping field "%s" memakai sumber yang tidak ada.', $field['label']);
            $result['fields'][$key] = $row;
            continue;
        }

        if ($type === 'relation') {
            $row['status'] = 'error';
            $row['notes'][] = 'Tipe field relation belum didukung oleh import WordPress.';
            $result['blocking'][] = sprintf('Field relasi "%s" tidak bisa diimport otomatis; lewati atau ubah mapping.', $field['label']);
            $result['fields'][$key] = $row;
            continue;
        }

        if (in_array($type, ['image', 'file', 'gallery'], true) && !$download_media) {
            $row['status'] = $required ? 'error' : 'warn';
            $row['notes'][] = 'Aktifkan "Unduh media" agar field gambar/file bisa diisi.';
            if ($required) {
                $result['blocking'][] = sprintf('Field wajib "%s" butuh unduh media diaktifkan.', $field['label']);
            } else {
                $result['warnings'][] = sprintf('Field "%s" akan kosong karena unduh media nonaktif.', $field['label']);
            }
            $result['fields'][$key] = $row;
            continue;
        }

        $filled = 0;
        $invalid = 0;
        $unresolved = 0;
        $preview = [];
        $notes = [];
        foreach ($samples as $item) {
            $raw = wp_import_extract_source($item, $source);
            if ($raw === null || $raw === '' || $raw === []) {
                continue;
            }
            $filled++;
            if (count($preview) < 3) {
                $preview[] = wp_import_preview_value($raw);
            }
            if (in_array($type, ['image', 'file', 'gallery'], true)) {
                $refs = wp_import_media_refs($raw);
                if (count($refs) > WP_IMPORT_MEDIA_LIMIT) {
                    $refs = array_slice($refs, 0, WP_IMPORT_MEDIA_LIMIT);
                    $notes[] = 'Galeri dibatasi ' . WP_IMPORT_MEDIA_LIMIT . ' gambar per entri.';
                }
                if ($refs === []) {
                    $invalid++;
                    continue;
                }
                foreach ($refs as $ref) {
                    $url = wp_import_ref_url($ref, $attachments, $blog_url);
                    if ($url === null || !wp_import_url_is_public($url)) {
                        $unresolved++;
                    }
                }
            } else {
                $warnings = [];
                $value = wp_import_coerce_value($field, $raw, $warnings);
                $raw_text = is_array($raw) ? wp_import_preview_value($raw) : trim((string) $raw);
                if ($value === null && $raw_text !== '') {
                    $invalid++;
                }
                foreach ($warnings as $warning) {
                    if (!in_array($warning, $notes, true)) {
                        $notes[] = $warning;
                    }
                }
            }
        }

        $row['filled'] = $filled;
        $row['samples'] = $preview;

        if ($filled === 0) {
            $row['status'] = $required ? 'error' : 'warn';
            $notes[] = 'Tidak ada nilai pada ' . $sample_count . ' item sampel.';
            if ($required) {
                $result['blocking'][] = sprintf('Field wajib "%s" tidak memiliki nilai pada file WXR.', $field['label']);
            } else {
                $result['warnings'][] = sprintf('Field "%s" tidak memiliki nilai pada sampel.', $field['label']);
            }
        }
        if ($invalid > 0) {
            $row['status'] = $required ? 'error' : 'warn';
            $notes[] = $invalid . ' nilai tidak valid untuk tipe ' . $type . '.';
            if ($required) {
                $result['blocking'][] = sprintf('Field wajib "%s" memiliki nilai tidak valid.', $field['label']);
            } else {
                $result['warnings'][] = sprintf('Field "%s": %d nilai tidak valid akan dikosongkan.', $field['label'], $invalid);
            }
        }
        if ($unresolved > 0) {
            $row['status'] = $required ? 'error' : 'warn';
            $notes[] = $unresolved . ' referensi media tidak bisa diunduh.';
            if ($required) {
                $result['blocking'][] = sprintf('Field wajib "%s": referensi media tidak dapat diunduh.', $field['label']);
            } else {
                $result['warnings'][] = sprintf('Field "%s": %d referensi media dilewati.', $field['label'], $unresolved);
            }
        }

        $row['notes'] = array_values(array_unique($notes));
        $result['fields'][$key] = $row;
    }

    $result['ok'] = $result['blocking'] === [];
    $result['blocking'] = array_values(array_unique($result['blocking']));
    $result['warnings'] = array_values(array_unique($result['warnings']));
    return $result;
}

function wp_import_preview_value(mixed $value): string
{
    if (is_array($value)) {
        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
    }
    return mb_substr(trim((string) $value), 0, 100);
}

function wp_import_status_for(array $item, array $options): string
{
    if (($options['status_mode'] ?? 'map') === 'fixed') {
        $status = (string) ($options['default_status'] ?? 'draft');
        return in_array($status, entry_statuses(), true) ? $status : 'draft';
    }
    $wp_status = strtolower((string) ($item['status'] ?? ''));
    if (in_array($wp_status, ['trash', 'auto-draft', 'inherit', 'spam'], true)) {
        return 'skip';
    }
    return match ($wp_status) {
        'publish' => 'published',
        default => 'draft',
    };
}

/* =========================================================================
 * Eksekusi import (batch)
 * ========================================================================= */

/**
 * @param array $state state sesi (offset, stats, media_cache diperbarui)
 * @return array{results:array,total:int,offset:int,finished:bool}
 */
function wp_import_run_batch(array &$state, array $fields, array $user, int $limit = WP_IMPORT_BATCH_SIZE): array
{
    $payload = wp_import_load_items((string) $state['token']);
    if ($payload === null) {
        throw new RuntimeException('Data import tidak ditemukan atau sudah kedaluwarsa. Ulangi upload.');
    }

    $ct_id = (int) $state['ct_id'];
    $post_type = (string) $state['post_type'];
    $mapping = is_array($state['mapping'] ?? null) ? $state['mapping'] : [];
    $options = is_array($state['options'] ?? null) ? $state['options'] : [];
    $options['post_type'] = $post_type;
    $options['blog_url'] = (string) ($state['blog_url'] ?? '');

    $all_items = wp_import_select_items($payload['items'], $post_type);
    $total = count($all_items);
    $offset = max(0, (int) ($state['offset'] ?? 0));
    $slice = array_slice($all_items, $offset, max(1, $limit));

    $attachments = wp_import_attachments($payload['items'], (string) ($state['blog_url'] ?? ''));
    $fields_by_key = [];
    $decorated = [];
    foreach ($fields as $field) {
        $field['options'] = decode_options($field['options_json'] ?? null);
        $decorated[] = $field;
        $fields_by_key[(string) $field['field_key']] = $field;
    }
    $taxonomies = !empty($options['import_terms']) && can_manage_taxonomies($user)
        ? get_taxonomies($ct_id)
        : [];
    $site_key = wp_import_site_key((string) ($state['blog_url'] ?? ''));
    $media_cache = is_array($state['media_cache'] ?? null) ? $state['media_cache'] : [];
    $can_create_terms = !empty($options['create_terms']) && can_manage_taxonomies($user);

    $results = ['imported' => [], 'updated' => [], 'skipped' => [], 'failed' => [], 'downgraded' => []];
    $deadline = microtime(true) + WP_IMPORT_TIME_LIMIT;
    $processed = 0;

    foreach ($slice as $item) {
        if (microtime(true) > $deadline) {
            break;
        }
        $processed++;
        $wp_id = (string) ($item['wp_id'] ?? '');
        $title = (string) ($item['title'] ?? '');
        $label = $title !== '' ? $title : ('#' . $wp_id);
        try {
            $status = wp_import_status_for($item, $options);
            if ($status === 'skip') {
                $results['skipped'][] = ['title' => $label, 'reason' => 'Status WordPress tidak diimport (' . (string) $item['status'] . ').'];
                continue;
            }

            $warnings = [];
            $data = wp_import_build_payload(
                $item,
                $mapping,
                $fields_by_key,
                $attachments,
                $options,
                (int) $user['id'],
                $media_cache,
                $warnings
            );

            $validation = validate_entry_payload($data, $decorated, false, $status !== 'draft');
            $downgraded = false;
            if (!$validation['ok'] && $status !== 'draft' && !empty($options['fallback_draft'])) {
                $validation = validate_entry_payload($data, $decorated, false, false);
                if ($validation['ok']) {
                    $status = 'draft';
                    $downgraded = true;
                    $warnings[] = 'Disimpan sebagai draft karena field wajib belum lengkap.';
                }
            }
            if (!$validation['ok']) {
                $results['failed'][] = [
                    'title' => $label,
                    'reason' => wp_import_flatten_errors($validation['errors']),
                ];
                continue;
            }
            $data = $validation['data'];

            $existing = null;
            if ($wp_id !== '') {
                $existing = wp_import_ref_find($site_key, $wp_id, $ct_id);
            }
            $duplicates = (string) ($options['duplicates'] ?? 'skip');

            if ($existing !== null && $duplicates === 'skip') {
                $results['skipped'][] = ['title' => $label, 'reason' => 'Sudah pernah diimport (entry #' . $existing . ').'];
                continue;
            }

            if ($existing !== null && $duplicates === 'update' && get_entry_for_content_type($existing, $ct_id) !== null) {
                update_entry($existing, $data, $status);
                $entry_id = $existing;
                $results['updated'][] = ['title' => $label, 'id' => $entry_id];
            } else {
                $entry_id = create_entry($ct_id, $data, $status, (int) $user['id']);
                if ($wp_id !== '') {
                    wp_import_ref_save($site_key, $wp_id, $ct_id, $entry_id);
                }
                $results['imported'][] = ['title' => $label, 'id' => $entry_id];
            }

            if ($downgraded) {
                $results['downgraded'][] = ['title' => $label, 'id' => $entry_id];
            }

            if ($taxonomies !== []) {
                $term_warnings = [];
                wp_import_apply_terms($entry_id, $item, $taxonomies, $can_create_terms, $term_warnings);
                foreach ($term_warnings as $warning) {
                    $warnings[] = $warning;
                }
            }

            $state['stats']['warnings'] = array_slice(array_merge(
                (array) ($state['stats']['warnings'] ?? []),
                array_map(static fn(string $w): string => $label . ': ' . $w, array_slice($warnings, 0, 3))
            ), 0, 100);
        } catch (Throwable $e) {
            log_error('Import WordPress gagal untuk "' . $label . '": ' . $e->getMessage(), 'import');
            $results['failed'][] = ['title' => $label, 'reason' => 'Kesalahan internal saat import.'];
        }
    }

    $state['offset'] = $offset + $processed;
    $state['media_cache'] = $media_cache;
    $state['stats']['imported'] = (int) ($state['stats']['imported'] ?? 0) + count($results['imported']);
    $state['stats']['updated'] = (int) ($state['stats']['updated'] ?? 0) + count($results['updated']);
    $state['stats']['skipped'] = (int) ($state['stats']['skipped'] ?? 0) + count($results['skipped']);
    $state['stats']['failed'] = (int) ($state['stats']['failed'] ?? 0) + count($results['failed']);
    $state['stats']['downgraded'] = (int) ($state['stats']['downgraded'] ?? 0) + count($results['downgraded']);

    foreach (array_slice($results['failed'], 0, 20) as $failure) {
        if (count($state['stats']['failures'] ?? []) < 200) {
            $state['stats']['failures'][] = $failure;
        }
    }

    $finished = $state['offset'] >= $total;
    $state['finished'] = $finished;

    return [
        'results' => $results,
        'total' => $total,
        'offset' => $state['offset'],
        'finished' => $finished,
    ];
}

/**
 * Bangun payload data entry dari satu item WXR sesuai mapping.
 *
 * @return array<string,mixed>
 */
function wp_import_build_payload(
    array $item,
    array $mapping,
    array $fields_by_key,
    array $attachments,
    array $options,
    int $user_id,
    array &$media_cache,
    array &$warnings
): array {
    $data = [];
    foreach ($mapping as $key => $source) {
        if (!is_string($source) || $source === '' || !isset($fields_by_key[$key])) {
            continue;
        }
        $field = $fields_by_key[$key];
        $raw = wp_import_extract_source($item, $source);
        if ($raw === null || $raw === '' || $raw === []) {
            continue;
        }
        $type = (string) $field['field_type'];

        if (in_array($type, ['image', 'file', 'gallery'], true)) {
            $refs = wp_import_media_refs($raw);
            if (count($refs) > WP_IMPORT_MEDIA_LIMIT) {
                $refs = array_slice($refs, 0, WP_IMPORT_MEDIA_LIMIT);
                $warnings[] = 'Galeri dibatasi ' . WP_IMPORT_MEDIA_LIMIT . ' gambar.';
            }
            $paths = [];
            foreach ($refs as $ref) {
                $path = wp_import_resolve_media(
                    $ref,
                    $attachments,
                    (string) ($options['blog_url'] ?? ''),
                    !empty($options['download_media']),
                    $user_id,
                    $media_cache,
                    $warnings,
                    $type !== 'file'
                );
                if ($path !== null) {
                    $paths[] = $path;
                }
            }
            if ($type === 'gallery') {
                if ($paths !== []) {
                    $data[$key] = $paths;
                }
            } elseif ($paths !== []) {
                $data[$key] = $paths[0];
            }
            continue;
        }

        $value = wp_import_coerce_value($field, $raw, $warnings);
        if ($value !== null) {
            $data[$key] = $value;
        }
    }
    return $data;
}

function wp_import_flatten_errors(array $errors): string
{
    $parts = [];
    foreach ($errors as $field => $messages) {
        foreach ((array) $messages as $message) {
            $parts[] = $field . ': ' . $message;
            if (count($parts) >= 3) {
                return implode(' | ', $parts);
            }
        }
    }
    return implode(' | ', $parts);
}

/* =========================================================================
 * Taksonomi & referensi sumber
 * ========================================================================= */

function wp_import_apply_terms(int $entry_id, array $item, array $taxonomies, bool $create_missing, array &$warnings): void
{
    $terms = $item['terms'] ?? [];
    if (!is_array($terms) || $terms === [] || $taxonomies === []) {
        return;
    }
    $by_slug = [];
    foreach ($taxonomies as $taxonomy) {
        $by_slug[(string) $taxonomy['slug']] = $taxonomy;
    }
    $ids = [];
    foreach ($terms as $term) {
        $domain = (string) ($term['domain'] ?? '');
        if (!isset($by_slug[$domain])) {
            continue;
        }
        $taxonomy_id = (int) $by_slug[$domain]['id'];
        $name = sanitize_text((string) ($term['name'] ?? ''));
        $slug = (string) ($term['slug'] ?? '');
        if ($slug === '') {
            $slug = make_term_slug($name);
        }
        if ($slug === '' || $name === '') {
            continue;
        }
        $existing = resolve_term_ids_by_slug([$slug], $taxonomy_id);
        if ($existing !== []) {
            $ids[] = (int) $existing[0];
            continue;
        }
        if (!$create_missing) {
            $warnings[] = sprintf('Term "%s" (%s) belum ada dan tidak dibuat.', $name, $domain);
            continue;
        }
        try {
            $ids[] = create_term($taxonomy_id, $name, $slug);
        } catch (Throwable $e) {
            $warnings[] = sprintf('Gagal membuat term "%s": %s', $name, $e->getMessage());
        }
    }
    if ($ids !== []) {
        set_entry_terms($entry_id, $ids);
    }
}

function wp_import_site_key(string $blog_url): string
{
    $host = parse_url($blog_url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        $host = $blog_url;
    }
    return mb_substr(strtolower(trim($host)), 0, 190);
}

function wp_import_ref_find(string $site_key, string $source_id, int $content_type_id): ?int
{
    if (!wp_import_refs_available()) {
        return null;
    }
    try {
        $stmt = get_db()->prepare(
            'SELECT entry_id FROM import_refs
             WHERE source = ? AND source_site = ? AND source_id = ? AND content_type_id = ?
             LIMIT 1'
        );
        $stmt->execute(['wordpress', $site_key, $source_id, $content_type_id]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    } catch (Throwable $e) {
        log_error('Gagal membaca import_refs: ' . $e->getMessage(), 'import');
        return null;
    }
}

/**
 * Tabel anti-duplikat tersedia? Bila user DB tidak punya hak DDL, import
 * tetap berjalan tanpa deduplikasi (bukan error fatal).
 */
function wp_import_refs_available(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    try {
        get_db()->query('SELECT 1 FROM import_refs LIMIT 1');
        $available = true;
    } catch (Throwable $e) {
        $available = false;
    }
    return $available;
}

function wp_import_ref_save(string $site_key, string $source_id, int $content_type_id, int $entry_id): void
{
    if (!wp_import_refs_available()) {
        return;
    }
    try {
        $stmt = get_db()->prepare(
            'INSERT INTO import_refs (source, source_site, source_id, content_type_id, entry_id)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE entry_id = VALUES(entry_id)'
        );
        $stmt->execute(['wordpress', $site_key, $source_id, $content_type_id, $entry_id]);
    } catch (Throwable $e) {
        log_error('Gagal menulis import_refs: ' . $e->getMessage(), 'import');
    }
}
