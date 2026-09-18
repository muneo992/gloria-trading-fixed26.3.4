<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/vehicle-store.php';

function sa_image_not_found(): never
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo "Image not found.\n";
    exit;
}

$ref = strtoupper(trim((string)($_GET['ref'] ?? '')));
$file = trim((string)($_GET['file'] ?? ''));
$relative = 'images/' . $ref . '/' . $file;

try {
    sa_validate_gallery_path($relative, $ref, false);
    $loaded = sa_load_vehicle_data(false, false);
    $vehicle = sa_find_vehicle($loaded['data'], $ref);
    if ($vehicle === null || !in_array($relative, $vehicle['vehicle']['gallery'] ?? [], true)) {
        sa_image_not_found();
    }
} catch (Throwable $exception) {
    sa_image_not_found();
}

$runtimeRoot = realpath(sa_runtime_image_dir());
$path = realpath(sa_runtime_image_dir() . '/' . $ref . '/' . $file);
if ($runtimeRoot === false || $path === false || !str_starts_with($path, $runtimeRoot . DIRECTORY_SEPARATOR) || !is_file($path)) {
    sa_image_not_found();
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($path);
if (!is_string($mime) || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    sa_image_not_found();
}

$etag = '"' . hash_file('sha256', $path) . '"';
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    header('ETag: ' . $etag);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
readfile($path);
