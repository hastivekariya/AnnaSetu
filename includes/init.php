
<?php

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/annasetu',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

date_default_timezone_set('Asia/Kolkata');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/config/app.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/language.php';

if (!isset($_SESSION['language'])) {
    $_SESSION['language'] = 'gu';
}

if (!in_array($_SESSION['language'], ['en', 'gu'], true)) {
    $_SESSION['language'] = 'gu';
}

if (!defined('APP_NAME')) {
    define('APP_NAME', 'Anganwadi Stock Management');
}

if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.0.0');
}
