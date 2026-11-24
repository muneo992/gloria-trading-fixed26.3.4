<?php
declare(strict_types=1);

const GT_LHD_BACKUP_NAME = '/^snapshot-\d{8}T\d{6}Z-[a-f0-9]{12}$/D';
const GT_LHD_BACKUP_DAYS = 30;

function gt_lhd_backup_root(string $directory): string
{
    $root = $directory . '/backups';
    if (is_link($root) || (!is_dir($root) && !@mkdir($root, 0700) && !is_dir($root))
        || realpath($root) !== realpath($directory) . DIRECTORY_SEPARATOR . 'backups') {
        throw new RuntimeException('LHD backup directory is unavailable.');
    }
    return $root;
}

function gt_lhd_remove_snapshot(string $directory): void
{
    if (is_link($directory) || !is_dir($directory)) {
        throw new RuntimeException('Invalid LHD backup directory.');
    }
    foreach (new FilesystemIterator($directory) as $file) {
        $name = $file->getFilename();
        if ((!in_array($name, ['lhd-vehicles.json', 'sha256.json'], true) && !preg_match(GT_LHD_IMAGE_PATTERN, $name))
            || (!$file->isFile() && !$file->isLink())) {
            throw new RuntimeException('Unexpected LHD backup entry.');
        }
    }
    foreach (new FilesystemIterator($directory) as $file) {
        if (!unlink($file->getPathname())) {
            throw new RuntimeException('Cannot remove LHD backup file.');
        }
    }
    if (!rmdir($directory)) {
        throw new RuntimeException('Cannot remove LHD backup directory.');
    }
}

function gt_lhd_snapshot_hashes(string $source, array $records, string $destination): array
{
    $files = ['lhd-vehicles.json' => null];
    foreach ($records as $record) {
        if (($record['image'] ?? '') !== '') {
            $path = gt_lhd_image_path($source, $record);
            if ($path === null || (getimagesize($path)[2] ?? null) !== IMAGETYPE_JPEG) {
                throw new RuntimeException('LHD backup image is missing or invalid.');
            }
            $files[basename($path)] = $path;
        }
    }
    gt_lhd_write($destination, $records);
    $hashes = [];
    foreach ($files as $name => $path) {
        $target = $destination . '/' . $name;
        if ($path !== null && (!copy($path, $target) || !chmod($target, 0600))) {
            throw new RuntimeException('LHD backup image copy failed.');
        }
        $hash = hash_file('sha256', $target);
        if ($hash === false || ($path !== null && hash_file('sha256', $path) !== $hash)) {
            throw new RuntimeException('LHD backup integrity check failed.');
        }
        $hashes[$name] = $hash;
    }
    ksort($hashes);
    return $hashes;
}

// The caller holds master.lock for the entire snapshot and write.
function gt_lhd_backup(string $directory, array $records): string
{
    $root = gt_lhd_backup_root($directory);
    $name = 'snapshot-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(6));
    $staging = $root . '/.' . $name;
    $final = $root . '/' . $name;
    if (!mkdir($staging, 0700)) {
        throw new RuntimeException('LHD backup staging failed.');
    }
    try {
        $hashes = gt_lhd_snapshot_hashes($directory, $records, $staging);
        if (file_put_contents($staging . '/sha256.json', json_encode($hashes, JSON_THROW_ON_ERROR)) === false
            || !chmod($staging . '/sha256.json', 0600) || !rename($staging, $final)) {
            throw new RuntimeException('LHD backup commit failed.');
        }
    } finally {
        if (is_dir($staging)) {
            gt_lhd_remove_snapshot($staging);
        }
    }
    $cutoff = 'snapshot-' . gmdate('Ymd\THis\Z', time() - GT_LHD_BACKUP_DAYS * 86400);
    foreach (new FilesystemIterator($root) as $entry) {
        $old = $entry->getFilename();
        if (preg_match(GT_LHD_BACKUP_NAME, $old) && strcmp($old, $cutoff) < 0
            && $entry->isDir() && !$entry->isLink()) {
            gt_lhd_remove_snapshot($entry->getPathname());
        }
    }
    return $name;
}

// Create a new isolated directory and compare the restored files with the manifest.
function gt_lhd_restore_copy(string $snapshot, string $destination): array
{
    $manifest = $snapshot . '/sha256.json';
    if (is_link($snapshot) || is_link($manifest) || !is_file($manifest)
        || realpath(dirname($destination)) === false || file_exists($destination) || is_link($destination)) {
        throw new RuntimeException('Invalid LHD restore paths.');
    }
    $records = gt_lhd_read($snapshot);
    $expected = json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($expected) || !isset($expected['lhd-vehicles.json'])
        || hash_file('sha256', $snapshot . '/lhd-vehicles.json') !== $expected['lhd-vehicles.json']) {
        throw new RuntimeException('LHD backup master checksum mismatch.');
    }
    if (!mkdir($destination, 0700)) {
        throw new RuntimeException('LHD restore directory could not be created.');
    }
    try {
        if (gt_lhd_snapshot_hashes($snapshot, $records, $destination) !== $expected
            || gt_lhd_read($destination) !== $records) {
            throw new RuntimeException('LHD recovery verification failed.');
        }
    } catch (Throwable $error) {
        gt_lhd_remove_snapshot($destination);
        throw $error;
    }
    return $records;
}
