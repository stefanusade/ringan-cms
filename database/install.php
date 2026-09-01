<?php
/**
 * CLI installer:
 *   php database/install.php
 *
 * Membuat tabel dari schema.sql dan user superadmin pertama.
 * Konfigurasi DB dibaca dari .env (salinan .env.example).
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/db.php';

function cli_out(string $msg): void
{
    echo $msg . PHP_EOL;
}

function cli_prompt(string $prompt): string
{
    echo $prompt;
    $line = fgets(STDIN);
    return $line === false ? '' : trim($line);
}

cli_out('=== Ringan CMS Installer ===');

$schema = APP_ROOT . '/database/schema.sql';
if (!is_readable($schema)) {
    cli_out('[ERROR] database/schema.sql tidak ditemukan.');
    exit(1);
}

try {
    $db = get_db();
} catch (Throwable $e) {
    cli_out('[ERROR] Gagal koneksi database: ' . $e->getMessage());
    cli_out('Periksa .env (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS).');
    exit(1);
}

cli_out('Menjalankan schema.sql ...');
$sql = file_get_contents($schema);
$sql = preg_replace('/^--.*$/m', '', (string) $sql);
$sql = preg_replace('#/\*.*?\*/#s', '', (string) $sql);
$statements = array_filter(array_map('trim', explode(';', (string) $sql)));
foreach ($statements as $stmt) {
    if ($stmt === '') {
        continue;
    }
    $db->exec($stmt);
}
cli_out('Tabel berhasil dibuat.');

$count = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($count > 0) {
    cli_out('User sudah ada, lewati pembuatan admin.');
} else {
    cli_out('Membuat user superadmin pertama...');
    $username = env('ADMIN_USERNAME', '');
    $email = env('ADMIN_EMAIL', '');
    $password = env('ADMIN_PASSWORD', '');
    if ($username === '' || $email === '' || $password === '') {
        $username = cli_prompt('Username superadmin: ');
        $email = cli_prompt('Email: ');
        $password = cli_prompt('Password (min 8 karakter): ');
    }
    if ($username === '' || $email === '' || $password === '' || strlen($password) < 8) {
        cli_out('[ERROR] Data admin tidak lengkap / password kurang dari 8 karakter.');
        exit(1);
    }
    $stmt = $db->prepare('INSERT INTO users (username, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, 1)');
    $stmt->execute([$username, $email, password_hash($password, PASSWORD_ARGON2ID), 'superadmin']);
    cli_out('Superadmin "' . $username . '" dibuat.');
}

if (!is_dir(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0750, true);
}
if (!is_dir(LOG_DIR)) {
    @mkdir(LOG_DIR, 0750, true);
}
file_put_contents(UPLOAD_DIR . '/.htaccess', "php_flag engine off\n<FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|phar|pl|py|cgi|asp|aspx|sh)$\">\n  Require all denied\n</FilesMatch>\n");
file_put_contents(UPLOAD_DIR . '/index.html', '');
file_put_contents(LOG_DIR . '/.htaccess', "Require all denied\n");
file_put_contents(LOG_DIR . '/index.html', '');

cli_out('Selesai. Login di ' . BASE_URL . '/admin/login');
