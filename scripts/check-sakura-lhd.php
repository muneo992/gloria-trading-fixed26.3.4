<?php
declare(strict_types=1);

// Executed over SSH on Sakura; credentials and response bodies stay on host.
const WEB = '/home/gltr/www/gloria-test';
const PRIVATE_ROOT = '/home/gltr/private/west-africa-test';
const SITE = 'https://gltr.sakura.ne.jp/gloria-test';
const FEED = '/data/lhd-vehicle-feed.php';
const ADMIN = '/admin/lhd.php';
if (PHP_SAPI !== 'cli' || realpath(WEB) !== WEB || realpath(PRIVATE_ROOT) !== PRIVATE_ROOT) {
    exit(1);
}
require WEB . '/admin/lhd-vehicle-data.php';

function verify(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

function request(string $path, array|string|null $post = null, string $cookie = '', string $base = SITE): array
{
    $session = curl_init($base . $path);
    curl_setopt_array($session, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 18,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIE => $cookie,
    ]);
    if ($post !== null) {
        curl_setopt_array($session, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post]);
    }
    $response = curl_exec($session);
    verify(is_string($response), 'https_request_failed');
    $size = curl_getinfo($session, CURLINFO_HEADER_SIZE);
    return [
        'status' => curl_getinfo($session, CURLINFO_RESPONSE_CODE),
        'headers' => substr($response, 0, $size),
        'body' => substr($response, $size),
    ];
}

function login(string $password, string $base = SITE): array
{
    $response = request('/admin/index.php', http_build_query(['action' => 'login', 'password' => $password]), '', $base);
    verify(preg_match('/^Set-Cookie:\s*((?:ENC_)?PHPSESSID=[^;\r\n]+)/mi', $response['headers'], $match) === 1, 'session_cookie');
    return [$response, $match[1]];
}

function verifyRhdSave(string $parent): void
{
    $root = $parent . '/rhd-fixture';
    verify(mkdir($root, 0700), 'rhd_fixture_directory');
    $master = $root . '/vehicles.json';
    $records = [];
    for ($index = 1; $index <= 26; $index++) {
        $records[] = [
            'ref_id' => 'QA-' . $index, 'display_name_en' => 'Synthetic ' . $index,
            'make' => 'Example', 'model' => 'Fixture', 'year' => 2020,
        ];
    }
    verify(file_put_contents($master, json_encode(['vehicles' => $records], JSON_THROW_ON_ERROR)) !== false, 'rhd_fixture_master');
    $loopback = stream_socket_server('tcp://127.0.0.1:0');
    verify(is_resource($loopback), 'rhd_loopback_socket');
    $address = stream_socket_get_name($loopback, false);
    fclose($loopback);
    verify(is_string($address) && str_starts_with($address, '127.0.0.1:'), 'rhd_loopback_address');
    $base = 'http://' . $address;
    $password = bin2hex(random_bytes(18));
    $server = proc_open(
        [PHP_BINARY, '-d', 'session.save_path=' . $root, '-S', $address, '-t', WEB],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']],
        $pipes,
        WEB,
        [...getenv(), 'GLORIA_WA_DATA_DIR' => $root, 'GLORIA_ADMIN_PASSWORD' => $password]
    );
    try {
        verify(is_resource($server), 'rhd_fixture_server');
        $port = (int) substr($address, strlen('127.0.0.1:'));
        for ($i = 0; $i < 30; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if (is_resource($socket)) {
                fclose($socket);
                break;
            }
            usleep(100000);
        }
        verify($i < 30, 'rhd_fixture_server_ready');
        [$response, $cookie] = login($password, $base);
        verify(str_contains($response['body'], 'QA-26'), 'rhd_fixture_admin_list');
        $edit = '/admin/edit.php?ref=QA-1';
        $attachment = $root . '/synthetic.txt';
        verify(file_put_contents($attachment, "Synthetic quotation attachment\n") !== false, 'rhd_fixture_attachment');
        $status = request($edit, [
            'action' => 'save', 'upload_mode' => 'both', 'ref_id' => 'QA-1',
            'display_name_en' => 'Synthetic edited', 'year' => '2020',
            'make' => 'Example', 'model' => 'Fixture', 'reference_price_usd' => '4321',
            'gallery_json' => '[]', 'quote_spec_files_json' => '[]', 'quote_image_files_json' => '[]',
            'vehicle_certificate_files_json' => '[]',
            'quote_spec_files[0]' => new CURLFile($attachment, 'text/plain'),
        ], $cookie, $base);
        verify(in_array($status['status'], [302, 303], true) && str_contains($status['headers'], 'saved=1'), 'rhd_save_redirect');
        $saved = json_decode(file_get_contents($master), true, 512, JSON_THROW_ON_ERROR)['vehicles'];
        verify(count($saved) === 26 && $saved[0]['display_name_en'] === 'Synthetic edited', 'rhd_save_result');
        verify(count($saved[0]['quote_spec_files'] ?? []) === 1, 'rhd_attachment_saved');
        verify(count(glob($root . '/backups/*.json') ?: []) === 1, 'rhd_save_backup');
    } finally {
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $path = $file->getPathname();
            verify($file->isDir() && !$file->isLink() ? rmdir($path) : unlink($path), 'rhd_fixture_cleanup');
        }
        verify(rmdir($root), 'rhd_fixture_directory_cleanup');
    }
    echo 'rhd_isolated_26_save_redirect_and_backup_ok' . PHP_EOL;
}

