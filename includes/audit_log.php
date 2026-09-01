<?php
/**
 * Pencatatan aktivitas sensitif (audit trail).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/rate_limit.php';

function log_audit(string $action, ?string $target_table = null, ?string $target_id = null, ?int $user_id = null): void
{
    try {
        $db = get_db();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (mb_strlen($ua) > 255) {
            $ua = mb_substr($ua, 0, 255);
        }
        $stmt = $db->prepare(
            'INSERT INTO audit_log (user_id, action, target_table, target_id, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$user_id, $action, $target_table, $target_id, client_ip(), $ua]);
    } catch (Throwable $e) {
        // audit log tidak boleh menggagalkan request utama
        if (function_exists('log_error')) {
            log_error('Gagal menulis audit log: ' . $e->getMessage(), 'audit');
        }
    }
}
