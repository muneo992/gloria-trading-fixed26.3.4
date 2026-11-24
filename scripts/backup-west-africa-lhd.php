<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/admin/lhd-vehicle-data.php';

umask(0077);
$lock = null;
try {
    $directory = gt_lhd_directory(true);
    if ($directory === null || is_link($directory . '/master.lock')) {
        throw new RuntimeException('LHD storage unavailable.');
    }
    $lock = fopen($directory . '/master.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('LHD lock unavailable.');
    }
    echo gt_lhd_backup($directory, gt_lhd_read($directory)) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, "LHD backup failed. Verify private storage and image integrity.\n");
    exit(1);
} finally {
    if (is_resource($lock)) {
        fclose($lock);
    }
}
