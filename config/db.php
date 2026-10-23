<?php
/**
 * Koneksi database (PDO singleton).
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        // Charset sudah di-set lewat DSN (setara SET NAMES). Jangan tambahkan
        // PDO::MYSQL_ATTR_INIT_COMMAND di sini: constant itu deprecated di PHP 8.5.
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    return $pdo;
}
