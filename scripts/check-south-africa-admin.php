<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/south-africa/lib/vehicle-store.php';
require_once dirname(__DIR__) . '/south-africa/lib/admin-auth.php';

$temp = sys_get_temp_dir() . '/gloria-sa-admin-test-' . bin2hex(random_bytes(4));
putenv('GLORIA_SA_DATA_DIR=' . $temp);
$_ENV['GLORIA_SA_DATA_DIR'] = $temp;
sa_ensure_runtime_directories();

$loaded = sa_load_vehicle_data(false, false);
if (count($loaded['data']['vehicles']) < 4) {
    fwrite(STDERR, "Seed vehicles missing\n");
    exit(1);
}
foreach ($loaded['data']['vehicles'] as $vehicle) {
    if (($vehicle['listing_type'] ?? '') !== 'sample' || ($vehicle['status'] ?? '') !== 'published') {
        fwrite(STDERR, "Seed vehicles must remain sample/published\n");
        exit(1);
    }
}

$published = sa_published_vehicle_data($loaded['data']);
if (count($published['vehicles']) !== count($loaded['data']['vehicles'])) {
    fwrite(STDERR, "Unexpected published count\n");
    exit(1);
}

$record = sa_build_vehicle_record([], [
    'ref_id' => 'SA-TEST-001',
    'make' => 'Nissan',
    'model' => 'Leaf',
    'listing_type' => 'sample',
    'status' => 'draft',
    'year' => '',
    'mileage_km' => '',
    'powertrain' => 'EV',
    'battery' => '',
    'range' => '',
    'reference_price_usd' => '',
], []);
sa_save_vehicle_record($record, null, $loaded['version']);

$after = sa_load_vehicle_data(false, false);
$found = sa_find_vehicle($after['data'], 'SA-TEST-001');
if ($found === null || $found['vehicle']['status'] !== 'draft') {
    fwrite(STDERR, "Draft save failed\n");
    exit(1);
}
if (count(sa_published_vehicle_data($after['data'])['vehicles']) !== 4) {
    fwrite(STDERR, "Draft leaked into public list\n");
    exit(1);
}

$found['vehicle']['status'] = 'published';
$found['vehicle']['listing_type'] = 'available';
$found['vehicle']['reference_price_usd'] = 12000;
$found['vehicle']['price_as_of'] = '2026-09-18';
sa_validate_vehicle_record($found['vehicle'], false);
sa_save_vehicle_record($found['vehicle'], 'SA-TEST-001', $after['version']);

$priced = sa_load_vehicle_data(false, false);
$again = sa_find_vehicle($priced['data'], 'SA-TEST-001');
if (($again['vehicle']['reference_price_usd'] ?? null) !== 12000) {
    fwrite(STDERR, "Price update failed\n");
    exit(1);
}

sa_delete_vehicle_record('SA-TEST-001', $priced['version']);
$final = sa_load_vehicle_data(false, false);
if (sa_find_vehicle($final['data'], 'SA-TEST-001') !== null) {
    fwrite(STDERR, "Delete failed\n");
    exit(1);
}

$tokenPath = sa_bootstrap_token_path();
$hashPath = sa_admin_hash_file_path();
$setupToken = bin2hex(random_bytes(32));
if (file_put_contents($tokenPath, $setupToken) === false) {
    fwrite(STDERR, "Bootstrap token write failed\n");
    exit(1);
}
chmod($tokenPath, 0600);
if (sa_admin_can_bootstrap() !== true) {
    fwrite(STDERR, "Bootstrap should be available before the hash exists\n");
    exit(1);
}
try {
    sa_attempt_bootstrap('wrong-token', 'long-enough-password', 'long-enough-password');
    fwrite(STDERR, "Wrong setup key was accepted\n");
    exit(1);
} catch (Throwable $exception) {
    if (!is_file($tokenPath) || is_file($hashPath)) {
        fwrite(STDERR, "Failed bootstrap must not consume the token or write a hash\n");
        exit(1);
    }
}
sa_attempt_bootstrap($setupToken, 'long-enough-password', 'long-enough-password');
if (is_file($tokenPath)) {
    fwrite(STDERR, "Bootstrap token was not removed\n");
    exit(1);
}
if (!is_file($hashPath)) {
    fwrite(STDERR, "Password hash was not created\n");
    exit(1);
}
if ((fileperms($hashPath) & 0777) !== 0600) {
    fwrite(STDERR, "Password hash mode is not 600\n");
    exit(1);
}
$stored = trim((string)file_get_contents($hashPath));
$info = password_get_info($stored);
if (($info['algoName'] ?? 'unknown') === 'unknown' || !password_verify('long-enough-password', $stored)) {
    fwrite(STDERR, "Stored hash is invalid\n");
    exit(1);
}
if (sa_admin_can_bootstrap()) {
    fwrite(STDERR, "Bootstrap remained open after success\n");
    exit(1);
}
try {
    sa_attempt_bootstrap($setupToken, 'another-long-password', 'another-long-password');
    fwrite(STDERR, "Second bootstrap was accepted\n");
    exit(1);
} catch (Throwable $exception) {
}

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->isFile()) {
        @unlink($file->getPathname());
    }
}
@rmdir($temp . '/backups');
@rmdir($temp . '/images');
@rmdir($temp);
fwrite(STDOUT, "South Africa admin store checks passed.\n");
