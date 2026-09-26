<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/frontend/data/public-vehicle-data.php';
require_once dirname(__DIR__) . '/admin/vehicle-data.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Synthetic fixture only. No operational vehicle, customer, or cost data is read.
$synthetic = [
    'vehicles' => [[
        'ref_id' => 'TEST-001',
        'display_name_en' => 'Synthetic Vehicle',
        'year' => 2020,
        'make' => 'Example',
        'model' => 'Fixture',
        'reference_price_usd' => 12345,
        'gallery' => ['images/vehicles/synthetic.jpg', ['quote_spec_data' => ['profit_usd' => 111]]],
        'quote_spec_data' => [
            'auction_price_jpy' => 111,
            'profit_usd' => 222,
            'customer_name' => 'Synthetic Customer',
        ],
        'export_document_data' => ['consignee_name' => 'Synthetic Consignee'],
        'vehicle_certificate_data' => ['owner_name' => 'Synthetic Owner'],
        'quote_spec_files' => ['uploads/quotes/synthetic.pdf'],
    ]],
];

$public = gt_public_vehicle_data($synthetic);
check(count($public['vehicles']) === 1, 'The public projection must preserve record count.');
$record = $public['vehicles'][0];
check(($record['ref_id'] ?? null) === 'TEST-001', 'The public projection must preserve allowlisted fields.');
check(($record['gallery'] ?? null) === ['images/vehicles/synthetic.jpg'], 'The public projection must reject nested gallery data.');

$privateSynthetic = $synthetic;
$privateSynthetic['vehicles'][0]['gallery'] = ['images/vehicles/synthetic.jpg'];
$private = normalizeVehicleData($privateSynthetic);
check(
    ($private['vehicles'][0]['quote_spec_data']['profit_usd'] ?? null) === 222,
    'The administrative master must preserve private quotation data.'
);

$privateFields = [
    'quote_spec_data',
    'export_document_data',
    'vehicle_certificate_data',
    'quote_spec_files',
    'quote_image_files',
    'vehicle_certificate_files',
];
foreach ($privateFields as $field) {
    check(!array_key_exists($field, $record), "Private field escaped public projection: {$field}");
}

