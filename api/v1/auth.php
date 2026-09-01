<?php
/**
 * Endpoint login API: POST /api/v1/auth
 * Body: { "username": "...", "password": "..." }
 * Balasan: JWT Bearer token (role-based, superadmin/editor = boleh tulis).
 * Di-include oleh api/v1/index.php setelah CORS & rate limit per IP.
 */

declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('method_not_allowed', 'Method tidak diizinkan.', 405);
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    json_error('validation_error', 'Body harus berupa JSON object.', 400);
}

$identifier = sanitize_text((string) ($body['username'] ?? ''));
$password = (string) ($body['password'] ?? '');

if ($identifier === '' || $password === '') {
    json_error('validation_error', 'username dan password wajib diisi.', 400);
}

if (!check_login_rate_limit($identifier)) {
    json_error('rate_limited', 'Terlalu banyak percobaan login.', 429);
}

$db = get_db();
$stmt = $db->prepare('SELECT id, username, password_hash, role, is_active FROM users WHERE (username = ? OR email = ?) LIMIT 1');
$stmt->execute([$identifier, $identifier]);
$user = $stmt->fetch();

if (!$user || !$user['is_active'] || !password_verify($password, $user['password_hash'])) {
    record_login_attempt($identifier, false);
    json_error('unauthorized', 'Kredensial salah.', 401);
}

record_login_attempt($identifier, true);
log_audit('api_login', 'users', (string) $user['id'], (int) $user['id']);

$token = jwt_encode(['sub' => (int) $user['id'], 'role' => $user['role']]);

json_response([
    'success' => true,
    'data' => [
        'token' => $token,
        'token_type' => 'Bearer',
        'expires_in' => JWT_TTL,
        'user' => [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
        ],
    ],
]);
