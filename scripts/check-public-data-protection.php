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
check(is_string($rootHtaccess) && str_contains($rootHtaccess, '^data/vehicles\\.json$ frontend/data/vehicle-feed.php'), 'Canonical public feed rewrite is missing.');
check(str_contains($rootHtaccess, '^frontend/data/vehicles\\.json$ - [F,L,NC]'), 'Direct master JSON denial is missing.');
check(str_contains($rootHtaccess, 'data/backup(?:/|$) - [F,L,NC]'), 'Backup denial is missing.');
check(is_string($dataHtaccess) && str_contains($dataHtaccess, '^vehicles\\.json$ - [F,L,NC]'), 'Data-directory master denial is missing.');
check(str_contains($dataHtaccess, '^backup(?:/|$) - [F,L,NC]'), 'Data-directory backup denial is missing.');
check(
    is_string($netlifyConfig)
    && str_contains($netlifyConfig, 'from = "/data/vehicles.json"')
    && str_contains($netlifyConfig, 'to = "https://www.gloriatrading.com/data/vehicles.json"')
    && str_contains($netlifyConfig, 'force = true'),
    'Netlify must not serve the administrative master as a static asset.'
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
    str_contains($testWorkflow, "php '\$EXPECTED_SAKURA_TEST_PATH/admin/health.php'")
    && !str_contains($testWorkflow, 'admin/index.php?diag=1'),
    'Test deployment must use CLI-only health diagnostics.'
);
check(is_string($adminIndex) && !str_contains($adminIndex, "file_get_contents(__DIR__ . '/.htaccess')"), 'Web diagnostics still read admin/.htaccess.');
check(str_contains($adminIndex, 'http_response_code(404);'), 'diag requests are not rejected.');
check(is_string($adminHealth) && str_contains($adminHealth, "PHP_SAPI !== 'cli'"), 'health diagnostics are not CLI-only.');

echo "Public data protection checks passed.\n";
