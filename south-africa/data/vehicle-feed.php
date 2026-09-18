<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/vehicle-store.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

try {
    $loaded = sa_load_vehicle_data(true, false);
    echo sa_encode_vehicle_data(sa_published_vehicle_data($loaded['data']));
} catch (Throwable $exception) {
    error_log('South Africa public vehicle feed failed: ' . $exception->getMessage());
    http_response_code(503);
    echo json_encode(
        ['vehicles' => [], 'error' => 'Vehicle information is temporarily unavailable.'],
        JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
}
