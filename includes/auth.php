<?php
/**
 * Autentikasi admin: session aman, login, CSRF, password hashing, JWT.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/audit_log.php';

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    session_name('ringancms_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function current_user(): ?array
{
    start_session();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    static $cached = false;
    if ($cached === false) {
        $db = get_db();
        $stmt = $db->prepare('SELECT id, username, email, role, is_active FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user || !$user['is_active']) {
            unset($_SESSION['user_id']);
            $cached = null;
            return null;
        }
        $cached = $user;
    }
    return $cached ?: null;
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        flash_set('error', 'Silakan login terlebih dahulu.');
        redirect_admin('login');
    }
    return $user;
}

function require_role(string ...$roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        echo '403 — Anda tidak memiliki akses ke halaman ini.';
        exit;
    }
    return $user;
}

/**
 * @return array{error:string}|true
 */
function login_user(string $identifier, string $password): array|true
{
    if (!check_login_rate_limit($identifier)) {
        return ['error' => 'Terlalu banyak percobaan login. Silakan coba lagi nanti.'];
    }
    $db = get_db();
    $stmt = $db->prepare('SELECT id, username, email, password_hash, role, is_active FROM users WHERE (username = ? OR email = ?) LIMIT 1');
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();

    if (!$user || !$user['is_active'] || !password_verify($password, $user['password_hash'])) {
        record_login_attempt($identifier, false);
        return ['error' => 'Username/email atau password salah.'];
    }

    record_login_attempt($identifier, true);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    log_audit('login', 'users', (string) $user['id'], (int) $user['id']);
    return true;
}

function logout_user(): void
{
    start_session();
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($uid > 0) {
        log_audit('logout', 'users', (string) $uid, $uid);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/* ===== CSRF ===== */

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): bool
{
    start_session();
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($token)
        && $token !== ''
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function require_csrf(): void
{
    if (!verify_csrf()) {
        http_response_code(419);
        echo 'CSRF token tidak valid. Silakan muat ulang halaman dan coba lagi.';
        exit;
    }
}

/* ===== Password ===== */

function hash_password(string $password): string
{
    return password_hash($password, PASSWORD_ARGON2ID);
}

/* ===== JWT (HMAC-SHA256, tanpa dependency) ===== */

function jwt_encode(array $claims, ?int $ttl = null): string
{
    $ttl = $ttl ?? JWT_TTL;
    $now = time();
    $payload = $claims + ['iat' => $now, 'exp' => $now + $ttl];
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $b64url = static fn(string $d): string => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    $segments = [$b64url(json_encode($header)), $b64url(json_encode($payload))];
    $signing_input = implode('.', $segments);
    $signature = hash_hmac('sha256', $signing_input, JWT_SECRET, true);
    $segments[] = $b64url($signature);
    return implode('.', $segments);
}

function jwt_decode(string $token): ?array
{
    if (JWT_SECRET === '') {
        return null;
    }
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    [$header, $body, $signature] = $parts;
    $b64url = static fn(string $d): string => strtr($d, '-_', '+/');
    $expected = hash_hmac('sha256', $header . '.' . $body, JWT_SECRET, true);
    $sig = base64_decode($b64url($signature), true);
    if ($sig === false || !hash_equals($expected, $sig)) {
        return null;
    }
    $payload = json_decode(base64_decode($b64url($body), true) ?: '', true);
    if (!is_array($payload)) {
        return null;
    }
    if (!isset($payload['exp']) || (int) $payload['exp'] < time()) {
        return null;
    }
    return $payload;
}
