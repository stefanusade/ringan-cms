<?php
/**
 * Pembaruan Ringan CMS dari manifest JSON (mirip update WordPress).
 *
 * Manifest: { "version": "1.2.0", "url": "https://…/paket.zip",
 *             "checksum": "sha256-hex", "changelog": "…" }
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/validation.php';

const UPDATE_CACHE_TTL = 21600; // 6 jam

function cms_version(): string
{
    return defined('RINGAN_CMS_VERSION') ? RINGAN_CMS_VERSION : '1.0.0';
}

function update_url_setting(): string
{
    return trim(get_setting('update_url', ''));
}

function clear_update_cache(): void
{
    set_setting('update_cache', '');
    set_setting('update_history_cache', '');
    set_setting('update_cache_at', '');
}

function http_get(string $url, int $timeout = 10): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'RinganCMS/' . cms_version(),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        return $errno === 0 && is_string($body) ? $body : null;
    }
    $ctx = stream_context_create(['http' => [
        'timeout' => $timeout,
        'ignore_errors' => true,
        'user_agent' => 'RinganCMS/' . cms_version(),
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    return is_string($body) ? $body : null;
}

function parse_github_release(string $json): ?array
{
    $data = json_decode($json, true);
    if (!is_array($data) || empty($data['tag_name']) || !is_string($data['tag_name'])) {
        return null;
    }
    $version = ltrim($data['tag_name'], 'v');
    // Cari asset .zip + digest SHA-256 (disediakan GitHub untuk release assets).
    $zip = null;
    $digest = '';
    if (isset($data['assets']) && is_array($data['assets'])) {
        foreach ($data['assets'] as $asset) {
            if (is_array($asset) && !empty($asset['browser_download_url'])
                && str_ends_with(strtolower((string) ($asset['name'] ?? '')), '.zip')) {
                $zip = $asset['browser_download_url'];
                $digest = (string) ($asset['digest'] ?? '');
                break;
            }
        }
    }
    if ($zip === null) {
        return null; // release tanpa asset zip — tidak bisa di-update
    }
    if (!preg_match('/^sha256:([a-f0-9]{64})$/i', $digest, $m)) {
        return null; // wajib checksum SHA-256
    }
    return [
        'version' => $version,
        'url' => $zip,
        'checksum' => strtolower($m[1]),
        'changelog' => isset($data['body']) && is_string($data['body']) ? $data['body'] : '',
    ];
}

function parse_update_manifest(string $json): ?array
{
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return null;
    }
    if (empty($data['version']) || !is_string($data['version'])) {
        return null;
    }
    if (empty($data['url']) || !preg_match('#^https?://#i', $data['url'])) {
        return null;
    }
    if (empty($data['checksum']) || !preg_match('/^[a-f0-9]{64}$/i', $data['checksum'])) {
        return null;
    }
    return [
        'version' => $data['version'],
        'url' => $data['url'],
        'checksum' => strtolower($data['checksum']),
        'changelog' => isset($data['changelog']) && is_string($data['changelog']) ? $data['changelog'] : '',
    ];
}

/**
 * Ambil manifest (cache 6 jam di tabel settings). null = nonaktif/gagal.
 */
