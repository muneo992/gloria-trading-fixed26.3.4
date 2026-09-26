<?php
declare(strict_types=1);

require_once __DIR__ . '/public-vehicle-data.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

try {
    $raw = file_get_contents(__DIR__ . '/vehicles.json');
    if ($raw === false) {
        throw new RuntimeException('Vehicle master is unavailable.');
    }
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    $public = gt_public_vehicle_data($decoded);

    echo json_encode(
        $public,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (Throwable $exception) {
    http_response_code(503);
    echo json_encode(
        ['vehicles' => [], 'error' => 'Vehicle information is temporarily unavailable.'],
        JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
}
