<?php
declare(strict_types=1);

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

function remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

$temp = sys_get_temp_dir() . '/gloria-ea-admin-test-' . bin2hex(random_bytes(6));
putenv('GLORIA_EA_DATA_DIR=' . $temp);
$_SERVER['REMOTE_ADDR'] = '127.0.0.99';
$_SERVER['HTTP_HOST'] = 'ea.gloriatrading.com';

require_once dirname(__DIR__) . '/east-africa/admin/bootstrap.php';

try {
    test_assert(ea_runtime_dir() === $temp, 'test runtime directory override');
    $initial = ea_load_vehicle_data(false, true);
    test_assert($initial['source'] === 'seed', 'seed is used before runtime JSON exists');
    test_assert(count($initial['data']['vehicles']) === 3, 'three seed vehicles load');
    ea_ensure_runtime_directories();
    $runtimeImageDir = ea_runtime_image_dir() . '/EA-TEST-IMAGE';
    mkdir($runtimeImageDir, 0700, true);
    copy(dirname(__DIR__) . '/east-africa/images/EA-PBX-001/EA-PBX-001-01.jpg', $runtimeImageDir . '/EA-TEST-IMAGE-sample.jpg');
    test_assert(
        ea_resolve_gallery_image('images/EA-TEST-IMAGE/EA-TEST-IMAGE-sample.jpg') !== null,
        'runtime image storage resolves through the public path namespace'
    );
    ea_remove_created_images(['images/EA-TEST-IMAGE/EA-TEST-IMAGE-sample.jpg']);
    test_assert(!is_file($runtimeImageDir . '/EA-TEST-IMAGE-sample.jpg'), 'failed-request runtime upload cleanup works');

    $first = ea_find_vehicle($initial['data'], 'EA-PBX-001');
    test_assert($first !== null, 'EA-PBX-001 exists');
    $expectedGallery = [];
    for ($number = 1; $number <= 8; $number++) {
        $expectedGallery[] = sprintf('images/EA-PBX-001/EA-PBX-001-%02d.jpg', $number);
    }
    test_assert($first['vehicle']['gallery'] === $expectedGallery, 'EA-PBX-001 gallery order is unchanged');

    $second = ea_find_vehicle($initial['data'], 'EA-PBX-002');
    test_assert($second !== null, 'EA-PBX-002 exists');
    $edited = ea_build_vehicle_record($second['vehicle'], array_merge($second['vehicle'], [
        'mileage_km' => '12345',
        'engine_cc' => '1490',
        'transmission' => 'Automatic',
        'drive' => '2WD',
        'steering' => 'Right hand drive',
        'availability' => 'in_stock',
        'reference_price_usd' => '6100',
        'estimated_cif_mombasa_usd' => '8900',
        'price_as_of' => '2026-09-22',
    ]), $second['vehicle']['gallery']);
    $save = ea_save_vehicle_record($edited, 'EA-PBX-002', $initial['version']);
    test_assert(is_file(ea_runtime_json_path()), 'runtime JSON is created');
    test_assert(is_file($save['backup']), 'pre-write backup is created');
    test_assert(hash_file('sha256', $save['backup']) === hash('sha256', $initial['raw']), 'backup matches pre-write data');

    $runtime = ea_load_vehicle_data(false, true);
    test_assert($runtime['source'] === 'runtime', 'runtime JSON becomes operational source');
    $runtimeSecond = ea_find_vehicle($runtime['data'], 'EA-PBX-002');
    test_assert(($runtimeSecond['vehicle']['mileage_km'] ?? null) === 12345, 'edited specification is saved');
    test_assert(($runtimeSecond['vehicle']['availability'] ?? null) === 'in_stock', 'availability is saved');
    test_assert(($runtimeSecond['vehicle']['reference_price_usd'] ?? null) === 6100, 'FOB price is saved');
    test_assert(($runtimeSecond['vehicle']['estimated_cif_mombasa_usd'] ?? null) === 8900, 'CIF price is saved');
    $runtimeFirst = ea_find_vehicle($runtime['data'], 'EA-PBX-001');
    test_assert($runtimeFirst['vehicle']['gallery'] === $expectedGallery, 'unrelated EA-PBX-001 gallery remains unchanged');

    $conflictDetected = false;
    try {
        ea_save_vehicle_record($edited, 'EA-PBX-002', $initial['version']);
    } catch (EaConflictException $exception) {
        $conflictDetected = true;
    }
    test_assert($conflictDetected, 'stale data version is rejected');

    $newInput = [
        'ref_id' => 'EA-TEST-001',
        'display_name_en' => '2023 Test Vehicle',
        'make' => 'Test',
        'model' => 'Vehicle',
        'year' => '2023',
        'fuel_type' => 'Petrol',
        'mileage_km' => '1000',
        'engine_cc' => '1300',
        'transmission' => 'Automatic',
        'drive' => '2WD',
        'steering' => 'Right hand drive',
        'auction_grade' => '4',
        'reference_price_usd' => '5000',
        'estimated_cif_mombasa_usd' => '7000',
        'price_as_of' => '2026-09-13',
    ];
    $newRecord = ea_build_vehicle_record([], $newInput, []);
    ea_save_vehicle_record($newRecord, null, $runtime['version']);
    $afterAdd = ea_load_vehicle_data(false, true);
    test_assert(count($afterAdd['data']['vehicles']) === 4, 'new vehicle is appended');
    test_assert(ea_find_vehicle($afterAdd['data'], 'EA-TEST-001') !== null, 'new vehicle is readable');

    $badPriceRejected = false;
    try {
        $badInput = $newInput;
        $badInput['ref_id'] = 'EA-TEST-002';
        $badInput['price_as_of'] = '';
        ea_build_vehicle_record([], $badInput, []);
    } catch (EaValidationException $exception) {
        $badPriceRejected = true;
    }
    test_assert($badPriceRejected, 'price without reference date is rejected');

    $removalRejected = false;
    try {
        ea_gallery_from_order(json_encode(['old:' . $expectedGallery[0]], JSON_THROW_ON_ERROR), $expectedGallery, []);
        $removalRejected = true;
    } catch (EaValidationException $exception) {
        $removalRejected = false;
    }
    test_assert($removalRejected, 'own-vehicle gallery removal is allowed');

    $foreignRejected = false;
    try {
        ea_gallery_from_order(json_encode(['old:images/EA-PBX-002/secret.jpg'], JSON_THROW_ON_ERROR), $expectedGallery, []);
    } catch (EaValidationException $exception) {
        $foreignRejected = true;
    }
    test_assert($foreignRejected, 'another vehicle image cannot be attached through gallery order');

    $runtime002Dir = ea_runtime_image_dir() . '/EA-PBX-002';
    ea_make_directory($runtime002Dir, 0700);
    $extraPath = 'images/EA-PBX-002/EA-PBX-002-extra.jpg';
    copy(dirname(__DIR__) . '/east-africa/images/EA-PBX-001/EA-PBX-001-01.jpg', ea_runtime_image_dir() . '/EA-PBX-002/EA-PBX-002-extra.jpg');
    $withPhoto = ea_build_vehicle_record($runtimeSecond['vehicle'], $runtimeSecond['vehicle'], [$extraPath]);
    ea_save_vehicle_record($withPhoto, 'EA-PBX-002', $afterAdd['version']);
    $afterPhoto = ea_load_vehicle_data(false, true);
    $emptyGallery = ea_gallery_from_order('[]', [$extraPath], []);
    test_assert($emptyGallery === [], 'gallery can be emptied for this vehicle');
    $detached = ea_detached_gallery_paths([$extraPath], $emptyGallery);
    test_assert($detached === [$extraPath], 'detached paths are limited to the current gallery');
    ea_assert_gallery_belongs_to_ref($detached, 'EA-PBX-002');
    $withoutPhoto = ea_build_vehicle_record($withPhoto, $withPhoto, $emptyGallery);
    ea_save_vehicle_record($withoutPhoto, 'EA-PBX-002', $afterPhoto['version']);
    ea_remove_created_images($detached);
    test_assert(!is_file(ea_runtime_image_dir() . '/EA-PBX-002/EA-PBX-002-extra.jpg'), 'runtime photo for this vehicle can be deleted');
    $afterDelete = ea_load_vehicle_data(false, true);
    $stillFirst = ea_find_vehicle($afterDelete['data'], 'EA-PBX-001');
    test_assert($stillFirst['vehicle']['gallery'] === $expectedGallery, 'EA-PBX-001 eight photos remain after deleting another vehicle photo');
    foreach ($expectedGallery as $staticPath) {
        test_assert(is_file(dirname(__DIR__) . '/east-africa/' . $staticPath), 'seed photo file remains on disk: ' . $staticPath);
    }

    ea_ensure_runtime_directories();
    $password = 'test-password-that-is-not-used-in-production';
    file_put_contents(ea_runtime_dir() . '/admin-password.hash', password_hash($password, PASSWORD_DEFAULT) . PHP_EOL, LOCK_EX);
    chmod(ea_runtime_dir() . '/admin-password.hash', 0600);
    test_assert(ea_admin_password_is_configured(), 'password hash file is recognized');
    test_assert(password_verify($password, ea_admin_password_hash()), 'configured password hash verifies');

    $newPassword = 'replacement-password-not-for-production';
    $wrongCurrent = false;
    try {
        ea_change_admin_password('incorrect-current-password', $newPassword, $newPassword);
    } catch (RuntimeException $exception) {
        $wrongCurrent = true;
    }
    test_assert($wrongCurrent, 'password change requires the current password');
    test_assert(password_verify($password, ea_admin_password_hash()), 'failed password change leaves the original hash');

    $mismatch = false;
    try {
        ea_change_admin_password($password, $newPassword, $newPassword . '-nope');
    } catch (RuntimeException $exception) {
        $mismatch = true;
    }
    test_assert($mismatch, 'password change requires matching confirmation');

    $tooShort = false;
    try {
        ea_change_admin_password($password, 'short-pass', 'short-pass');
    } catch (RuntimeException $exception) {
        $tooShort = true;
    }
    test_assert($tooShort, 'password change rejects short passwords');

    ea_change_admin_password($password, $newPassword, $newPassword);
    test_assert(password_verify($newPassword, ea_admin_password_hash()), 'password change stores a verifiable hash');
    test_assert(!password_verify($password, ea_admin_password_hash()), 'previous password no longer verifies');
    $hashFile = file_get_contents(ea_runtime_dir() . '/admin-password.hash');
    test_assert(is_string($hashFile) && !str_contains($hashFile, $newPassword) && !str_contains($hashFile, $password), 'hash file does not contain plaintext passwords');

    putenv('GLORIA_EA_ADMIN_PASSWORD_HASH=' . password_hash('environment-password-not-used', PASSWORD_DEFAULT));
    $envRejected = false;
    try {
        ea_change_admin_password($newPassword, 'another-password-xx', 'another-password-xx');
    } catch (RuntimeException $exception) {
        $envRejected = true;
    }
    test_assert($envRejected, 'environment-configured password cannot be changed from admin');
    putenv('GLORIA_EA_ADMIN_PASSWORD_HASH');
    unset($_SERVER['GLORIA_EA_ADMIN_PASSWORD_HASH'], $_ENV['GLORIA_EA_ADMIN_PASSWORD_HASH']);

    $csrfRejected = false;
    try {
        ea_verify_csrf('incorrect-token');
    } catch (RuntimeException $exception) {
        $csrfRejected = true;
    }
    test_assert($csrfRejected, 'invalid CSRF token is rejected');

    for ($attempt = 0; $attempt < EA_LOGIN_MAX_FAILURES; $attempt++) {
        ea_record_login_failure();
    }
    test_assert(ea_login_retry_after() > 0, 'login failures trigger a retry delay');
    ea_clear_login_failures();
    test_assert(ea_login_retry_after() === 0, 'login failure record can be cleared');

    echo "EA admin checks passed: seed fallback, validation, backup, atomic runtime save, photo removal for the current vehicle only, authentication change, CSRF, and rate limiting.\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    remove_tree($temp);
}