function get_update_manifest(bool $force = false): ?array
{
    $source = update_url_setting();
    if ($source === '') {
        return null;
    }
    if (!$force) {
        $at = (int) get_setting('update_cache_at', '0');
        if (time() - $at < UPDATE_CACHE_TTL) {
            $cached = get_setting('update_cache', '');
            if ($cached !== '') {
                $parsed = parse_update_manifest($cached);
                if ($parsed === null) {
                    $parsed = parse_github_release($cached);
                }
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }
    }
    $body = http_get($source);
    if ($body === null || $body === '') {
        return null;
    }
    // Dukung dua format: manifest kustom ATAU respons GitHub Releases API.
    $manifest = parse_update_manifest($body);
    if ($manifest === null) {
        $manifest = parse_github_release($body);
    }
    if ($manifest !== null) {
        set_setting('update_cache', $body);
        set_setting('update_cache_at', (string) time());
    }
    return $manifest;
}

/**
 * @return array|null manifest bila ada versi LEBIH BARU, null jika tidak.
 */
function is_update_available(bool $force = false): ?array
{
    $manifest = get_update_manifest($force);
    if ($manifest === null) {
        return null;
    }
    return version_compare($manifest['version'], cms_version(), '>') ? $manifest : null;
}

/* ===== Paket & proses update ===== */

function create_backup(): ?string
{
    $storage = dirname(UPLOAD_DIR);
    $dest = $storage . '/backups/ringan-cms-' . date('Ymd-His') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return null;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(APP_ROOT, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    $exclude = ['/.env', '/.git', '/storage'];
    foreach ($iterator as $item) {
        $path = str_replace('\\', '/', $item->getPathname());
        $rel = substr($path, strlen(APP_ROOT));
        foreach ($exclude as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                continue 2;
            }
        }
        if ($item->isDir()) {
            $zip->addEmptyDir(ltrim($rel, '/'));
        } elseif ($item->isFile()) {
            $zip->addFile($path, ltrim($rel, '/'));
        }
    }
    $zip->close();
    return is_file($dest) ? $dest : null;
}

function download_package(string $url, string $dest): bool
{
    if (function_exists('curl_init')) {
        $fp = fopen($dest, 'wb');
        if ($fp === false) {
            return false;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'RinganCMS/' . cms_version(),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $ok = curl_exec($ch) !== false && curl_errno($ch) === 0;
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fp);
        return $ok && $http >= 200 && $http < 300;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 60, 'ignore_errors' => true, 'user_agent' => 'RinganCMS/' . cms_version()]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return false;
    }
    return file_put_contents($dest, $body) !== false;
}

function verify_checksum(string $file, string $expected): bool
{
    $actual = hash_file('sha256', $file);
    return $actual !== false && hash_equals(strtolower($expected), $actual);
}

function extract_package_safely(string $zip_path, string $dest_dir): bool
{
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== true) {
            return false;
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            // Anti zip-slip: tolak path absolut, '..', atau drive letter.
            if ($name === '' || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name) || preg_match('#^[a-zA-Z]:#', $name)) {
                $zip->close();
                return false;
            }
            $out = $dest_dir . '/' . $name;
            if (str_ends_with($name, '/')) {
                if (!is_dir($out) && !mkdir($out, 0755, true) && !is_dir($out)) {
                    $zip->close();
                    return false;
                }
                continue;
            }
            $dir = dirname($out);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                $zip->close();
                return false;
            }
            $content = $zip->getFromIndex($i);
            if ($content === false || file_put_contents($out, $content) === false) {
                $zip->close();
                return false;
            }
        }
        $zip->close();
        return true;
    }
    // Fallback: biner unzip (paket sudah diverifikasi checksum)
    $cmd = 'unzip -o -q ' . escapeshellarg($zip_path) . ' -d ' . escapeshellarg($dest_dir) . ' 2>&1';
    exec($cmd, $out, $code);
    return $code === 0;
}

function validate_package(string $dir): array
{
    $required = ['config/config.php', 'public/index.php', 'includes/'];
    foreach ($required as $r) {
        if (!file_exists($dir . '/' . $r)) {
            return ['ok' => false, 'error' => 'Paket tidak valid: ' . $r . ' tidak ditemukan.'];
        }
    }
    return ['ok' => true];
}

/**
 * Salin isi paket ke APP_ROOT — tidak menimpa .env, storage/, .git.
 */
function apply_package(string $src, string $dest): int
{
    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    $exclude = ['/.env', '/.git', '/storage'];
    foreach ($iterator as $item) {
        $path = str_replace('\\', '/', $item->getPathname());
        $rel = substr($path, strlen($src));
        foreach ($exclude as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                continue 2;
            }
        }
        $target = $dest . $rel;
        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                continue;
            }
        } elseif ($item->isFile()) {
            if (copy($path, $target)) {
                $count++;
            }
        }
    }
    return $count;
}

function run_pending_migrations(): array
{
    $results = [];
    $dir = APP_ROOT . '/database/migrations';
    if (!is_dir($dir)) {
        return $results;
    }
    $files = glob($dir . '/*.sql');
    sort($files);
    foreach ($files as $file) {
        $sql = file_get_contents($file);
        $sql = preg_replace('/^--.*$/m', '', (string) $sql);
        $sql = preg_replace('#/\*.*?\*/#s', '', (string) $sql);
        $statements = array_filter(array_map('trim', explode(';', (string) $sql)));
        $ok = true;
        foreach ($statements as $stmt) {
            if ($stmt === '') {
                continue;
            }
            try {
                get_db()->exec($stmt);
            } catch (Throwable $e) {
                $ok = false;
                $results[] = ['file' => basename($file), 'ok' => false, 'error' => $e->getMessage()];
                break;
            }
        }
        if ($ok) {
            $results[] = ['file' => basename($file), 'ok' => true];
        }
    }
    return $results;
}

