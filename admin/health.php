<?php
/**
 * Lightweight CLI-only admin diagnostics.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Not Found\n";
    exit;
}

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: text/plain; charset=UTF-8');

$checks = [
    'php_version' => PHP_VERSION,
    'frontend_dir_exists' => is_dir(FRONTEND_DIR) ? 'yes' : 'no',
    'vehicles_json_exists' => is_file(VEHICLES_JSON) ? 'yes' : 'no',
    'vehicle_data_exists' => is_file(__DIR__ . '/vehicle-data.php') ? 'yes' : 'no',
    'password_configured' => (getConfiguredAdminPassword() !== '') ? 'yes' : 'no',
];

foreach ($checks as $key => $value) {
    echo $key . ': ' . $value . "\n";
}

echo "status: ok\n";