$rootHtaccess = file_get_contents(dirname(__DIR__) . '/.htaccess');
$dataHtaccess = file_get_contents(dirname(__DIR__) . '/frontend/data/.htaccess');
$netlifyConfig = file_get_contents(dirname(__DIR__) . '/netlify.toml');
$productionWorkflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/deploy-production.yml');
$testWorkflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/deploy-test.yml');
$adminIndex = file_get_contents(dirname(__DIR__) . '/admin/index.php');
$adminHealth = file_get_contents(dirname(__DIR__) . '/admin/health.php');
check(is_string($rootHtaccess) && str_contains($rootHtaccess, '^data/vehicles\\.json$ frontend/data/vehicle-feed.php [END,NC]'), 'Canonical public feed rewrite must stop per-directory rewriting.');
check(str_contains($rootHtaccess, '^vehicles\\.json$ - [F,END,NC]'), 'Legacy root master JSON denial is missing.');
check(str_contains($rootHtaccess, '^frontend/data/vehicles\\.json$ - [F,END,NC]'), 'Direct master JSON denial is missing.');
check(str_contains($rootHtaccess, 'data/backup(?:/|$) - [F,END,NC]'), 'Backup denial is missing.');
check(
    strpos($rootHtaccess, '^data/vehicles\\.json$ frontend/data/vehicle-feed.php [END,NC]')
        < strpos($rootHtaccess, '^data/(.*)$ frontend/data/$1 [L]'),
    'Canonical public feed rewrite must precede the generic data rewrite.'
);
check(
    is_string($dataHtaccess)
    && str_contains($dataHtaccess, 'RewriteCond %{THE_REQUEST} \\s/+[^?\\s]*frontend/data/vehicles\\.json(?:[?\\s]) [NC]')
    && str_contains($dataHtaccess, '^vehicles\\.json$ - [F,END,NC]'),
    'Direct frontend master requests must be denied using the original request URI.'
);
check(
    str_contains($dataHtaccess, '^vehicles\\.json$ vehicle-feed.php [END,NC]'),
    'Symlinked canonical vehicle requests must fall back to the allowlisted feed.'
);
check(
    strpos($dataHtaccess, 'RewriteCond %{THE_REQUEST}')
        < strpos($dataHtaccess, '^vehicles\\.json$ vehicle-feed.php [END,NC]'),
    'The direct-request denial must precede the canonical feed fallback.'
);
$directMasterRequestPattern = '#\s/+[^?\s]*frontend/data/vehicles\.json(?:[?\s])#i';
check(
    preg_match($directMasterRequestPattern, 'GET /gloria-test/frontend/data/vehicles.json?probe=1 HTTP/1.1') === 1,
    'The original-request guard must detect a direct frontend master request.'
);
check(
    preg_match($directMasterRequestPattern, 'GET /gloria-test/data/vehicles.json?probe=1 HTTP/1.1') === 0,
    'The original-request guard must allow the canonical symlinked feed request.'
);
check(str_contains($dataHtaccess, '^backup(?:/|$) - [F,END,NC]'), 'Data-directory backup denial is missing.');
check(
    is_string($netlifyConfig)
    && str_contains($netlifyConfig, 'command = "node scripts/build-netlify-public-data.js"'),
    'Netlify must sanitize the administrative master before publishing.'
);
check(
    is_string($productionWorkflow)
    && str_contains($productionWorkflow, "--exclude='node_modules/' \\\n"),
    'Production rsync exclusions must remain in one continued command.'
);
check(
    is_string($testWorkflow)
    && str_contains($testWorkflow, "--exclude='/frontend/data/backup/' \\\n"),
    'Test rsync exclusions must remain in one continued command.'
);
check(
    str_contains($testWorkflow, "php '\$EXPECTED_SAKURA_TEST_PATH/admin/health.php'"),
    'Test deployment must use CLI-only health diagnostics.'
);
check(
    str_contains($testWorkflow, 'expect_status 200 "$BASE_URL/data/vehicles.json"')
    && str_contains($testWorkflow, 'Public vehicle feed contains a private field.')
    && str_contains($testWorkflow, 'expect_status 403 "$BASE_URL/vehicles.json"')
    && str_contains($testWorkflow, 'expect_status 403 "$BASE_URL/frontend/data/vehicles.json"')
    && str_contains($testWorkflow, 'expect_status 403 "$BASE_URL/data/backup/probe.json"')
    && str_contains($testWorkflow, 'expect_status 403 "$BASE_URL/frontend/data/backup/probe.json"')
    && str_contains($testWorkflow, 'expect_status 404 "$BASE_URL/admin/health.php"')
    && str_contains($testWorkflow, 'expect_status 404 "$BASE_URL/admin/index.php?diag=1"')
    && str_contains($testWorkflow, 'expect_status 200 "$BASE_URL/admin/"')
    && str_contains($testWorkflow, 'grep -q "管理画面"')
    && str_contains($testWorkflow, '--output /dev/null --max-time 20 "$BASE_URL/catalog.html"'),
    'Test deployment security smoke coverage is incomplete.'
);
check(is_string($adminIndex) && !str_contains($adminIndex, "file_get_contents(__DIR__ . '/.htaccess')"), 'Web diagnostics still read admin/.htaccess.');
check(str_contains($adminIndex, 'http_response_code(404);'), 'diag requests are not rejected.');
check(is_string($adminHealth) && str_contains($adminHealth, "PHP_SAPI !== 'cli'"), 'health diagnostics are not CLI-only.');