/**
 * Proses update end-to-end.
 * @return array{ok:bool,message:string,new_version?:string,files?:int,migrations?:array,backup?:?string}
 */
function do_update(): array
{
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'message' => 'Ekstensi PHP ZipArchive (ext-zip) tidak tersedia — update dibatalkan.'];
    }

    // Cek hak tulis direktori project.
    $probe = APP_ROOT . '/.rcm_update_probe';
    if (!@file_put_contents($probe, '1') || !@unlink($probe)) {
        return ['ok' => false, 'message' => 'Direktori project tidak dapat ditulis oleh web server. Periksa izin (mis. chown -R :www-data dan chmod -R g+w).'];
    }

    $manifest = get_update_manifest(true);
    if ($manifest === null) {
        return ['ok' => false, 'message' => 'Tidak ada pembaruan tersedia atau manifest tidak dapat diambil.'];
    }
    if (version_compare($manifest['version'], cms_version(), '<=')) {
        return ['ok' => false, 'message' => 'Tidak ada pembaruan baru.'];
    }

    $storage = dirname(UPLOAD_DIR);
    $tmp = $storage . '/tmp';
    $backups = $storage . '/backups';
    foreach ([$tmp, $backups] as $d) {
        if (!is_dir($d) && !mkdir($d, 0750, true) && !is_dir($d)) {
            return ['ok' => false, 'message' => 'Gagal membuat direktori penyimpanan sementara.'];
        }
    }

    $run = $tmp . '/update-' . bin2hex(random_bytes(8));
    if (!mkdir($run, 0750, true)) {
        return ['ok' => false, 'message' => 'Gagal membuat direktori kerja update.'];
    }

    try {
        $backup = create_backup();
        if ($backup === null) {
            return ['ok' => false, 'message' => 'Gagal membuat backup otomatis. Update dibatalkan demi keamanan.'];
        }

        $zip_path = $run . '/package.zip';
        if (!download_package($manifest['url'], $zip_path)) {
            return ['ok' => false, 'message' => 'Gagal mengunduh paket pembaruan.'];
        }
        if (!verify_checksum($zip_path, $manifest['checksum'])) {
            return ['ok' => false, 'message' => 'Checksum paket tidak cocok. Unduhan dianggap tidak valid dan dibatalkan.'];
        }
        $extract = $run . '/extract';
        if (!mkdir($extract, 0750, true) || !extract_package_safely($zip_path, $extract)) {
            return ['ok' => false, 'message' => 'Gagal mengekstrak paket (zip rusak atau path tidak aman).'];
        }
        $valid = validate_package($extract);
        if (!$valid['ok']) {
            return ['ok' => false, 'message' => $valid['error']];
        }

        $files = apply_package($extract, APP_ROOT);
        $migrations = run_pending_migrations();
        clear_update_cache();

        return [
            'ok' => true,
            'message' => 'Update selesai. Ringan CMS diperbarui ke versi ' . $manifest['version'] . '.',
            'new_version' => $manifest['version'],
            'files' => $files,
            'migrations' => $migrations,
            'backup' => $backup,
        ];
    } finally {
        if (is_dir($run)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($run, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                if ($f->isDir() && !$f->isLink()) {
                    rmdir($f->getPathname());
                } else {
                    @unlink($f->getPathname());
                }
            }
            rmdir($run);
        }
    }
}

/* ===== Riwayat rilis (What's New) ===== */

/**
 * URL daftar rilis GitHub bila update_url menunjuk ke .../releases/latest.
 * Manifest kustom tidak punya riwayat → null.
 */
function update_history_url(): ?string
{
    $source = update_url_setting();
    if ($source === '') {
        return null;
    }
    if (preg_match('#^https://api\.github\.com/repos/([^/]+/[^/]+)/releases/latest$#i', $source, $m)) {
        return 'https://api.github.com/repos/' . $m[1] . '/releases?per_page=10';
    }
    return null;
}

