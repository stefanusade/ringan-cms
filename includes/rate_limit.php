<?php
/**
 * Rate limiting: brute-force login & API.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function login_identifier(string $username): string
{
    return mb_strtolower(trim($username)) . '|' . client_ip();
}

function check_login_rate_limit(string $username): bool
{
    $db = get_db();
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE identifier = ? AND success = 0
           AND attempted_at > (NOW() - INTERVAL ? MINUTE)'
    );
    $stmt->execute([login_identifier($username), LOGIN_LOCK_MINUTES]);
    return ((int) $stmt->fetchColumn()) < LOGIN_MAX_ATTEMPTS;
}

function record_login_attempt(string $username, bool $success): void
{
    $db = get_db();
    $stmt = $db->prepare('INSERT INTO login_attempts (identifier, success) VALUES (?, ?)');
    $stmt->execute([login_identifier($username), $success ? 1 : 0]);
    // bersihkan data lama sekali-sekali
    if (random_int(1, 50) === 1) {
        $db->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 24 HOUR)');
    }
}

function api_rate_limited(?int $api_key_id): bool
{
    $db = get_db();
    if ($api_key_id !== null) {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM api_rate_log
             WHERE api_key_id = ? AND requested_at > (NOW() - INTERVAL ? SECOND)'
        );
        $stmt->execute([$api_key_id, API_RATE_WINDOW]);
    } else {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM api_rate_log
             WHERE api_key_id IS NULL AND ip_address = ? AND requested_at > (NOW() - INTERVAL ? SECOND)'
        );
        $stmt->execute([client_ip(), API_RATE_WINDOW]);
    }
    return ((int) $stmt->fetchColumn()) >= API_RATE_LIMIT;
}

function record_api_request(?int $api_key_id): void
{
    $db = get_db();
    $stmt = $db->prepare('INSERT INTO api_rate_log (api_key_id, ip_address) VALUES (?, ?)');
    $stmt->execute([$api_key_id, client_ip()]);
    if (random_int(1, 50) === 1) {
        $db->exec('DELETE FROM api_rate_log WHERE requested_at < (NOW() - INTERVAL 24 HOUR)');
    }
}
