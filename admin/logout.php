<?php
/**
 * Logout admin (POST + CSRF).
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
require_once APP_ROOT . '/config/config.php';
require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    logout_user();
}
flash_set('info', 'Anda telah logout.');
redirect_admin('login');
