<?php
/**
 * Authenticated access to operational uploads stored outside the web root.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/vehicle-data.php';

function gt_private_file_url(string $storedPath): string
{
    return 'private-file.php?path=' . rawurlencode($storedPath);
}

function gt_upload_path_is_registered(string $storedPath): bool
{
    $data = loadVehicles();
    foreach ($data['vehicles'] as $vehicle) {
        if (!is_array($vehicle)) {
            continue;
        }
        foreach (['quote_spec_files', 'quote_image_files', 'vehicle_certificate_files'] as $field) {
            foreach ($vehicle[$field] ?? [] as $candidate) {
                if (is_string($candidate) && $candidate === $storedPath) {
                    return true;
                }
            }
        }
    }
    return false;
}

function gt_resolve_private_upload(string $storedPath, bool $requireRegistered = true): ?string
{
    $private = gt_wa_private_dir();
    if ($private === null || !is_dir($private)) {
        return null;
    }

    $storedPath = str_replace('\\', '/', trim($storedPath));
    if ($storedPath === '' || str_contains($storedPath, "\0") || str_contains($storedPath, '..')) {
        return null;
    }
    $storedPath = ltrim($storedPath, '/');

    $general = false;
    if (preg_match('#^uploads/general/([^/]+)$#', $storedPath, $match) === 1) {
        if (preg_match('/^[A-Za-z0-9._-]+$/', $match[1]) !== 1) {
            return null;
        }
        $general = true;
    } elseif (preg_match('#^uploads/(quotes|certificates)/([A-Za-z0-9-]+)/([A-Za-z0-9._/-]+)$#', $storedPath, $match) !== 1) {
        return null;
    }

    $uploadsRoot = $private . '/uploads';
    if (!is_dir($uploadsRoot)) {
        return null;
    }
    $candidate = $private . '/' . $storedPath;
    $realRoot = realpath($uploadsRoot);
    $realFile = realpath($candidate);
    if ($realRoot === false || $realFile === false || !is_file($realFile)) {
        return null;
    }
    if (!str_starts_with($realFile, $realRoot . DIRECTORY_SEPARATOR)) {
        return null;
    }
    if ($requireRegistered && !$general && !gt_upload_path_is_registered($storedPath)) {
        return null;
    }
    return $realFile;
}

function gt_save_quote_uploads($field, $ref, $subdir, $allowed_mimes): array
{
    $saved = [];
    if (empty($_FILES[$field]['name'][0]) || !gt_wa_private_storage_ready()) {
        return $saved;
    }
    $safe_ref = preg_replace('/[^a-zA-Z0-9\-]/', '', strtolower((string)$ref));
    if ($safe_ref === '') {
        return $saved;
    }

    if ($subdir === 'vehicle-certificates') {
        $dir = rtrim(CERTIFICATE_UPLOAD_DIR, '/') . '/' . $safe_ref . '/';
        $storedPrefix = 'uploads/certificates/' . $safe_ref . '/';
    } else {
        $safe_subdir = preg_replace('/[^a-z0-9\-]/', '', strtolower((string)$subdir));
        if ($safe_subdir === '') {
            return $saved;
        }
        $dir = rtrim(QUOTE_UPLOAD_DIR, '/') . '/' . $safe_ref . '/' . $safe_subdir . '/';
        $storedPrefix = 'uploads/quotes/' . $safe_ref . '/' . $safe_subdir . '/';
    }
    if (!gt_wa_mkdir_private($dir)) {
        return $saved;
    }

    foreach ($_FILES[$field]['tmp_name'] as $i => $tmp) {
        if (($_FILES[$field]['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        $mime = mime_content_type($tmp);
        if (!in_array($mime, $allowed_mimes, true)) {
            continue;
        }
        $original = basename((string)($_FILES[$field]['name'][$i] ?? ''));
        $original = preg_replace('/[^a-zA-Z0-9._-]/', '_', $original);
        if ($original === null || $original === '' || $original === '.' || $original === '..') {
            $original = 'file_' . time();
        }
        $filename = date('YmdHis') . '_' . sprintf('%02d', $i + 1) . '_' . $original;
        $dest = $dir . $filename;
        if (move_uploaded_file($tmp, $dest)) {
            @chmod($dest, 0600);
            $saved[] = $storedPrefix . $filename;
        }
    }
    return $saved;
}

function gt_send_private_upload(string $storedPath): void
{
    $real = gt_resolve_private_upload($storedPath, true);
    if ($real === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo "Not Found\n";
        return;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($real);
    $allowed = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf', 'text/plain', 'text/csv',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip', 'application/x-zip-compressed',
    ];
    if (!is_string($mime) || !in_array($mime, $allowed, true)) {
        $mime = 'application/octet-stream';
    }
    $downloadName = basename($real);
    $downloadName = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName) ?: 'file';
    $disposition = (str_starts_with($mime, 'image/') || $mime === 'application/pdf') ? 'inline' : 'attachment';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($real));
    header('Content-Disposition: ' . $disposition . '; filename="' . $downloadName . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($real);
}
