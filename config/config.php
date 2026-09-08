<?php
/**
 * Konfigurasi utama aplikasi.
 * Membaca .env, mendefinisikan konstanta, dan mengatur error handling.
 * Tidak ada secret yang di-hardcode di sini.
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

/**
 * Helper membaca nilai env dengan default.
 */
function env(string $key, string $default = ''): string
{
    $val = getenv($key);
    if ($val === false) {
        $val = $_ENV[$key] ?? null;
    }
    if ($val === null || $val === false) {
        return $default;
    }
    return (string) $val;
}

/**
 * Loader .env sederhana (tanpa dependency eksternal).
 */
function load_env_file(string $path): void
{
    if (!is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = substr($line, 7);
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        if (getenv($key) === false && !isset($_ENV[$key])) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

load_env_file(APP_ROOT . '/.env');

/* ===== Environment & error handling ===== */
define('APP_ENV', env('APP_ENV', 'production'));
define('APP_DEBUG', env('APP_DEBUG', 'false') === 'true');
define('APP_TIMEZONE', env('APP_TIMEZONE', 'Asia/Jakarta'));
date_default_timezone_set(APP_TIMEZONE);

/* ===== Versi aplikasi ===== */
define('RINGAN_CMS_VERSION', '1.2.1');

/* ===== Database ===== */
define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_PORT', env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', 'ringan_cms'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

/* ===== URL & path ===== */
$_base_url = env('BASE_URL', '');
if ($_base_url === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Deteksi otomatis subdirektori: {base}/public/index.php → base = /ringan-cms
    $_script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $_script_dir = rtrim(dirname($_script), '/');
    $_base_path = '';
    if ($_script_dir !== '' && str_ends_with($_script_dir, '/public')) {
        $_base_path = rtrim(substr($_script_dir, 0, -strlen('/public')), '/');
    }
    $_base_url = $scheme . '://' . $host . $_base_path;
    unset($_script, $_script_dir, $_base_path);
}
define('BASE_URL', rtrim($_base_url, '/'));
define('BASE_PATH', (string) parse_url(BASE_URL, PHP_URL_PATH));
unset($_base_url);

/* ===== Storage ===== */
define('UPLOAD_DIR', env('UPLOAD_DIR', APP_ROOT . '/storage/uploads'));
define('LOG_DIR', env('LOG_DIR', APP_ROOT . '/storage/logs'));
define('MAX_UPLOAD_SIZE', max(1024, (int) env('MAX_UPLOAD_SIZE', '5242880')));

/* ===== Auth / API ===== */
define('JWT_SECRET', env('JWT_SECRET', ''));
define('JWT_TTL', max(60, (int) env('JWT_TTL', '3600')));
define('LOGIN_MAX_ATTEMPTS', max(1, (int) env('LOGIN_MAX_ATTEMPTS', '5')));
define('LOGIN_LOCK_MINUTES', max(1, (int) env('LOGIN_LOCK_MINUTES', '15')));
define('API_RATE_LIMIT', max(1, (int) env('API_RATE_LIMIT', '120')));
define('API_RATE_WINDOW', max(1, (int) env('API_RATE_WINDOW', '60')));
define('CORS_ORIGINS', array_values(array_filter(array_map('trim', explode(',', env('CORS_ORIGINS', ''))))));

/* ===== Direktori runtime ===== */
if (!is_dir(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0750, true);
}
if (!is_dir(LOG_DIR)) {
    @mkdir(LOG_DIR, 0750, true);
}

/* ===== Error handling ===== */
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', LOG_DIR . '/php_errors.log');
