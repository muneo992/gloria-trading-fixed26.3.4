<?php
declare(strict_types=1);

// Run over SSH stdin. The production input is the public HTTPS catalog only.
const TEST_WEB = '/home/gltr/www/gloria-test';
const TEST_PRIVATE = '/home/gltr/private/west-africa-test';
const PUBLIC_SITE = 'https://www.gloriatrading.com/';
if (PHP_SAPI !== 'cli' || realpath(TEST_WEB) !== TEST_WEB || realpath(TEST_PRIVATE) !== TEST_PRIVATE) {
    exit(1);
}
require TEST_WEB . '/admin/vehicle-data.php';
require TEST_WEB . '/frontend/data/public-vehicle-data.php';

function ensure(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
}

function publicBytes(string $path): string
{
    $curl = curl_init(PUBLIC_SITE . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_MAXFILESIZE => 12582912]);
    $bytes = curl_exec($curl);
    ensure(is_string($bytes) && curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200, 'public_download_failed');
    return $bytes;
}

umask(0077);
$created = [];
$stage = [];
$committed = false;
$status = 0;
try {
    ensure(gt_wa_private_dir() === TEST_PRIVATE && gt_wa_site_root() === TEST_WEB, 'test_storage_mismatch');
    $master = TEST_PRIVATE . '/vehicles.json';
    $images = TEST_WEB . '/frontend/images/vehicles';
    ensure(realpath($images) === $images && is_file($master) && !is_link($master)
        && stat($master)['nlink'] === 1, 'invalid_test_paths');
    $before = file_get_contents($master);
    ensure(is_string($before), 'test_read_failed');
    $test = json_decode($before, true, 512, JSON_THROW_ON_ERROR);
    $public = json_decode(publicBytes('data/vehicles.json'), true, 512, JSON_THROW_ON_ERROR);
    ensure(count($public['vehicles']) === 26, 'unexpected_public_count');
    $existing = array_column($test['vehicles'], null, 'ref_id');
    ensure(count($existing) === count($test['vehicles']), 'duplicate_test_ids');
    $incoming = array_column(gt_public_vehicle_data($public)['vehicles'], null, 'ref_id');
    ensure(count($incoming) === 26 && array_diff_key($existing, $incoming) === [], 'catalog_id_mismatch');
    $missing = array_diff_key($incoming, $existing);
    if ($missing === []) { echo "test_rhd_already_26\n"; exit; }
    ensure(count($existing) === 19 && count($missing) === 7, 'unexpected_sync_scope');
    foreach ($missing as $id => $item) {
        ensure(preg_match('/^REF-02[0-6]$/D', $id) === 1 && $item['gallery'] !== [], 'unexpected_public_record');
        foreach ($item['gallery'] as $photo) {
            ensure(preg_match('#^images/vehicles/' . strtolower($id) . '-[0-9]+\.jpg$#D', $photo) === 1, 'unexpected_photo_path');
            $file = $images . '/' . basename($photo);
            $bytes = publicBytes($photo);
            ensure((getimagesizefromstring($bytes)[2] ?? null) === IMAGETYPE_JPEG, 'invalid_catalog_photo');
            ensure(!is_link($file), 'linked_test_photo');
            if (file_exists($file)) {
                ensure(is_file($file) && hash_file('sha256', $file) === hash('sha256', $bytes), 'existing_photo_differs');
                continue;
            }
            $temp = tempnam($images, '.rhd-sync-');
            ensure(is_string($temp), 'photo_staging_failed');
            $stage[$file] = $temp;
            ensure(file_put_contents($temp, $bytes) === strlen($bytes) && chmod($temp, 0644), 'photo_staging_failed');
        }
    }
    $backupRoot = TEST_PRIVATE . '/backups';
    ensure(!is_link($backupRoot) && gt_wa_mkdir_private($backupRoot) && realpath($backupRoot) === $backupRoot, 'backup_root_failed');
    $backup = $backupRoot . '/public-sync-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
    ensure(mkdir($backup, 0700), 'backup_directory_failed');
    ensure(file_put_contents($backup . '/vehicles.json', $before) === strlen($before)
        && file_get_contents($backup . '/vehicles.json') === $before, 'backup_verification_failed');
    $manifest = json_encode(array_map('basename', array_keys($stage)), JSON_THROW_ON_ERROR);
    ensure(file_put_contents($backup . '/added-photos.json', $manifest) === strlen($manifest), 'backup_manifest_failed');
    $test['vehicles'] = [...$test['vehicles'], ...array_values($missing)];
    $json = json_encode($test, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $candidate = tempnam(TEST_PRIVATE, '.rhd-sync-');
    ensure(is_string($candidate), 'master_staging_failed');
    ensure(file_put_contents($candidate, $json) === strlen($json) && chmod($candidate, 0600), 'master_staging_failed');
    $handle = fopen($master, 'r+');
    ensure(is_resource($handle) && flock($handle, LOCK_EX) && stream_get_contents($handle) === $before, 'test_master_changed');
    foreach ($stage as $file => $temp) {
        ensure(link($temp, $file), 'photo_install_failed');
        $created[] = $file;
    }
    ensure(rename($candidate, $master), 'atomic_master_install_failed');
    $committed = true;
    echo 'test_rhd_public_sync_count=26 added_photos=' . count($created) . ' backup=' . basename($backup) . PHP_EOL;
} catch (Throwable $error) {
    $label = $error->getMessage();
    fwrite(STDERR, 'public_sync_failed=' . (preg_match('/^[a-z_]+$/D', $label) ? $label : 'runtime_error') . PHP_EOL);
    $status = 1;
} finally {
    if (!$committed) foreach ($created as $file) @unlink($file);
    foreach ($stage as $temp) @unlink($temp);
    if (isset($candidate) && is_string($candidate) && is_file($candidate)) @unlink($candidate);
    if (isset($handle) && is_resource($handle)) fclose($handle);
}
exit($status);
