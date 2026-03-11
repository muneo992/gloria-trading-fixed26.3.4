<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

define('VEHICLES_JSON', __DIR__ . '/../data/vehicles.json');

$format = $_GET['format'] ?? 'csv'; // csv or template

// vehicles.json を読み込む
function loadVehicles() {
    if (!file_exists(VEHICLES_JSON)) return ['vehicles' => []];
    return json_decode(file_get_contents(VEHICLES_JSON), true) ?: ['vehicles' => []];
}

// CSVヘッダー定義
$headers = [
    'ref_id'           => 'Ref ID',
    'display_name_en'  => '車両名 (英語)',
    'year'             => '年式',
    'make'             => 'メーカー',
    'model'            => 'モデル',
    'body_type'        => 'ボディタイプ',
    'fuel_type'        => '燃料タイプ',
    'transmission'     => 'ミッション',
    'mileage_km'       => '走行距離(km)',
    'price_low_usd'    => '価格下限(USD)',
    'price_high_usd'   => '価格上限(USD)',
    'basis_from'       => '参考期間(開始) YYYY-MM',
    'basis_to'         => '参考期間(終了) YYYY-MM',
    'disclaimer_short' => '免責事項',
    'gallery'          => '画像パス(複数はセミコロン区切り)',
];

// テンプレートダウンロード（サンプルデータ入り）
if ($format === 'template') {
    $filename = 'gloria_vehicles_template.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $output = fopen('php://output', 'w');
    // BOM for Excel UTF-8
    fputs($output, "\xEF\xBB\xBF");

    // ヘッダー行
    fputcsv($output, array_values($headers));

    // サンプルデータ行
    $sample = [
        'REF-006',
        'Toyota Land Cruiser 2021',
        '2021',
        'Toyota',
        'Land Cruiser',
        'SUV',
        'Diesel',
        'Automatic',
        '30000',
        '25000',
        '30000',
        '2023-01',
        '2023-06',
        'Reference vehicle only. Not in stock. Photo for reference.',
        'images/vehicles/ref-006-1.jpg',
    ];
    fputcsv($output, $sample);

    fclose($output);
    exit;
}

// 通常エクスポート
$data = loadVehicles();
$vehicles = $data['vehicles'];

$filename = 'gloria_vehicles_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');

$output = fopen('php://output', 'w');
// BOM for Excel UTF-8
fputs($output, "\xEF\xBB\xBF");

// ヘッダー行
fputcsv($output, array_values($headers));

// データ行
foreach ($vehicles as $v) {
    $gallery_str = isset($v['gallery']) ? implode(';', $v['gallery']) : '';
    $row = [
        $v['ref_id']           ?? '',
        $v['display_name_en']  ?? '',
        $v['year']             ?? '',
        $v['make']             ?? '',
        $v['model']            ?? '',
        $v['body_type']        ?? '',
        $v['fuel_type']        ?? '',
        $v['transmission']     ?? '',
        $v['mileage_km']       ?? '',
        $v['price_low_usd']    ?? '',
        $v['price_high_usd']   ?? '',
        $v['basis_from']       ?? '',
        $v['basis_to']         ?? '',
        $v['disclaimer_short'] ?? '',
        $gallery_str,
    ];
    fputcsv($output, $row);
}

fclose($output);
exit;