function parse_update_history(string $json): array
{
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $rel) {
        if (!is_array($rel) || !empty($rel['draft'])) {
            continue;
        }
        $version = ltrim((string) ($rel['tag_name'] ?? ''), 'v');
        if ($version === '') {
            continue;
        }
        $out[] = [
            'version' => $version,
            'name' => (string) ($rel['name'] ?? ''),
            'date' => (string) ($rel['published_at'] ?? ''),
            'body' => (string) ($rel['body'] ?? ''),
            'prerelease' => !empty($rel['prerelease']),
        ];
    }
    return $out;
}

/**
 * Ambil daftar rilis (cache 6 jam di tabel settings).
 */
function get_update_history(bool $force = false): array
{
    $url = update_history_url();
    if ($url === null) {
        return [];
    }
    if (!$force) {
        $at = (int) get_setting('update_cache_at', '0');
        if (time() - $at < UPDATE_CACHE_TTL) {
            $cached = get_setting('update_history_cache', '');
            if ($cached !== '') {
                $parsed = parse_update_history($cached);
                if ($parsed !== []) {
                    return $parsed;
                }
            }
        }
    }
    $body = http_get($url);
    if ($body === null || $body === '') {
        return [];
    }
    $parsed = parse_update_history($body);
    if ($parsed !== []) {
        set_setting('update_history_cache', $body);
        set_setting('update_cache_at', (string) time());
    }
    return $parsed;
}

/* ===== Render markdown-lite yang aman untuk changelog ===== */

function changelog_inline(string $text): string
{
    $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
        if (preg_match('#^(javascript|vbscript|data):#i', $m[2])) {
            return e($m[0]);
        }
        return '<a href="' . e($m[2]) . '" target="_blank" rel="noopener">' . e($m[1]) . '</a>';
    }, $text);
    $text = preg_replace_callback('/`([^`]+)`/', function ($m) {
        return '<code>' . e($m[1]) . '</code>';
    }, $text);
    $text = preg_replace_callback('/\*\*([^*]+)\*\*/', function ($m) {
        return '<strong>' . e($m[1]) . '</strong>';
    }, $text);
    return $text;
}

function render_changelog_markdown(string $markdown): string
{
    $lines = preg_split('/\r\n|\r|\n/', $markdown);
    $html = '';
    $in_list = false;
    $in_code = false;
    $code_lines = [];
    $paragraph = [];

    $flush_paragraph = function () use (&$html, &$paragraph) {
        if ($paragraph === []) {
            return;
        }
        $html .= '<p>' . changelog_inline(implode(' ', $paragraph)) . '</p>';
        $paragraph = [];
    };
    $close_list = function () use (&$html, &$in_list) {
        if ($in_list) {
            $html .= '</ul>';
            $in_list = false;
        }
    };
    $flush_code = function () use (&$html, &$in_code, &$code_lines) {
        if (!$in_code) {
            return;
        }
        $html .= '<pre><code>' . e(implode("\n", $code_lines)) . '</code></pre>';
        $code_lines = [];
        $in_code = false;
    };

    foreach ($lines as $raw) {
        $line = rtrim($raw);

        if (preg_match('/^```/', $line)) {
            $flush_paragraph();
            $close_list();
            if ($in_code) {
                $flush_code();
            } else {
                $in_code = true;
                $code_lines = [];
            }
            continue;
        }
        if ($in_code) {
            $code_lines[] = $line;
            continue;
        }
        if (trim($line) === '') {
            $flush_paragraph();
            $close_list();
            continue;
        }
        if (preg_match('/^(#{1,4})\s+(.*)$/', $line, $m)) {
            $flush_paragraph();
            $close_list();
            $level = min(6, strlen($m[1]) + 2);
            $html .= '<h' . $level . '>' . changelog_inline($m[2]) . '</h' . $level . '>';
            continue;
        }
        if (preg_match('/^-{3,}$/', trim($line))) {
            $flush_paragraph();
            $close_list();
            $html .= '<hr>';
            continue;
        }
        if (preg_match('/^[-*]\s+(.*)$/', $line, $m)) {
            $flush_paragraph();
            if (!$in_list) {
                $html .= '<ul>';
                $in_list = true;
            }
            $html .= '<li>' . changelog_inline($m[1]) . '</li>';
            continue;
        }
        $paragraph[] = changelog_inline($line);
    }
    $flush_paragraph();
    $close_list();
    $flush_code();
    return $html;
}
