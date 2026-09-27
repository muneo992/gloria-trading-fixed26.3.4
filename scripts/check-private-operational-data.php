<?php
declare(strict_types=1);

/**
 * Local verification for private operational storage.
 * Uses synthetic records only. Do not print customer, price, bank, or password values.
 */

$root = dirname(__DIR__);
$private = sys_get_temp_dir() . '/gloria-wa-private-' . getmypid();
$legacy = sys_get_temp_dir() . '/gloria-wa-legacy-' . getmypid();
$deploy = sys_get_temp_dir() . '/gloria-wa-deploy-' . getmypid();
$empty = sys_get_temp_dir() . '/gloria-wa-empty-' . getmypid();
$cookie = sys_get_temp_dir() . '/gloria-wa-cookie-' . getmypid() . '.txt';
$upload = sys_get_temp_dir() . '/gloria-wa-upload-' . getmypid() . '.txt';
$router = sys_get_temp_dir() . '/gloria-wa-router-' . getmypid() . '.php';
$port = 8891;
$server = null;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function remove_tree(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        remove_tree($path . '/' . $entry);
    }
    rmdir($path);
}

try {
    foreach ([$private, $legacy, $deploy, $empty] as $directory) {
        remove_tree($directory);
        check(mkdir($directory, 0700, true) || is_dir($directory), 'Temporary directory could not be created.');
    }
    check(mkdir($legacy . '/frontend/data/backup', 0700, true), 'Legacy backup directory could not be created.');
    check(mkdir($private . '/archive/pre-cutover', 0700, true), 'Pre-cutover archive could not be created.');
    check(mkdir($deploy . '/frontend/data/backup', 0700, true), 'Deploy backup directory could not be created.');

    $fixture = [
        'vehicles' => [[
            'ref_id' => 'SYN-001',
            'display_name_en' => 'Synthetic Vehicle',
            'year' => 2020,
            'make' => 'Example',
            'model' => 'Fixture',
            'grade' => '',
            'body_type' => 'Van',
            'fuel_type' => 'Diesel',
            'transmission' => 'Manual',
            'mileage_km' => 1000,
            'engine_cc' => 2000,
            'reference_price_usd' => 4321,
            'basis_from' => 'SYNTHETIC-DATE',
            'basis_to' => 'SYNTHETIC-VENUE',
            'best_for_resale_in' => 'Ghana',
            'typical_buyer_use' => 'Synthetic use',
            'similar_units' => '',
            'bulk_repeat_order' => '',
            'gallery' => ['images/vehicles/synthetic.jpg'],
            'quote_spec_files' => [],
            'quote_image_files' => [],
            'vehicle_certificate_files' => [],
            'quote_spec_data' => [
                'auction_price_jpy' => 0,
                'customer_name' => '',
                'target_price_usd' => 4321,
            ],
        ]],
    ];
    $encoded = json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    check(is_string($encoded), 'Synthetic fixture could not be encoded.');
    $legacyMaster = $legacy . '/frontend/data/vehicles.json';
    file_put_contents($legacyMaster, $encoded);
    file_put_contents($legacy . '/frontend/data/backup/marker.json', "{\"marker\":true}\n");
    $originalHash = hash_file('sha256', $legacyMaster);

    $privateMaster = $private . '/vehicles.json';
    check(copy($legacyMaster, $privateMaster), 'Private master copy failed.');
    check(hash_file('sha256', $privateMaster) === $originalHash, 'Private master copy does not match the legacy master.');
    check(copy($legacyMaster, $private . '/archive/pre-cutover/vehicles.json'), 'Pre-cutover archive copy failed.');
    check(hash_file('sha256', $legacyMaster) === $originalHash, 'Legacy master changed during the copy.');
    file_put_contents($private . '/password.txt', "synthetic-admin-password\n");
    chmod($private . '/password.txt', 0600);
    file_put_contents($private . '/invoice-bank.php', "<?php\nreturn ['bank_name' => 'SYNTHETIC-BANK-WA', 'swift_code' => '', 'branch_name' => '', 'branch_phone' => '', 'account_name' => '', 'account_number' => '0000000000', 'branch_address' => ''];\n");
    chmod($private . '/invoice-bank.php', 0600);
    file_put_contents($private . '/DO_NOT_TOUCH', 'sentinel');
    file_put_contents($upload, "synthetic attachment\n");

    file_put_contents($deploy . '/frontend/data/vehicles.json', "{\"vehicles\":[{\"ref_id\":\"LEGACY-MASTER\"}]}\n");
    file_put_contents($deploy . '/frontend/data/backup/marker.json', "{\"marker\":true}\n");
    $deployMasterHash = hash_file('sha256', $deploy . '/frontend/data/vehicles.json');
    $deployMarkerHash = hash_file('sha256', $deploy . '/frontend/data/backup/marker.json');

    putenv('GLORIA_WA_DATA_DIR=' . $private);
    $_ENV['GLORIA_WA_DATA_DIR'] = $private;
    $_SERVER['GLORIA_WA_DATA_DIR'] = $private;
    require_once $root . '/admin/bootstrap.php';
    require_once $root . '/admin/vehicle-data.php';
    require_once $root . '/admin/private-files.php';
    require_once $root . '/frontend/data/public-vehicle-data.php';

    check(gt_wa_private_dir() === $private, 'Environment directory was not selected.');
    check(loadVehicles()['vehicles'][0]['ref_id'] === 'SYN-001', 'Admin load did not read the private master.');
    check(is_file($root . '/frontend/data/vehicles.json'), 'Repository public snapshot is missing.');

    check(gt_wa_mkdir_private($private . '/backups'), 'Private backup directory could not be created.');
    for ($i = 1; $i <= 31; $i++) {
        file_put_contents(sprintf('%s/backups/vehicles-20200101-%06d.json', $private, $i), "{\"vehicles\":[]}\n");
    }
    gt_wa_archive_old_vehicle_backups($private . '/backups');
    $remainingBackups = glob($private . '/backups/vehicles-20200101-*.json') ?: [];
    $archivedBackups = glob($private . '/archive/backups/vehicles-20200101-*.json') ?: [];
    check(count($remainingBackups) === 30, 'Private backups must keep the newest 30 files.');
    check(count($archivedBackups) === 1, 'Older backups must move to the archive.');
    check(is_file($private . '/archive/backups/vehicles-20200101-000001.json'), 'Archived backup was deleted.');

    $routerSource = <<<'PHP'
<?php
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (preg_match('#^/data/vehicles\.json$#', $uri) === 1) {
    require '%ROOT%/frontend/data/vehicle-feed.php';
    return true;
}
if (preg_match('#^/(?:vehicles\.json|frontend/data/vehicles\.json|data/backup(?:/.*)?|frontend/data/backup(?:/.*)?|uploads(?:/.*)?|frontend/uploads(?:/.*)?)#', $uri) === 1) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Forbidden\n";
    return true;
}
if (preg_match('#/(?:private|home/gltr)(?:/|$)#', $uri) === 1) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Not Found\n";
    return true;
}
return false;
PHP;
    file_put_contents($router, str_replace('%ROOT%', $root, $routerSource));

    $descriptors = [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/gloria-wa-server-' . getmypid() . '.log', 'w'], 2 => ['file', sys_get_temp_dir() . '/gloria-wa-server-' . getmypid() . '.log', 'a']];
    $env = getenv();
    if (!is_array($env)) {
        $env = [];
    }
    $env['GLORIA_WA_DATA_DIR'] = $private;
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root, $router], $descriptors, $pipes, $root, $env);
    check(is_resource($server), 'Local admin server could not start.');
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
        if (is_resource($socket)) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    check($ready, 'Local admin server did not accept connections.');

    $base = 'http://127.0.0.1:' . $port;
    $request = static function (string $url, array $options = []) use ($cookie): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, $options + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $cookie,
            CURLOPT_COOKIEFILE => $cookie,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        check(is_string($raw), 'HTTP request failed.');
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return ['status' => $status, 'headers' => substr($raw, 0, $headerSize), 'body' => substr($raw, $headerSize)];
    };

    $login = $request($base . '/admin/index.php', [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['action' => 'login', 'password' => 'synthetic-admin-password'],
    ]);
    check($login['status'] === 200 && str_contains($login['body'], 'SYN-001') && str_contains($login['body'], '管理画面'), 'Admin list did not open after login.');
    check(!str_contains($login['body'], $private), 'Admin list revealed the private directory.');

    $edit = $request($base . '/admin/edit.php?ref=SYN-001');
    check($edit['status'] === 200 && str_contains($edit['body'], 'name="reference_price_usd"'), 'Admin edit form did not open.');
    check(!str_contains($edit['body'], $private), 'Admin edit form revealed the private directory.');

    $save = $request($base . '/admin/edit.php?ref=SYN-001', [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'action' => 'save',
            'upload_mode' => 'both',
            'ref_id' => 'SYN-001',
            'display_name_en' => 'Synthetic Vehicle',
            'year' => '2020',
            'make' => 'Example',
            'model' => 'Fixture',
            'body_type' => 'Van',
            'fuel_type' => 'Diesel',
            'transmission' => 'Manual',
            'mileage_km' => '1000',
            'engine_cc' => '2000',
            'reference_price_usd' => '4321',
            'basis_from' => 'SYNTHETIC-DATE',
            'basis_to' => 'SYNTHETIC-VENUE',
            'gallery_json' => '["images/vehicles/synthetic.jpg"]',
            'quote_spec_files_json' => '[]',
            'quote_image_files_json' => '[]',
            'vehicle_certificate_files_json' => '[]',
            'quote_auction_price_jpy' => '111222',
            'quote_profit_usd' => '444',
            'quote_purchase_price_usd' => '333',
            'quote_target_price_usd' => '9999',
            'quote_customer_name' => 'Synthetic Customer Wa',
            'quote_chassis_no' => 'SYNCHASSIS001',
            'quote_spec_files[0]' => new CURLFile($upload, 'text/plain', 'synthetic.txt'),
        ],
    ]);
    check(in_array($save['status'], [302, 303], true), 'Admin save did not redirect.');
    $saved = loadVehicles();
    $savedVehicle = $saved['vehicles'][0];
    check(($savedVehicle['reference_price_usd'] ?? null) === 4321, 'Saving auction, cost, or target price overwrote the public reference price.');
    check(($savedVehicle['quote_spec_data']['auction_price_jpy'] ?? null) === 111222, 'Admin save did not keep the private auction field.');
    check(($savedVehicle['quote_spec_data']['customer_name'] ?? null) === 'Synthetic Customer Wa', 'Admin save did not keep the private customer field.');
    check(count($savedVehicle['quote_spec_files'] ?? []) === 1, 'Quote attachment was not recorded.');
    $storedAttachment = (string)$savedVehicle['quote_spec_files'][0];
    check(str_starts_with($storedAttachment, 'uploads/quotes/syn-001/specs/'), 'Quote attachment was not stored under the private quote directory.');
    check(is_file($private . '/' . $storedAttachment), 'Quote attachment is missing from the private directory.');
    check(!is_file($root . '/frontend/' . $storedAttachment), 'Quote attachment was written into the public tree.');
    $newBackups = glob($private . '/backups/vehicles-*.json') ?: [];
    check($newBackups !== [], 'Saving did not create a private backup.');
    check(glob($root . '/frontend/data/backup/vehicles-*.json') === [] || glob($root . '/frontend/data/backup/vehicles-*.json') === false, 'Saving created a backup inside the repository data directory.');
    $legacyBackupNames = array_map('basename', glob($legacy . '/frontend/data/backup/*') ?: []);
    check($legacyBackupNames === ['marker.json'], 'A new backup was written beside the legacy master.');

    $create = $request($base . '/admin/edit.php', [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'action' => 'save',
            'upload_mode' => 'both',
            'ref_id' => 'SYN-NEW',
            'display_name_en' => 'Synthetic Added',
            'year' => '2021',
            'make' => 'Example',
            'model' => 'Added',
            'reference_price_usd' => '1000',
            'gallery_json' => '[]',
            'quote_spec_files_json' => '[]',
            'quote_image_files_json' => '[]',
            'vehicle_certificate_files_json' => '[]',
        ],
    ]);
    check(in_array($create['status'], [302, 303], true), 'Admin create did not redirect.');
    check(count(loadVehicles()['vehicles']) === 2, 'Admin create did not add a vehicle.');

    $list = $request($base . '/admin/index.php');
    check(str_contains($list['body'], 'SYN-001') && str_contains($list['body'], 'SYN-NEW'), 'Admin list does not show the saved vehicles.');

    $feed = $request($base . '/data/vehicles.json');
    check($feed['status'] === 200, 'Public feed did not return 200.');
    $feedData = json_decode($feed['body'], true, 512, JSON_THROW_ON_ERROR);
    check(count($feedData['vehicles']) === 2, 'Public feed count does not match the private master.');
    $privateKeys = ['quote_spec_data', 'export_document_data', 'vehicle_certificate_data', 'quote_spec_files', 'quote_image_files', 'vehicle_certificate_files', 'basis_from', 'basis_to', 'auction_price_jpy', 'target_price_usd', 'customer_name', 'chassis_no'];
    foreach ($feedData['vehicles'] as $record) {
        foreach ($privateKeys as $key) {
            check(!array_key_exists($key, $record), 'Public feed contains a private field.');
        }
    }
    check(!str_contains($feed['body'], '111222'), 'Public feed contains a private auction value.');
    check(!str_contains($feed['body'], 'Synthetic Customer Wa'), 'Public feed contains a private customer value.');
    check(!str_contains($feed['body'], 'SYNTHETIC-BANK-WA'), 'Public feed contains bank data.');
    check(!str_contains($feed['body'], 'SYNCHASSIS001'), 'Public feed contains a chassis number.');
    check(str_contains($feed['body'], '4321') && str_contains($feed['body'], 'images/vehicles/synthetic.jpg'), 'Public feed is missing the public price or gallery path.');

    $preview = $request($base . '/admin/quote-preview.php?ref=SYN-001');
    check($preview['status'] === 200 && str_contains($preview['body'], 'Synthetic Customer Wa'), 'Quote preview did not read the private master.');
    $anonymousPreview = $request($base . '/admin/quote-preview.php?ref=SYN-001', [CURLOPT_COOKIEFILE => '', CURLOPT_COOKIEJAR => '']);
    check(in_array($anonymousPreview['status'], [302, 303], true), 'Quote preview was available without login.');
    check(!str_contains($anonymousPreview['body'], 'Synthetic Customer Wa'), 'Anonymous quote preview contained private data.');

    foreach (['quotation', 'proforma', 'commercial_invoice', 'packing_list'] as $type) {
        $pdf = $request($base . '/admin/quote-pdf.php?type=' . $type . '&ref=SYN-001');
        check($pdf['status'] === 200 && str_starts_with($pdf['body'], '%PDF'), 'Authenticated document PDF was not created: ' . $type);
        check(!str_contains($pdf['body'], $private), 'Document PDF revealed the private directory.');
    }
    $proforma = $request($base . '/admin/quote-pdf.php?type=proforma&ref=SYN-001');
    check(str_contains($proforma['body'], 'SYNTHETIC-BANK-WA'), 'Proforma PDF did not use the private bank file.');
    $anonymousPdf = $request($base . '/admin/quote-pdf.php?type=quotation&ref=SYN-001', [CURLOPT_COOKIEFILE => '', CURLOPT_COOKIEJAR => '']);
    check($anonymousPdf['status'] === 403 && !str_starts_with($anonymousPdf['body'], '%PDF'), 'Document PDF was available without login.');

    $attachment = $request($base . '/admin/private-file.php?path=' . rawurlencode($storedAttachment));
    check($attachment['status'] === 200 && $attachment['body'] === "synthetic attachment\n", 'Logged-in attachment download failed.');
    $anonymousFile = $request($base . '/admin/private-file.php?path=' . rawurlencode($storedAttachment), [CURLOPT_COOKIEFILE => '', CURLOPT_COOKIEJAR => '']);
    check($anonymousFile['status'] === 403 && $anonymousFile['body'] !== "synthetic attachment\n", 'Attachment was available without login.');
    $escaped = $request($base . '/admin/private-file.php?path=' . rawurlencode('uploads/quotes/syn-001/specs/../../password.txt'));
    check($escaped['status'] === 404, 'Private file resolver allowed a path outside the upload directory.');
    check($request($base . '/uploads/quotes/syn-001/specs/synthetic.txt')['status'] === 403, 'Public upload URL was not rejected.');
    check($request($base . '/private/west-africa-test/vehicles.json')['status'] === 404, 'Private directory URL was not rejected.');
    check($request($base . '/vehicles.json')['status'] === 403, 'Direct master URL was not rejected.');
    check($request($base . '/frontend/data/vehicles.json')['status'] === 403, 'Direct frontend master URL was not rejected.');

    $passwordPage = $request($base . '/admin/change-password.php');
    check(preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $passwordPage['body'], $csrf) === 1, 'Password form did not render.');
    $changed = $request($base . '/admin/change-password.php', [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'csrf_token' => $csrf[1],
            'new_password' => 'synthetic-admin-pass-2',
            'confirm_password' => 'synthetic-admin-pass-2',
        ],
    ]);
    check($changed['status'] === 200 && str_contains($changed['body'], '管理画面パスワードを更新しました'), 'Password change did not save.');
    check(is_file($private . '/password.txt') && !is_file($root . '/admin/password.txt'), 'Password file was not kept in the private directory.');
    check((fileperms($private . '/password.txt') & 0777) === 0600, 'Private password file mode is not 0600.');
    check(!str_contains($changed['body'], $private), 'Password page revealed the private directory.');

    $request($base . '/admin/index.php?logout=1');
    $afterLogout = $request($base . '/admin/private-file.php?path=' . rawurlencode($storedAttachment));
    check($afterLogout['status'] === 403, 'Attachment remained available after logout.');

    $beforeRestore = file_get_contents($privateMaster);
    check(is_string($beforeRestore), 'Current master could not be read before restore.');
    $restoreArchive = $private . '/archive/before-restore.json';
    check(file_put_contents($restoreArchive, $beforeRestore) !== false, 'Restore archive could not be written.');
    $backupCandidates = array_merge(
        glob($private . '/backups/vehicles-*.json') ?: [],
        glob($private . '/archive/backups/vehicles-*.json') ?: []
    );
    $restored = false;
    foreach ($backupCandidates as $candidate) {
        if (hash_file('sha256', $candidate) === $originalHash) {
            check(copy($candidate, $privateMaster), 'Restore copy failed.');
            $restored = true;
            break;
        }
    }
    check($restored, 'No restorable backup matched the pre-change master.');
    check(hash_file('sha256', $privateMaster) === $originalHash, 'Restored master does not match the pre-change copy.');
    check(is_file($restoreArchive), 'The pre-restore master was not kept in the archive.');
    check(hash_file('sha256', $legacyMaster) === $originalHash, 'Legacy master was removed or changed.');
    $restoredFeed = $request($base . '/data/vehicles.json');
    $restoredData = json_decode($restoredFeed['body'], true, 512, JSON_THROW_ON_ERROR);
    check(count($restoredData['vehicles']) === 1, 'Public feed count did not return to the pre-change count.');
    check(!str_contains($restoredFeed['body'], 'Synthetic Customer Wa'), 'Restored public feed still contains the synthetic customer.');
    check(!str_contains($restoredFeed['body'], 'SYN-NEW'), 'Restored public feed still contains the synthetic vehicle.');

    proc_terminate($server);
    proc_close($server);
    $server = null;

    $cleanEnv = getenv();
    if (!is_array($cleanEnv)) {
        $cleanEnv = ['PATH' => (string)getenv('PATH')];
    }
    unset($cleanEnv['GLORIA_WA_DATA_DIR']);
    $refused = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-r', 'require "admin/vehicle-data.php"; $path = "frontend/data/vehicles.json"; $before = hash_file("sha256", $path); $result = saveVehicles(["vehicles" => [["ref_id" => "SHOULD-NOT-WRITE", "display_name_en" => "No", "year" => 2020]]]); $after = hash_file("sha256", $path); if ($result !== false || $before !== $after) { fwrite(STDERR, "unconfigured save wrote operational data\n"); exit(1); } echo "unconfigured save refused\n";'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $refusedPipes, $root, $cleanEnv);
    check(is_resource($refused), 'Unconfigured save check could not start.');
    $refusedOut = stream_get_contents($refusedPipes[1]);
    $refusedErr = stream_get_contents($refusedPipes[2]);
    fclose($refusedPipes[1]);
    fclose($refusedPipes[2]);
    $refusedCode = proc_close($refused);
    check($refusedCode === 0 && str_contains((string)$refusedOut, 'unconfigured save refused'), 'Unconfigured local save was not refused. ' . trim((string)$refusedErr));

    $snapshotFeed = proc_open([PHP_BINARY, '-d', 'display_errors=0', $root . '/frontend/data/vehicle-feed.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $feedPipes, $root, $cleanEnv);
    check(is_resource($snapshotFeed), 'Snapshot feed check could not start.');
    $snapshotBody = stream_get_contents($feedPipes[1]);
    fclose($feedPipes[1]);
    fclose($feedPipes[2]);
    check(proc_close($snapshotFeed) === 0, 'Snapshot feed failed.');
    $snapshotData = json_decode((string)$snapshotBody, true, 512, JSON_THROW_ON_ERROR);
    check(count($snapshotData['vehicles']) === 19, 'Local preview feed count changed.');
    check(!str_contains((string)$snapshotBody, 'quote_spec_data') && !str_contains((string)$snapshotBody, 'auction_price_jpy'), 'Local preview feed contains private fields.');

    $missingEnv = $cleanEnv;
    $missingEnv['GLORIA_WA_DATA_DIR'] = $empty;
    $missingFeed = proc_open([PHP_BINARY, '-d', 'display_errors=0', $root . '/frontend/data/vehicle-feed.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $missingPipes, $root, $missingEnv);
    check(is_resource($missingFeed), 'Missing-master feed check could not start.');
    $missingBody = stream_get_contents($missingPipes[1]);
    fclose($missingPipes[1]);
    fclose($missingPipes[2]);
    proc_close($missingFeed);
    $missingData = json_decode((string)$missingBody, true, 512, JSON_THROW_ON_ERROR);
    check(($missingData['vehicles'] ?? null) === [], 'An unreadable private master fell back to the public snapshot.');
    check(!str_contains((string)$missingBody, 'SYN-001') && !str_contains((string)$missingBody, 'REF-001'), 'Unavailable feed exposed catalog records.');

    $rsync = trim((string)shell_exec('command -v rsync'));
    check($rsync !== '', 'rsync is not installed.');
    $workflow = file_get_contents($root . '/.github/workflows/deploy-test.yml');
    check(is_string($workflow), 'Test workflow could not be read.');
    preg_match_all("/--exclude='([^']+)'/", $workflow, $excludeMatch);
    $excludes = $excludeMatch[1] ?? [];
    check(in_array('/frontend/data/vehicles.json', $excludes, true), 'Test deploy no longer excludes the vehicle JSON.');
    check(in_array('/frontend/data/backup/', $excludes, true), 'Test deploy no longer excludes backups.');
    check(!str_contains($workflow, '--delete'), 'Test deploy contains --delete.');
    $sentinelHash = hash_file('sha256', $private . '/DO_NOT_TOUCH');
    $privateHash = hash_file('sha256', $privateMaster);
    $rsyncCommand = array_merge([$rsync, '-a'], array_map(static fn(string $exclude): string => '--exclude=' . $exclude, $excludes), [$root . '/', $deploy . '/']);
    for ($pass = 1; $pass <= 2; $pass++) {
        $rsyncProcess = proc_open($rsyncCommand, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rsyncPipes);
        check(is_resource($rsyncProcess), 'Deploy simulation could not start.');
        fclose($rsyncPipes[1]);
        $rsyncErr = stream_get_contents($rsyncPipes[2]);
        fclose($rsyncPipes[2]);
        check(proc_close($rsyncProcess) === 0, 'Deploy simulation failed. ' . trim((string)$rsyncErr));
        check(hash_file('sha256', $privateMaster) === $privateHash, 'Deploy changed the private master.');
        check(hash_file('sha256', $private . '/DO_NOT_TOUCH') === $sentinelHash, 'Deploy changed a private sentinel file.');
        check(hash_file('sha256', $deploy . '/frontend/data/vehicles.json') === $deployMasterHash, 'Deploy overwrote the server vehicle JSON.');
        check(hash_file('sha256', $deploy . '/frontend/data/backup/marker.json') === $deployMarkerHash, 'Deploy overwrote a server backup.');
        check(is_file($deploy . '/admin/private-file.php'), 'Deploy did not copy the new admin code.');
        check(!is_dir($deploy . '/private'), 'Deploy created a private directory inside the web root.');
    }

    echo "Private operational data checks passed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    foreach ([$private, $legacy, $deploy, $empty, $cookie, $upload, $router, sys_get_temp_dir() . '/gloria-wa-server-' . getmypid() . '.log'] as $path) {
        if (is_string($path) && file_exists($path)) {
            remove_tree($path);
        }
    }
}