function fields(string $path, string $cookie): array
{
    $page = request($path, null, $cookie);
    verify($page['status'] === 200, 'lhd_admin_authenticated');
    preg_match_all('/name="(csrf|version)" value="([a-f0-9]{64})"/', $page['body'], $matches, PREG_SET_ORDER);
    $inputs = array_column($matches, 2, 1);
    verify(isset($inputs['csrf'], $inputs['version']), 'lhd_admin_form');
    return $inputs;
}

function inFeed(string $ref): bool
{
    $result = request(FEED);
    verify($result['status'] === 200, 'lhd_public_feed');
    $records = json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR)['vehicles'];
    $allowed = ['ref', 'has_image', 'make', 'model', 'year', 'mileage', 'trade_term', 'destination_port', 'price', 'currency'];
    foreach ($records as $record) {
        verify(array_diff(array_keys($record), $allowed) === [], 'unexpected_public_fields');
    }
    return in_array($ref, array_column($records, 'ref'), true);
}

function underLock(string $directory, callable $operation): void
{
    $lockPath = $directory . '/master.lock';
    verify(!is_link($lockPath), 'lhd_lock_symlink');
    $lock = fopen($lockPath, 'c');
    verify($lock !== false && flock($lock, LOCK_EX), 'lhd_lock_failed');
    try {
        $operation(gt_lhd_read($directory));
    } finally {
        fclose($lock);
    }
}

