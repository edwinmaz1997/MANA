<?php
/**
 * MANA - núcleo del sistema
 * Compatible con PHP 7.4+
 */
define('APP_ROOT', dirname(__DIR__));
define('APP_NAME', 'Rutero');
define('APP_VERSION', '1.0.0');

if (!file_exists(APP_ROOT . '/config.php')) {
    header('Location: install.php');
    exit;
}
require APP_ROOT . '/config.php';

date_default_timezone_set(defined('APP_TZ') ? APP_TZ : 'America/Guatemala');
error_reporting(E_ALL);
ini_set('display_errors', defined('APP_DEBUG') && APP_DEBUG ? '1' : '0');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('MANASESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

require __DIR__ . '/helpers.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/creditos.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/migrate.php';
migrar();