$snapshotPath = dirname(__DIR__) . '/frontend/data/vehicles.json';
$snapshotRaw = file_get_contents($snapshotPath);
check(is_string($snapshotRaw) && $snapshotRaw !== '', 'The public vehicle snapshot must remain in the repository.');
$snapshot = json_decode($snapshotRaw, true, 512, JSON_THROW_ON_ERROR);
check(is_array($snapshot) && array_keys($snapshot) === ['vehicles'], 'The public snapshot root may contain only vehicles.');
check(is_array($snapshot['vehicles']) && count($snapshot['vehicles']) > 0, 'The public snapshot must keep the catalog records.');
$allowedSnapshotFields = array_fill_keys(GT_PUBLIC_VEHICLE_SCALAR_FIELDS, true);
foreach ($snapshot['vehicles'] as $vehicle) {
    check(is_array($vehicle), 'Each public snapshot record must be an object.');
    foreach ($vehicle as $key => $value) {
        if ($key === 'gallery') {
            check(is_array($value), 'Gallery must be a list of public paths.');
            foreach ($value as $path) {
                check(is_string($path), 'Gallery entries must be public path strings.');
            }
            continue;
        }
        check(isset($allowedSnapshotFields[$key]), 'Public snapshot contains a non-public field: ' . $key);
    }
    check(array_key_exists('reference_price_usd', $vehicle), 'Public reference price must remain on the snapshot.');
}

$independent = normalizeVehicleRecord([
    'ref_id' => 'TEST-001',
    'display_name_en' => 'Synthetic Vehicle',
    'year' => 2020,
    'reference_price_usd' => 4321,
    'quote_spec_data' => [
        'auction_price_jpy' => 111222,
        'purchase_price_usd' => 333,
        'profit_usd' => 444,
        'target_price_usd' => 9999,
    ],
]);
check(($independent['reference_price_usd'] ?? null) === 4321, 'Public reference price must stay independent from auction, cost, profit, and target price.');
check(($independent['quote_spec_data']['auction_price_jpy'] ?? null) === 111222, 'Private auction data must remain on the administrative record.');

$pdfConfig = require dirname(__DIR__) . '/admin/quote-pdf-config.php';
foreach (($pdfConfig['bank'] ?? []) as $key => $value) {
    check($value === '', 'Invoice bank sample must stay empty: ' . $key);
}
check(($pdfConfig['sequence_file'] ?? null) === '', 'Invoice sequence must not point at the public data directory.');

$buildScript = file_get_contents(dirname(__DIR__) . '/scripts/build-netlify-public-data.js');
check(is_string($buildScript) && str_contains($buildScript, 'Refusing to publish vehicle data because internal fields are present'), 'Netlify build must fail when internal fields are present.');
check(str_contains($rootHtaccess, 'uploads(?:/|$) - [F,END,NC]'), 'Operational uploads must not be served from the public site.');
check(str_contains($productionWorkflow, '/home/gltr/backups/site'), 'Production site backups must be written outside the web root.');
check(str_contains($testWorkflow, '/home/gltr/backups/site'), 'Test site backups must be written outside the web root.');
check(!str_contains($productionWorkflow, '/home/gltr/www/_backups'), 'Production deploy must not write site backups under the web root.');
check(!str_contains($testWorkflow, '/home/gltr/www/_backups'), 'Test deploy must not write site backups under the web root.');
check(!str_contains($productionWorkflow, '--delete') && !str_contains($testWorkflow, '--delete'), 'Deploy rsync must not delete existing server files.');
check(str_contains($productionWorkflow, "--exclude='/frontend/data/backup/'"), 'Production deploy must not restore repository backups.');
check(str_contains($testWorkflow, 'expect_status 403 "$BASE_URL/uploads/quotes/probe.pdf"'), 'Test deploy must reject public quote uploads.');
check(str_contains($testWorkflow, 'expect_status 404 "$BASE_URL/private/west-africa-test/vehicles.json"'), 'Test deploy must show that the private directory is not on the public site.');
check(gt_wa_private_dir_for_site('/home/gltr/www/gloria-test') === '/home/gltr/private/west-africa-test', 'Test storage must use the test private directory.');
check(gt_wa_private_dir_for_site('/home/gltr/www/gloria-site') === '/home/gltr/private/west-africa', 'Production storage must use the production private directory.');
check(gt_wa_private_dir_for_site('/home/gltr/www/gloria-test') !== gt_wa_private_dir_for_site('/home/gltr/www/gloria-site'), 'Test and production private directories must differ.');
check(gt_wa_private_dir_for_site('/tmp/gloria-local') === null, 'An unknown site root must not select operational storage.');
check(!str_contains(gt_redact_private_paths('open /home/gltr/private/west-africa-test/vehicles.json failed'), '/home/gltr/private'), 'Admin errors must not reveal the private directory.');

echo "Public data protection checks passed.\n";
