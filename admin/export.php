<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/vehicle-data.php';

$format = $_GET['format'] ?? 'csv'; // csv or template

// CSVヘッダー定義（参考期間は廃止。C案：CSV初期投入＋管理画面個別調整用）
$headers = [
    'ref_id'                 => 'Ref ID',
    'display_name_en'        => '車両名 (英語)',
    'year'                   => '年式',
    'make'                   => 'メーカー',
    'model'                  => 'モデル',
    'body_type'              => 'ボディタイプ',
    'fuel_type'              => '燃料タイプ',
    'transmission'           => 'ミッション',
    'mileage_km'             => '走行距離(km)',
    'engine_cc'              => '排気量(cc)',
    'reference_price_usd'    => 'FOB価格(USD)',
    'disclaimer_short'       => '免責事項',
    'best_for_resale_in'     => 'Best for resale in',
    'typical_buyer_use'      => 'Typical buyer use',
    'similar_units'          => 'Similar units',
    'bulk_repeat_order'      => 'Bulk / repeat order',
    'gallery'                => '画像パス(複数はセミコロン区切り)',
    'auction_price_jpy'      => '落札価格(JPY)',
    'exchange_rate_jpy_usd'  => '為替(JPY/USD)',
    'purchase_price_usd'     => '仕入価格(USD)',
    'land_transport_usd'     => '陸送費(USD)',
    'ocean_freight_usd'      => '船賃(USD)',
    'insurance_usd'          => '保険料(USD)',
    'inspection_fee_usd'     => '検査費(USD)',
    'other_cost_usd'         => 'その他費用(USD)',
    'profit_usd'             => '利益(USD)',
    'target_price_usd'       => '販売目標価格(USD)',
    'destination_country'    => '輸出先国',
    'customer_name'          => '顧客名',
    'customer_contact'       => '顧客連絡先',
    'quote_memo'             => '見積メモ',
];

// テンプレートダウンロード（サンプルデータ入り）
if ($format === 'template') {
    $filename = 'gloria_vehicles_template.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF");
    fputcsv($output, array_values($headers));

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
        '4600',
        '25000',
        'Reference vehicle only. Not in stock. Photo for reference.',
        "Ghana / Nigeria / Benin / Côte d'Ivoire",
        'Dealer resale stock, taxi and ride-hailing use, and small business fleet.',
        'Toyota Land Cruiser / Prado / Hilux class units',
        'Dealer RFQ supported',
        'images/vehicles/ref-006-1.jpg',
        '2500000',
        '150',
        '16667',
        '300',
        '1200',
        '120',
        '100',
        '150',
        '800',
        '19317',
        'Ghana',
        'Sample Customer',
        '+233-00-000-0000',
        'Sample row. Replace values before import.',
    ];
    fputcsv($output, $sample);

    fclose($output);
    exit;
}

$data = loadVehicles();
$vehicles = $data['vehicles'];

$filename = 'gloria_vehicles_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');

$output = fopen('php://output', 'w');
fputs($output, "\xEF\xBB\xBF");
fputcsv($output, array_values($headers));

foreach ($vehicles as $v) {
    $gallery_str = isset($v['gallery']) ? implode(';', $v['gallery']) : '';
    $quote = gt_quote_spec_data_value($v);
    $row = [
        $v['ref_id'] ?? '',
        $v['display_name_en'] ?? '',
        $v['year'] ?? '',
        $v['make'] ?? '',
        $v['model'] ?? '',
        $v['body_type'] ?? '',
        $v['fuel_type'] ?? '',
        $v['transmission'] ?? '',
        $v['mileage_km'] ?? '',
        $v['engine_cc'] ?? ($quote['engine_cc'] ?? ''),
        $v['reference_price_usd'] ?? '',
        $v['disclaimer_short'] ?? '',
        $v['best_for_resale_in'] ?? '',
        $v['typical_buyer_use'] ?? '',
        $v['similar_units'] ?? '',
        $v['bulk_repeat_order'] ?? '',
        $gallery_str,
        $quote['auction_price_jpy'] ?? '',
        $quote['exchange_rate_jpy_usd'] ?? '',
        $quote['purchase_price_usd'] ?? '',
        $quote['land_transport_usd'] ?? '',
        $quote['ocean_freight_usd'] ?? '',
        $quote['insurance_usd'] ?? '',
        $quote['inspection_fee_usd'] ?? '',
        $quote['other_cost_usd'] ?? '',
        $quote['profit_usd'] ?? '',
        $quote['target_price_usd'] ?? '',
        $quote['destination_country'] ?? '',
        $quote['customer_name'] ?? '',
        $quote['customer_contact'] ?? '',
        $quote['quote_memo'] ?? '',
    ];
    fputcsv($output, $row);
}

fclose($output);
exit;
