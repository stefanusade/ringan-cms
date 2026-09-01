<?php
/**
 * Wizard instalasi interaktif Ringan CMS (ala WordPress).
 *
 * Diakses via front controller: GET/POST /install
 * Alur: 1) prasyarat → 2) konfigurasi database → 3) migrasi skema
 *       4) akun superadmin + tulis .env → 5) selesai.
 *
 * Setelah instalasi selesai, route otomatis mengarahkan ke login
 * (deteksi: .env terisi + tabel users berisi). Disarankan menghapus
 * folder install/ setelah berhasil.
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/response.php';
require_once APP_ROOT . '/includes/validation.php';

start_session();

/**
 * Instalasi dianggap selesai bila .env terisi dan tabel users punya data.
 */
function installer_is_installed(): bool
{
    if (env('DB_NAME', '') === '' || env('DB_USER', '') === '') {
        return false;
    }
    try {
        $db = get_db();
        return ((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

$installer_finished = !empty($_SESSION['installer_finished']);
if (installer_is_installed()) {
    if (!$installer_finished) {
        flash_set('info', 'Ringan CMS sudah terinstall.');
        redirect_admin('login');
    }
    if (installer_step() !== 5) {
        redirect(installer_url('step=5'));
    }
}

function installer_url(string $query = ''): string
{
    return BASE_URL . '/install/' . ($query === '' ? '' : '?' . $query);
}

function installer_step(): int
{
    return max(1, min(5, (int) ($_GET['step'] ?? 1)));
}

function installer_db_connect(array $db, bool $with_db = true): PDO
{
    $dsn = 'mysql:host=' . $db['host'] . ';port=' . $db['port'] . ($with_db ? ';dbname=' . $db['name'] : '') . ';charset=utf8mb4';
    return new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function installer_run_schema(PDO $db, string $schema_file): array
{
    $results = [];
    $sql = file_get_contents($schema_file);
    $sql = preg_replace('/^--.*$/m', '', (string) $sql);
    $sql = preg_replace('#/\*.*?\*/#s', '', (string) $sql);
    $statements = array_filter(array_map('trim', explode(';', (string) $sql)));
    foreach ($statements as $stmt) {
        if ($stmt === '') {
            continue;
        }
        try {
            $db->exec($stmt);
            $results[] = ['sql' => substr($stmt, 0, 70) . '…', 'ok' => true];
        } catch (Throwable $e) {
            // Wizard butuh umpan balik SQL error untuk debug konfigurasi —
            // pengecualian yang disengaja dari aturan "jangan tampilkan error mentah".
            $results[] = ['sql' => substr($stmt, 0, 70) . '…', 'ok' => false, 'error' => $e->getMessage()];
        }
    }
    return $results;
}

function installer_write_env(array $db): bool
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Deteksi subdirektori (mis. /ringan-cms) dari lokasi front controller
    $_script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $_script_dir = rtrim(dirname($_script), '/');
    $_base_path = '';
    if ($_script_dir !== '' && str_ends_with($_script_dir, '/public')) {
        $_base_path = rtrim(substr($_script_dir, 0, -strlen('/public')), '/');
    }
    $secret = bin2hex(random_bytes(32));

    $content = "# Dibuat otomatis oleh wizard instalasi — " . date('Y-m-d H:i:s') . "\n"
        . "APP_ENV=development\n"
        . "APP_DEBUG=true\n"
        . "APP_TIMEZONE=Asia/Jakarta\n"
        . "BASE_URL=" . $scheme . "://" . $host . $_base_path . "\n\n"
        . "DB_HOST=" . $db['host'] . "\n"
        . "DB_PORT=" . $db['port'] . "\n"
        . "DB_NAME=" . $db['name'] . "\n"
        . "DB_USER=" . $db['user'] . "\n"
        . "DB_PASS=" . $db['pass'] . "\n"
        . "DB_CHARSET=utf8mb4\n\n"
        . "JWT_SECRET=" . $secret . "\n"
        . "JWT_TTL=3600\n\n"
        . "LOGIN_MAX_ATTEMPTS=5\n"
        . "LOGIN_LOCK_MINUTES=15\n\n"
        . "API_RATE_LIMIT=120\n"
        . "API_RATE_WINDOW=60\n\n"
        . "CORS_ORIGINS=\n\n"
        . "MAX_UPLOAD_SIZE=5242880\n";

    $path = APP_ROOT . '/.env';
    if (file_put_contents($path, $content, LOCK_EX) === false) {
        return false;
    }
    @chmod($path, 0600);
    return true;
}

/* ==================== Proses POST ==================== */
$errors = [];
$form_db = null;
$migration_results = null;
$step = installer_step();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $posted = (int) ($_POST['step'] ?? 0);

    if ($posted === 2) {
        // --- Langkah 2: konfigurasi database ---
        $form_db = [
            'host' => sanitize_text($_POST['db_host'] ?? '127.0.0.1'),
            'port' => (int) ($_POST['db_port'] ?? 3306),
            'name' => sanitize_text($_POST['db_name'] ?? 'ringan_cms'),
            'user' => sanitize_text($_POST['db_user'] ?? ''),
            'pass' => (string) ($_POST['db_pass'] ?? ''),
        ];
        if ($form_db['host'] === '' || $form_db['name'] === '' || $form_db['user'] === '') {
            $errors['db'] = 'Host, nama database, dan user wajib diisi.';
        } elseif ($form_db['port'] < 1 || $form_db['port'] > 65535) {
            $errors['db'] = 'Port tidak valid.';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $form_db['name'])) {
            $errors['db'] = 'Nama database hanya huruf, angka, dan underscore.';
        } else {
            try {
                $pdo = installer_db_connect($form_db, false);
                // Buat database bila belum ada (best-effort; butuh privilege CREATE DATABASE)
                try {
                    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $form_db['name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                    $form_db['created'] = true;
                } catch (Throwable $e) {
                    $form_db['created'] = false;
                }
                $_SESSION['installer_db'] = $form_db;
                redirect(installer_url('step=3'));
            } catch (Throwable $e) {
                $errors['db'] = 'Gagal terhubung ke database: ' . $e->getMessage();
                $step = 2;
            }
        }
    }

    if ($posted === 3) {
        // --- Langkah 3: migrasi skema ---
        $db = $_SESSION['installer_db'] ?? null;
        if (!is_array($db)) {
            redirect(installer_url('step=2'));
        }
        $schema = APP_ROOT . '/database/schema.sql';
        if (!is_readable($schema)) {
            $errors['migrate'] = 'database/schema.sql tidak ditemukan.';
            $step = 3;
        } else {
            try {
                $pdo = installer_db_connect($db, true);
                $migration_results = installer_run_schema($pdo, $schema);
                $failed = array_values(array_filter($migration_results, fn($r) => !$r['ok']));
                if ($failed === []) {
                    $_SESSION['installer_schema_ok'] = true;
                    redirect(installer_url('step=4'));
                }
                $step = 3;
            } catch (Throwable $e) {
                $errors['migrate'] = 'Gagal koneksi ke database "' . $db['name'] . '": ' . $e->getMessage();
                $step = 3;
            }
        }
    }

    if ($posted === 4) {
        // --- Langkah 4: akun superadmin + tulis .env ---
        $db = $_SESSION['installer_db'] ?? null;
        if (!is_array($db)) {
            redirect(installer_url('step=2'));
        }
        $username = sanitize_text($_POST['username'] ?? '');
        $email = sanitize_text($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $password2 = (string) ($_POST['password_confirm'] ?? '');

        if (!validate_username($username)) {
            $errors['username'] = 'Username 3–60 karakter (huruf, angka, titik, underscore, strip).';
        }
        if (!validate_email($email)) {
            $errors['email'] = 'Email tidak valid.';
        }
        if (strlen($password) < 8) {
            $errors['password'] = 'Password minimal 8 karakter.';
        } elseif ($password !== $password2) {
            $errors['password'] = 'Konfirmasi password tidak cocok.';
        }

        if ($errors === []) {
            try {
                $pdo = installer_db_connect($db, true);
                $exists = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
                if ($exists === 0) {
                    $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, 1)');
                    $stmt->execute([$username, $email, password_hash($password, PASSWORD_ARGON2ID), 'superadmin']);
                }
                if (!installer_write_env($db)) {
                    $errors['env'] = 'Gagal menulis file .env. Periksa izin tulis direktori project.';
                } else {
                    $_SESSION['installer_finished'] = true;
                    unset($_SESSION['installer_db'], $_SESSION['installer_schema_ok']);
                    redirect(installer_url('step=5'));
                }
            } catch (Throwable $e) {
                $errors['admin'] = 'Gagal menyimpan akun admin: ' . $e->getMessage();
            }
        }
        $step = 4;
    }
}

/* ==================== Render ==================== */
$db_session = $_SESSION['installer_db'] ?? null;
if (in_array($step, [3, 4], true) && !is_array($db_session)) {
    redirect(installer_url('step=2'));
}

installer_header($step);

switch ($step) {
    case 1:
        $checks = [
            'PHP versi 8.1 ke atas (terpasang: ' . PHP_VERSION . ')' => version_compare(PHP_VERSION, '8.1.0', '>='),
            'Ekstensi PDO MySQL (pdo_mysql)' => extension_loaded('pdo_mysql'),
            'Ekstensi mbstring' => extension_loaded('mbstring'),
            'Ekstensi fileinfo' => extension_loaded('fileinfo'),
            'Folder storage/ dapat ditulis' => is_dir(APP_ROOT . '/storage') || @mkdir(APP_ROOT . '/storage', 0750, true),
            'File database/schema.sql tersedia' => is_file(APP_ROOT . '/database/schema.sql'),
        ];
        $all_ok = !in_array(false, $checks, true);
        echo '<h2>Pemeriksaan Prasyarat</h2>';
        echo '<ul class="check-list">';
        foreach ($checks as $label => $ok) {
            echo '<li><span class="' . ($ok ? 'check-ok' : 'check-bad') . '">' . ($ok ? '✓' : '✗') . '</span> ' . e($label) . '</li>';
        }
        echo '</ul>';
        if ($all_ok) {
            echo '<div class="alert alert-success">Semua prasyarat terpenuhi.</div>';
            echo '<a class="btn btn-primary" href="' . e(installer_url('step=2')) . '">Lanjut ke Konfigurasi Database →</a>';
        } else {
            echo '<div class="alert alert-error">Ada prasyarat yang belum terpenuhi. Perbaiki dulu sebelum melanjutkan.</div>';
        }
        break;

    case 2:
        $pre = $form_db !== null
            ? $form_db
            : ($db_session !== null ? array_merge($db_session, ['pass' => '']) : []);
        $v_host = $pre['host'] ?? '127.0.0.1';
        $v_port = $pre['port'] ?? 3306;
        $v_name = $pre['name'] ?? 'ringan_cms';
        $v_user = $pre['user'] ?? '';
        echo '<h2>Konfigurasi Database</h2>';
        echo '<p class="muted">Isi kredensial MySQL/MariaDB. User database butuh hak <strong>CREATE</strong> pada database (untuk migrasi tabel) dan hak <strong>SELECT, INSERT, UPDATE, DELETE</strong> untuk aplikasi.</p>';
        if (isset($errors['db'])) {
            echo '<div class="alert alert-error">' . e($errors['db']) . '</div>';
        }
        echo '<form method="post" action="' . e(installer_url('')) . '">';
        echo csrf_field();
        echo '<input type="hidden" name="step" value="2">';
        echo '<div class="grid-2">';
        echo '<div class="form-group"><label for="db_host">Host</label><input type="text" id="db_host" name="db_host" value="' . e($v_host) . '" required></div>';
        echo '<div class="form-group"><label for="db_port">Port</label><input type="number" id="db_port" name="db_port" value="' . e((string) $v_port) . '" required min="1" max="65535"></div>';
        echo '</div>';
        echo '<div class="form-group"><label for="db_name">Nama Database</label><input type="text" id="db_name" name="db_name" value="' . e($v_name) . '" required></div>';
        echo '<div class="form-group"><label for="db_user">Username</label><input type="text" id="db_user" name="db_user" value="' . e($v_user) . '" required autocomplete="off"></div>';
        echo '<div class="form-group"><label for="db_pass">Password</label><input type="password" id="db_pass" name="db_pass" autocomplete="new-password" placeholder="••••••••"></div>';
        echo '<button type="submit" class="btn btn-primary">Uji Koneksi &amp; Lanjut →</button>';
        echo '</form>';
        break;

    case 3:
        echo '<h2>Migrasi Database</h2>';
        echo '<p class="muted">Database <strong>' . e($db_session['name']) . '</strong> di <strong>' . e($db_session['host'] . ':' . $db_session['port']) . '</strong> sebagai user <strong>' . e($db_session['user']) . '</strong>.</p>';
        if (isset($errors['migrate'])) {
            echo '<div class="alert alert-error">' . e($errors['migrate']) . '</div>';
        }
        if (is_array($migration_results)) {
            $failed = array_values(array_filter($migration_results, fn($r) => !$r['ok']));
            echo '<div class="alert alert-error"><strong>' . count($failed) . ' pernyataan SQL gagal.</strong> Periksa izin user database (butuh CREATE) lalu coba lagi.</div>';
            echo '<table class="table"><thead><tr><th>SQL</th><th>Error</th></tr></thead><tbody>';
            foreach ($migration_results as $r) {
                echo '<tr><td><code>' . e($r['sql']) . '</code></td><td>' . ($r['ok'] ? '<span class="check-ok">OK</span>' : '<span class="check-bad">' . e($r['error']) . '</span>') . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<form method="post" action="' . e(installer_url('')) . '">';
        echo csrf_field();
        echo '<input type="hidden" name="step" value="3">';
        echo '<button type="submit" class="btn btn-primary">' . (is_array($migration_results) ? 'Coba Lagi' : 'Mulai Migrasi →') . '</button>';
        echo '</form>';
        break;

    case 4:
        echo '<h2>Akun Superadmin</h2>';
        echo '<p class="muted">Buat akun admin pertama untuk masuk ke dashboard.</p>';
        if (isset($errors['env'])) {
            echo '<div class="alert alert-error">' . e($errors['env']) . '</div>';
        }
        if (isset($errors['admin'])) {
            echo '<div class="alert alert-error">' . e($errors['admin']) . '</div>';
        }
        if (isset($errors['username']) || isset($errors['email']) || isset($errors['password'])) {
            echo '<div class="alert alert-error"><ul>';
            foreach (['username', 'email', 'password'] as $k) {
                if (isset($errors[$k])) {
                    echo '<li>' . e($errors[$k]) . '</li>';
                }
            }
            echo '</ul></div>';
        }
        echo '<form method="post" action="' . e(installer_url('')) . '">';
        echo csrf_field();
        echo '<input type="hidden" name="step" value="4">';
        echo '<div class="form-group"><label for="username">Username</label><input type="text" id="username" name="username" required autocomplete="off"></div>';
        echo '<div class="form-group"><label for="email">Email</label><input type="email" id="email" name="email" required autocomplete="off"></div>';
        echo '<div class="grid-2">';
        echo '<div class="form-group"><label for="password">Password</label><input type="password" id="password" name="password" required minlength="8" autocomplete="new-password"></div>';
        echo '<div class="form-group"><label for="password_confirm">Ulangi Password</label><input type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password"></div>';
        echo '</div>';
        echo '<button type="submit" class="btn btn-primary">Selesaikan Instalasi →</button>';
        echo '</form>';
        break;

    case 5:
        echo '<div class="alert alert-success"><strong>Instalasi selesai!</strong> Ringan CMS siap digunakan.</div>';
        echo '<ul class="check-list">';
        echo '<li>File <code>.env</code> sudah dibuat (JWT_SECRET acak, permission 0600).</li>';
        echo '<li>Tabel database sudah dimigrasi.</li>';
        echo '<li>Akun superadmin sudah dibuat.</li>';
        echo '<li><strong>Keamanan:</strong> hapus folder <code>install/</code> setelah ini.</li>';
        echo '</ul>';
        echo '<p class="muted">Setelah menghapus folder install/, rute /install otomatis diblok (mengarah ke login).</p>';
        echo '<a class="btn btn-primary" href="' . e(admin_url('login')) . '">Ke Halaman Login →</a>';
        break;
}

installer_footer();

/* ==================== Layout helpers ==================== */

function installer_header(int $step): void
{
    $steps = [1 => 'Prasyarat', 2 => 'Database', 3 => 'Migrasi', 4 => 'Admin', 5 => 'Selesai'];
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Instalasi — Ringan CMS</title>
<link rel="stylesheet" href="<?= e(BASE_URL . '/admin/assets/css/admin.css') ?>">
</head>
<body>
<main class="container main install-main">
  <div class="install-brand">Ringan <span>CMS</span> — Instalasi</div>
  <ol class="install-steps">
    <?php foreach ($steps as $n => $label): ?>
      <li class="<?= $n === $step ? 'current' : ($n < $step ? 'done' : '') ?>"><?= $n ?>. <?= e($label) ?></li>
    <?php endforeach; ?>
  </ol>
  <div class="card install-card">
<?php
}

function installer_footer(): void
{
    ?>
  </div>
</main>
</body>
</html>
<?php
}
