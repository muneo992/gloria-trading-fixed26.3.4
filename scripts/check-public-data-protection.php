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

echo "Public data protection checks passed.\n";
