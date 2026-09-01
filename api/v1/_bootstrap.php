<?php
/**
 * Bootstrap API: CORS (whitelist origin), rate limit per IP,
 * dan autentikasi API key (X-API-Key) / JWT (Bearer).
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/response.php';
require_once dirname(__DIR__, 2) . '/includes/rate_limit.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

function api_cors_headers(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && in_array($origin, CORS_ORIGINS, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: X-API-Key, Authorization, Content-Type');
        header('Access-Control-Max-Age: 86400');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function api_ip_rate_limit(): void
{
    if (api_rate_limited(null)) {
        json_error('rate_limited', 'Terlalu banyak permintaan. Silakan coba lagi nanti.', 429);
    }
}

function api_key_touch_last_used(int $api_key_id): void
{
    $stmt = get_db()->prepare(
        'UPDATE api_keys SET last_used_at = NOW()
         WHERE id = ? AND (last_used_at IS NULL OR last_used_at < NOW() - INTERVAL 60 SECOND)'
    );
    $stmt->execute([$api_key_id]);
}

/**
 * Validasi API key atau Bearer JWT.
 *
 * @return array{type:string, api_key?:array, user?:array}
 */
function api_authenticate(): array
{
    $key = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($key !== '') {
        $hash = hash('sha256', $key);
        $stmt = get_db()->prepare('SELECT * FROM api_keys WHERE key_hash = ? LIMIT 1');
        $stmt->execute([$hash]);
        $api_key = $stmt->fetch();
        if (!$api_key || !$api_key['is_active']) {
            json_error('unauthorized', 'API key tidak valid atau nonaktif.', 401);
        }
        api_key_touch_last_used((int) $api_key['id']);
        return ['type' => 'api_key', 'api_key' => $api_key];
    }

    $authz = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^Bearer\s+(.+)$/i', $authz, $m)) {
        $payload = jwt_decode($m[1]);
        if ($payload === null || empty($payload['sub'])) {
            json_error('unauthorized', 'Token tidak valid atau kedaluwarsa.', 401);
        }
        $stmt = get_db()->prepare('SELECT id, username, role, is_active FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $payload['sub']]);
        $user = $stmt->fetch();
        if (!$user || !$user['is_active']) {
            json_error('unauthorized', 'User tidak ditemukan atau nonaktif.', 401);
        }
        return ['type' => 'jwt', 'user' => $user];
    }

    json_error('unauthorized', 'Header X-API-Key atau Bearer token diperlukan.', 401);
}
