<?php
/**
 * Lightweight admin diagnostics (no login required).
 * Delete or protect this file after troubleshooting if desired.
 */
require_once __DIR__ . '/bootstrap.php';

// #region agent log
@file_put_contents('/opt/cursor/logs/debug.log', json_encode(['hypothesisId' => 'D', 'location' => 'admin/health.php:entry', 'message' => 'Health diagnostics reached', 'data' => ['sapi' => PHP_SAPI, 'session_active' => session_status() === PHP_SESSION_ACTIVE, 'admin_auth_present' => isset($_SESSION) && !empty($_SESSION['admin_logged_in'])], 'timestamp' => (int) floor(microtime(true) * 1000)]) . "\n", FILE_APPEND | LOCK_EX);
// #endregion

header('Content-Type: text/plain; charset=UTF-8');

$frontend_dir = dirname(__DIR__) . '/frontend';
$checks = [
    'php_version' => PHP_VERSION,
    'frontend_dir' => $frontend_dir,
    'frontend_dir_exists' => is_dir($frontend_dir) ? 'yes' : 'no',
    'vehicles_json' => $frontend_dir . '/data/vehicles.json',
    'vehicles_json_exists' => is_file($frontend_dir . '/data/vehicles.json') ? 'yes' : 'no',
    'bootstrap_php' => __DIR__ . '/bootstrap.php',
    'vehicle_data_php' => __DIR__ . '/vehicle-data.php',
    'vehicle_data_exists' => is_file(__DIR__ . '/vehicle-data.php') ? 'yes' : 'no',
    'password_txt' => __DIR__ . '/password.txt',
    'password_configured' => (getConfiguredAdminPassword() !== '') ? 'yes' : 'no',
    'session_save_path' => session_save_path(),
    'session_save_path_writable' => is_writable(session_save_path()) ? 'yes' : 'no',
];

foreach ($checks as $key => $value) {
    echo $key . ': ' . $value . "\n";
}

echo "status: ok\n";
