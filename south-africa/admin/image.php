<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

sa_require_admin();

function sa_admin_image_missing(): never
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: private, no-store');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    echo "Image not found.\n";
    exit;
}

$ref = strtoupper(trim((string)($_GET['ref'] ?? '')));
$file = trim((string)($_GET['file'] ?? ''));
if (!preg_match('/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/', $ref) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.(?:jpe?g|png|webp)$/i', $file)) {
    sa_admin_image_missing();
}

$relative = 'images/' . $ref . '/' . $file;

try {
    sa_validate_gallery_path($relative, $ref, false);
    $loaded = sa_load_vehicle_data(false, false);
    $vehicle = sa_find_vehicle($loaded['data'], $ref);
    $gallery = is_array($vehicle) ? ($vehicle['vehicle']['gallery'] ?? []) : [];
    if (!is_array($vehicle) || !is_array($gallery) || !in_array($relative, $gallery, true)) {
        sa_admin_image_missing();
    }
    $path = sa_resolve_gallery_image($relative);
} catch (Throwable $exception) {
    sa_admin_image_missing();
}

if ($path === null || !is_file($path)) {
    sa_admin_image_missing();
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($path);
if (!is_string($mime) || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    sa_admin_image_missing();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');
readfile($path);
