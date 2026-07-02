<?php
/**
 * Shared path configuration for admin (frontend/ + west_africa/ layout).
 */
if (!defined('FRONTEND_DIR')) {
    define('FRONTEND_DIR', dirname(__DIR__) . '/frontend');
}
if (!defined('VEHICLES_JSON')) {
    define('VEHICLES_JSON', FRONTEND_DIR . '/data/vehicles.json');
}
if (!defined('IMAGES_DIR')) {
    define('IMAGES_DIR', FRONTEND_DIR . '/images/vehicles/');
}
if (!defined('IMAGES_URL')) {
    define('IMAGES_URL', '../images/vehicles/');
}
if (!defined('GENERAL_UPLOAD_DIR')) {
    define('GENERAL_UPLOAD_DIR', FRONTEND_DIR . '/uploads/general/');
}
if (!defined('GENERAL_UPLOAD_URL')) {
    define('GENERAL_UPLOAD_URL', 'uploads/general/');
}

if (!defined('QUOTE_UPLOAD_DIR')) {
    define('QUOTE_UPLOAD_DIR', FRONTEND_DIR . '/uploads/quotes/');
}
if (!defined('QUOTE_UPLOAD_URL')) {
    define('QUOTE_UPLOAD_URL', 'uploads/quotes/');
}

function getConfiguredAdminPassword(): string
{
    $env_password = getenv('GLORIA_ADMIN_PASSWORD');
    if (is_string($env_password) && trim($env_password) !== '') {
        return trim($env_password);
    }

    $password_file = __DIR__ . '/password.txt';
    if (is_readable($password_file)) {
        $file_password = trim((string) file_get_contents($password_file));
        if ($file_password !== '') {
            return $file_password;
        }
    }

    return '';
}