$phase = 'initialization';
$marker = 'QA TEST ' . strtoupper(bin2hex(random_bytes(5)));
$backup = PRIVATE_ROOT . '/lhd-qa-' . strtolower(substr($marker, -10));
$directory = null;
$photo = $backup . '/upload.png';
$hadMaster = false;
$success = false;
umask(0077);
try {
    verify(gt_wa_private_dir() === PRIVATE_ROOT && gt_wa_site_root() === WEB, 'test_paths_only');
    verify(function_exists('curl_init'), 'php_curl_missing');
    $phase = 'public_baseline';
    verify(str_contains(request('/lhd.html')['body'], 'LHD Vehicle Sourcing'), 'lhd_page');
    verify(request(ADMIN)['status'] === 303, 'lhd_anonymous_admin');
    verify(request('/frontend/data/vehicles.json')['status'] === 403, 'rhd_raw_master_denied');
    $rhd = request('/data/vehicles.json');
    verify($rhd['status'] === 200, 'rhd_feed');
    $vehicles = json_decode($rhd['body'], true, 512, JSON_THROW_ON_ERROR)['vehicles'];
    verify(count($vehicles) > 0 && request('/catalog.html')['status'] === 200, 'rhd_catalog');
    verify(request('/vehicle-detail.html?ref=' . rawurlencode($vehicles[0]['ref_id']))['status'] === 200, 'rhd_detail');
    verify(request('/rfq.html')['status'] === 200, 'rhd_rfq');
    echo 'rhd_http_ok count=' . count($vehicles) . PHP_EOL;
    $phase = 'admin_login';
    $password = getConfiguredAdminPassword();
    verify($password !== '', 'test_admin_password_unavailable');
    [$login, $cookie] = login($password);
    unset($password);
    verify(str_contains($login['body'], 'LHD調達候補'), 'rhd_admin_authenticated');
    $new = ADMIN . '?ref=';
    $inputs = fields($new, $cookie);
    verify(request($new, 'action=draft', $cookie)['status'] === 403, 'csrf_missing');

    $phase = 'backup';
    verify(mkdir($backup, 0700), 'backup_create');
    $directory = gt_lhd_directory(true);
    underLock($directory, static function (array $records) use ($directory, $backup, &$hadMaster): void {
        $master = $directory . '/lhd-vehicles.json';
        $hadMaster = is_file($master);
        if ($hadMaster) {
            verify(copy($master, $backup . '/lhd-vehicles.json'), 'master_backup');
            verify(hash_file('sha256', $master) === hash_file('sha256', $backup . '/lhd-vehicles.json'), 'master_backup_bytes');
        }
        foreach ($records as $record) {
            if (($record['image'] ?? '') === '') {
                continue;
            }
            $path = gt_lhd_image_path($directory, $record);
            verify($path !== null && copy($path, $backup . '/' . basename($path)), 'image_backup');
            verify(hash_file('sha256', $path) === hash_file('sha256', $backup . '/' . basename($path)), 'image_backup_bytes');
        }
        verify(gt_lhd_read($backup) === $records, 'backup_readback');
    });
    echo 'lhd_backup_readback_ok' . PHP_EOL;

    $phase = 'draft_and_gd';
    // Valid 2×2 PNG; the web PHP handler must decode it and serve JPEG.
    verify(file_put_contents($photo, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII=')) !== false, 'fixture_photo');
    $today = gmdate('Y-m-d');
    $draft = request($new, $inputs + [
        'action' => 'draft', 'make' => $marker, 'model' => 'Synthetic verification only',
        'year' => '2020', 'confirmed_on' => $today, 'expires_on' => $today,
        'confirmed' => '1', 'price_mode' => 'quote', 'photo' => new CURLFile($photo, 'image/png'),
    ], $cookie);
    verify($draft['status'] === 303 && preg_match('/^Location:.*ref=(LHD-[A-F0-9]{12})/mi', $draft['headers'], $match) === 1, 'draft_save_or_gd');
    $ref = $match[1];
    $edit = ADMIN . '?ref=' . $ref;
    $image = FEED . '?image=' . $ref;
    $preview = request($edit . '&image=1', null, $cookie);
    verify($preview['status'] === 200 && stripos($preview['headers'], 'Content-Type: image/jpeg') !== false, 'gd_preview_jpeg');
    verify(!inFeed($ref) && request($image)['status'] === 404 && request($edit . '&image=1')['status'] === 303, 'draft_hidden');
    echo 'lhd_admin_preview_gd_and_draft_boundary_ok' . PHP_EOL;

    $phase = 'publish_stop_expiry';
    $publish = static fn() => request($edit, http_build_query(fields($edit, $cookie) + ['action' => 'publish', 'approve' => '1']), $cookie);
    verify($publish()['status'] === 303 && inFeed($ref) && request($image)['status'] === 200, 'publish');
    $stop = request($edit, http_build_query(fields($edit, $cookie) + ['action' => 'stop']), $cookie);
    verify($stop['status'] === 303 && !inFeed($ref) && request($image)['status'] === 404, 'stop_hidden');
    $phase = 'isolated_recovery';
    underLock($directory, static function (array $records) use ($directory, $backup, $marker, $ref, $today): void {
        $masterHash = hash_file('sha256', $directory . '/lhd-vehicles.json');
        foreach (glob(gt_lhd_backup_root($directory) . '/snapshot-*') ?: [] as $snapshot) {
            $saved = gt_lhd_read($snapshot);
            if (($saved[$ref]['make'] ?? null) !== $marker || !gt_lhd_eligible($saved[$ref], $today)) {
                continue;
            }
            $recovery = $backup . '/recovered';
            try {
                $restored = gt_lhd_restore_copy($snapshot, $recovery);
                verify($restored[$ref]['published'] === true && $restored[$ref]['expires_on'] === $today
                    && gt_lhd_image_path($recovery, $restored[$ref]) !== null, 'recovered_state_or_image');
                verify(($records[$ref]['published'] ?? true) === false
                    && hash_file('sha256', $directory . '/lhd-vehicles.json') === $masterHash, 'live_master_unchanged');
            } finally {
                if (is_dir($recovery)) {
                    gt_lhd_remove_snapshot($recovery);
                }
            }
            echo 'lhd_isolated_restore_json_image_state_expiry_ok' . PHP_EOL;
            return;
        }
        throw new RuntimeException('published_recovery_snapshot_missing');
    });
    $phase = 'publish_stop_expiry';
    verify($publish()['status'] === 303, 'republish');
    underLock($directory, static function (array $records) use ($directory, $ref, $marker): void {
        verify(($records[$ref]['make'] ?? null) === $marker, 'fixture_changed');
        $records[$ref]['confirmed_on'] = $records[$ref]['expires_on'] = gmdate('Y-m-d', time() - 86400);
        gt_lhd_write($directory, $records);
    });
    verify(!inFeed($ref) && request($image)['status'] === 404, 'expiry_hidden');
    echo 'lhd_publish_stop_expiry_ok' . PHP_EOL;
    verify(hash('sha256', request('/data/vehicles.json')['body']) === hash('sha256', $rhd['body']), 'rhd_feed_changed');
    $phase = 'rhd_isolated_save';
    verifyRhdSave($backup);
    $success = true;
} catch (Throwable $error) {
    // Only our status labels may appear in Actions logs.
    $label = $error->getMessage();
    echo 'verification_failed=' . $phase . (preg_match('/^[a-z_]+$/D', $label) ? ':' . $label : '') . PHP_EOL;
} finally {
    try {
        if ($directory !== null) {
            underLock($directory, static function (array $records) use ($directory, $marker, $hadMaster): void {
                $images = [];
                foreach ($records as $id => $record) {
                    if (($record['make'] ?? null) === $marker) {
                        $images[] = gt_lhd_image_path($directory, $record);
                        unset($records[$id]);
                    }
                }
                if ($images !== []) {
                    if (!$hadMaster && $records === []) {
                        verify(unlink($directory . '/lhd-vehicles.json'), 'fixture_master_cleanup');
                    } else {
                        gt_lhd_write($directory, $records);
                    }
                    foreach (array_filter($images) as $path) {
                        verify(unlink($path), 'fixture_photo_cleanup');
                    }
                }
                foreach (glob($directory . '/backups/snapshot-*') ?: [] as $snapshot) {
                    foreach (gt_lhd_read($snapshot) as $saved) {
                        if (($saved['make'] ?? null) === $marker) {
                            gt_lhd_remove_snapshot($snapshot);
                            break;
                        }
                    }
                }
            });
            echo 'lhd_fixture_cleanup_ok' . PHP_EOL;
        }
        if (is_dir($backup)) {
            foreach (glob($backup . '/*') ?: [] as $file) {
                verify(is_file($file) && unlink($file), 'backup_fixture_cleanup');
            }
            verify(rmdir($backup), 'backup_directory_cleanup');
        }
    } catch (Throwable $error) {
        echo 'verification_cleanup_failed' . PHP_EOL;
        $success = false;
    }
}
exit($success ? 0 : 1);
