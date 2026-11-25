<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/admin/lhd-vehicle-data.php';

ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    $directory = gt_lhd_directory();
    $records = gt_lhd_read($directory);
    $today = gmdate('Y-m-d');
    $public = [];
    foreach ($records as $ref => $record) {
        if (!gt_lhd_eligible($record, $today)) {
            continue;
        }
        $image = gt_lhd_image_path($directory, $record);
        if (isset($_GET['image'])) {
            if ($_GET['image'] === $ref) {
                gt_lhd_send_image($image);
                return;
            }
            continue;
        }
        $public[] = gt_lhd_public_record($ref, $record, $image !== null);
    }
    if (isset($_GET['image'])) {
        http_response_code(404);
        return;
    }
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['vehicles' => $public], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(503);
    header('Content-Type: application/json; charset=UTF-8');
    echo '{"vehicles":[],"error":"LHD vehicle selections are temporarily unavailable."}';
}
