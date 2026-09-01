<?php
/**
 * Helper respons: JSON, redirect, flash message, logging, security headers.
 */

declare(strict_types=1);

require_once __DIR__ . '/validation.php';

function json_response(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $code, string $message, int $http_code = 400, array $details = []): void
{
    $error = ['code' => $code, 'message' => $message];
    if ($details !== []) {
        $error['details'] = $details;
    }
    json_response(['success' => false, 'error' => $error], $http_code);
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function admin_url(string $path = ''): string
{
    return BASE_URL . '/admin' . ($path === '' ? '' : '/' . ltrim($path, '/'));
}

function redirect_admin(string $path = ''): void
{
    redirect(admin_url($path));
}

function log_error(string $message, string $context = 'app'): void
{
    $line = sprintf("[%s] [%s] %s%s", date('Y-m-d H:i:s'), $context, $message, PHP_EOL);
    @file_put_contents(LOG_DIR . '/app.log', $line, FILE_APPEND | LOCK_EX);
}

function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if (!APP_DEBUG) {
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'");
    }
}

/**
 * Catatan: fungsi flash membutuhkan auth.php dimuat (start_session).
 */
function flash_set(string $type, string $message): void
{
    if (function_exists('start_session')) {
        start_session();
    } else {
        @session_start();
    }
    $_SESSION['flash'][$type] = $message;
}

function flash_get(): array
{
    if (function_exists('start_session')) {
        start_session();
    } else {
        @session_start();
    }
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flash;
}

function render_flash_messages(): string
{
    $html = '';
    foreach (flash_get() as $type => $message) {
        $class = in_array($type, ['success', 'error', 'info', 'warning'], true) ? $type : 'info';
        $html .= '<div class="alert alert-' . $class . '">' . e($message) . '</div>';
    }
    return $html;
}
