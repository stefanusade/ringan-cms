<?php
/**
 * Pengaturan situs (key-value), ala Settings di WordPress.
 * Tabel dibuat lazy (CREATE TABLE IF NOT EXISTS) agar fitur langsung
 * berfungsi tanpa migrasi manual; skema tetap ada di schema.sql & migrasi.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/upload.php';

const SETTINGS_DEFAULTS = [
    'site_title' => 'Ringan CMS',
    'site_tagline' => '',
    'site_description' => '',
    'site_favicon' => '',
    'site_timezone' => 'Asia/Jakarta',
    'api_path' => 'api/v1',
];

function ensure_settings_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    get_db()->exec(
        'CREATE TABLE IF NOT EXISTS settings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function get_setting(string $key, string $default = ''): string
{
    ensure_settings_table();
    $stmt = get_db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false || $value === null ? $default : (string) $value;
}

function set_setting(string $key, string $value): void
{
    ensure_settings_table();
    $stmt = get_db()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);
}

function get_site_settings(): array
{
    ensure_settings_table();
    $defaults = SETTINGS_DEFAULTS;
    $stmt = get_db()->query('SELECT setting_key, setting_value FROM settings');
    foreach ($stmt->fetchAll() as $row) {
        if (array_key_exists($row['setting_key'], $defaults)) {
            $defaults[$row['setting_key']] = (string) $row['setting_value'];
        }
    }
    return $defaults;
}

function site_favicon_url(): string
{
    $favicon = get_setting('site_favicon');
    return $favicon === '' ? '' : BASE_URL . '/files/' . ltrim($favicon, '/');
}

/**
 * Terapkan zona waktu dari pengaturan situs (fallback ke .env / default).
 */
function apply_site_timezone(): void
{
    try {
        $tz = get_setting('site_timezone', '');
        if ($tz !== '' && in_array($tz, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            date_default_timezone_set($tz);
        }
    } catch (Throwable $e) {
        // abaikan — pakai zona waktu dari .env
    }
}

/**
 * Path prefix REST API dari pengaturan (default: api/v1).
 * Selalu divalidasi ulang — jika tidak valid, fallback ke default.
 */
function get_api_path(): string
{
    try {
        $path = strtolower(trim(get_setting('api_path', 'api/v1')));
    } catch (Throwable $e) {
        return 'api/v1';
    }
    if (!preg_match('#^[a-z0-9-]+(?:/[a-z0-9-]+)*$#', $path)) {
        return 'api/v1';
    }
    return $path;
}

/**
 * Daftar zona waktu populer untuk dropdown Settings.
 */
function timezone_options(): array
{
    static $options = null;
    if ($options !== null) {
        return $options;
    }
    $regions = ['Asia', 'Europe', 'America', 'Australia', 'Pacific', 'Africa', 'Atlantic', 'Indian'];
    $options = ['UTC'];
    foreach (DateTimeZone::listIdentifiers() as $tz) {
        $region = explode('/', $tz, 2)[0];
        if (in_array($region, $regions, true)) {
            $options[] = $tz;
        }
    }
    return $options;
}
