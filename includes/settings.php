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
