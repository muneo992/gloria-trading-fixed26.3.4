<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/vehicle-data.php';


function gt_safe_filename($name) {
    $name = basename($name);
    $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
    return $name ?: ('file_' . time());
}

function gt_save_quote_uploads($field, $ref, $subdir, $allowed_mimes) {
    $saved = [];
    if (empty($_FILES[$field]['name'][0])) return $saved;
    $safe_ref = preg_replace('/[^a-zA-Z0-9\-]/', '', strtolower($ref));
    $dir = QUOTE_UPLOAD_DIR . $safe_ref . '/' . $subdir . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    foreach ($_FILES[$field]['tmp_name'] as $i => $tmp) {
        if ($_FILES[$field]['error'][$i] !== UPLOAD_ERR_OK) continue;
        $mime = mime_content_type($tmp);
        if (!in_array($mime, $allowed_mimes, true)) continue;
        $original = gt_safe_filename($_FILES[$field]['name'][$i]);
        $filename = date('YmdHis') . '_' . sprintf('%02d', $i + 1) . '_' . $original;
        $dest = $dir . $filename;
        if (move_uploaded_file($tmp, $dest)) {
            $saved[] = QUOTE_UPLOAD_URL . $safe_ref . '/' . $subdir . '/' . $filename;
        }
    }
    return $saved;
}


function gt_lower($value) {
    $value = trim((string)$value);
    return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
}

function gt_b2b_best_for_resale_from_post(): string {
    $allowed = ['Ghana', 'Nigeria', 'Benin', "Côte d'Ivoire"];
    $selected = $_POST['best_for_resale_markets'] ?? [];
    if (!is_array($selected)) $selected = [];
    $selected = array_values(array_intersect($allowed, array_map('trim', $selected)));
    $extra = trim((string)($_POST['best_for_resale_extra'] ?? ''));
    $parts = $selected;
    if ($extra !== '') $parts[] = $extra;
    return trim(implode(' / ', array_unique(array_filter($parts))));
}

function gt_detect_csv_columns($header_row) {
    $col_map = [
        'ref_id' => null,
        'display_name_en' => null,
        'year' => null,
        'make' => null,
        'model' => null,
        'body_type' => null,
        'fuel_type' => null,
        'transmission' => null,
        'mileage_km' => null,
        'engine_cc' => null,
        'reference_price_usd' => null,
        'basis_from' => null,
        'basis_to' => null,
        'disclaimer_short' => null,
        'best_for_resale_in' => null,
        'typical_buyer_use' => null,
        'similar_units' => null,
        'bulk_repeat_order' => null,
        'gallery' => null,
        'auction_price_jpy' => null,
        'exchange_rate_jpy_usd' => null,
        'purchase_price_usd' => null,
        'land_transport_usd' => null,
        'ocean_freight_usd' => null,
        'inspection_fee_usd' => null,
        'other_cost_usd' => null,
        'profit_usd' => null,
        'target_price_usd' => null,
        'insurance_usd' => null,
        'invoice_no' => null,
        'invoice_date' => null,
        'destination_country' => null,
        'customer_name' => null,
        'customer_address' => null,
        'customer_tel' => null,
        'customer_email' => null,
        'customer_contact' => null,
        'customer_attn' => null,
        'port_of_loading' => null,
        'port_of_discharge' => null,
        'incoterms' => null,
        'payment_terms' => null,
        'currency' => null,
        'chassis_no' => null,
        'quote_valid_until' => null,
        'quote_memo' => null,
    ];

    $header_keywords = [
        'ref_id' => ['ref id', 'ref_id', 'refid'],
        'display_name_en' => ['車両名', 'display_name', 'vehicle name', '名前'],
        'year' => ['年式', 'year'],
        'make' => ['メーカー', 'make', 'brand'],
        'model' => ['モデル', 'model'],
        'body_type' => ['ボディ', 'body_type', 'body type'],
        'fuel_type' => ['燃料', 'fuel_type', 'fuel type'],
        'transmission' => ['ミッション', 'transmission', 'trans'],
        'mileage_km' => ['走行距離', 'mileage', 'mileage_km'],
        'engine_cc' => ['排気量', 'engine_cc', 'engine cc', 'displacement', 'displacement_cc'],
        'reference_price_usd' => ['参考価格', 'fob価格', 'fob price', 'reference_price', 'reference price', 'price_usd'],
        'basis_from' => ['参考期間(開始)', 'basis_from', 'basis from', '開始'],
        'basis_to' => ['参考期間(終了)', 'basis_to', 'basis to', '終了'],
        'disclaimer_short' => ['免責', 'disclaimer'],
        'best_for_resale_in' => ['best for resale', 'resale market', '再販向け', '販売向け国', '対象国', 'best_for_resale_in'],
        'typical_buyer_use' => ['typical buyer use', 'buyer use', '用途', '購入者用途', 'typical_buyer_use'],
        'similar_units' => ['similar units', 'similar model', '類似車両', '類似モデル', 'similar_units'],
        'bulk_repeat_order' => ['bulk', 'repeat order', 'bulk / repeat', '複数台', 'リピート', 'bulk_repeat_order'],
        'gallery' => ['画像パス', 'gallery', 'image paths', 'photo paths'],
        'auction_price_jpy' => ['落札価格', 'auction_price_jpy', 'auction price', 'hammer price'],
        'exchange_rate_jpy_usd' => ['為替', 'exchange_rate', 'jpy usd', 'jpy/usd'],
        'purchase_price_usd' => ['仕入価格', 'purchase_price', 'purchase price', 'cost price'],
        'land_transport_usd' => ['陸送費', 'land_transport', 'land transport', 'domestic transport'],
        'ocean_freight_usd' => ['船賃', 'ocean_freight', 'ocean freight', 'freight'],
        'inspection_fee_usd' => ['検査費', 'inspection_fee', 'inspection fee', 'inspection'],
        'other_cost_usd' => ['その他費用', 'other_cost', 'other cost', 'misc cost'],
        'profit_usd' => ['利益', 'profit', 'margin'],
        'target_price_usd' => ['販売目標価格', 'target_price', 'target price', 'selling target'],
        'insurance_usd' => ['保険料', 'insurance_usd', 'insurance'],
        'invoice_no' => ['見積番号', 'invoice_no', 'invoice number', 'proforma no'],
        'invoice_date' => ['見積日', 'invoice_date', 'invoice date'],
        'destination_country' => ['輸出先国', 'destination_country', 'destination country', 'country'],
        'customer_name' => ['顧客名', 'customer_name', 'customer name', 'client name'],
        'customer_address' => ['顧客住所', 'customer_address', 'customer address', 'address'],
        'customer_tel' => ['顧客電話', 'customer_tel', 'customer tel', 'buyer tel', 'tel'],
        'customer_email' => ['顧客メール', 'customer_email', 'customer email', 'buyer email', 'email'],
        'customer_contact' => ['顧客連絡先', 'customer_contact', 'customer contact', 'contact'],
        'customer_attn' => ['担当者', 'customer_attn', 'attn', 'attention'],
        'port_of_loading' => ['積地港', 'port_of_loading', 'port of loading', 'loading port'],
        'port_of_discharge' => ['揚地港', 'port_of_discharge', 'port of discharge', 'discharge port'],
        'incoterms' => ['インコタームズ', 'incoterms', 'incoterm', 'trade terms', 'trade term'],
        'payment_terms' => ['支払条件', 'payment_terms', 'payment terms'],
        'currency' => ['通貨', 'currency'],
        'chassis_no' => ['車台番号', 'chassis_no', 'chassis number', 'vin'],
        'quote_valid_until' => ['見積有効期限', 'quote_valid_until', 'valid until', 'expiry'],
        'quote_memo' => ['見積メモ', 'quote_memo', 'quote memo', 'memo', 'note'],
    ];

    foreach ($header_row as $i => $h) {
        $h_lower = gt_lower($h);
        foreach ($header_keywords as $field => $keywords) {
            if ($col_map[$field] !== null) continue;
            foreach ($keywords as $kw) {
                if (strpos($h_lower, gt_lower($kw)) !== false) {
                    $col_map[$field] = $i;
                    break 2;
                }
            }
        }
    }
    return $col_map;
}

function gt_cell($row, $index) {
    if ($index === null || $index < 0 || !array_key_exists($index, $row)) return '';
    return trim((string)$row[$index]);
}

function gt_quote_data_from_post(array $base_quote): array {
    $quote = $base_quote;
    $numeric_fields = [
        'purchase_price_usd', 'land_transport_usd', 'ocean_freight_usd',
        'insurance_usd', 'inspection_fee_usd', 'other_cost_usd',
        'profit_usd', 'target_price_usd', 'auction_price_jpy', 'exchange_rate_jpy_usd'
    ];
    foreach ($numeric_fields as $field_name) {
        $post_key = 'quote_' . $field_name;
        if (array_key_exists($post_key, $_POST)) {
            $quote[$field_name] = (int)str_replace(',', '', (string)($_POST[$post_key] ?? '0'));
        }
    }

    $string_fields = [
        'invoice_no', 'invoice_date', 'destination_country',
        'customer_name', 'customer_address', 'customer_tel', 'customer_email',
        'customer_contact', 'customer_attn', 'port_of_loading', 'port_of_discharge',
        'incoterms', 'payment_terms', 'currency', 'chassis_no', 'engine_cc',
        'quote_valid_until', 'quote_memo'
    ];
    foreach ($string_fields as $field_name) {
        $post_key = 'quote_' . $field_name;
        if (array_key_exists($post_key, $_POST)) {
            $quote[$field_name] = trim((string)($_POST[$post_key] ?? ''));
        }
    }

    return gt_quote_spec_data_value(['quote_spec_data' => $quote, 'reference_price_usd' => $quote['target_price_usd'] ?? 0]);
}


function gt_export_document_data_from_post(array $base): array {
    $doc = $base;
    $fields = [
        'commercial_invoice_no', 'commercial_invoice_date', 'packing_list_no', 'packing_list_date',
        'consignee_name', 'consignee_address', 'consignee_tel', 'consignee_email',
        'notify_name', 'notify_address', 'notify_tel', 'notify_email',
        'vessel_name', 'voyage_no', 'booking_no', 'bl_no', 'final_destination',
        'marks_and_numbers', 'shipping_memo'
    ];
    foreach ($fields as $field_name) {
        $post_key = 'export_' . $field_name;
        if (array_key_exists($post_key, $_POST)) {
            $doc[$field_name] = trim((string)($_POST[$post_key] ?? ''));
        }
    }
    return gt_export_document_data_value(['export_document_data' => $doc]);
}

function gt_vehicle_certificate_data_from_post(array $base): array {
    $cert = $base;
    $fields = [
        'registration_no', 'registration_date', 'first_registration', 'vehicle_type', 'use',
        'private_business', 'body_shape', 'make_jp', 'make_en', 'model_code', 'chassis_no',
        'engine_model', 'fuel', 'displacement_l', 'engine_cc', 'capacity', 'max_load_kg',
        'vehicle_weight_kg', 'gross_weight_kg', 'length_cm', 'width_cm', 'height_cm',
        'owner_name', 'remarks', 'ocr_text'
    ];
    foreach ($fields as $field_name) {
        $post_key = 'cert_' . $field_name;
        if (array_key_exists($post_key, $_POST)) {
            $cert[$field_name] = trim((string)($_POST[$post_key] ?? ''));
        }
    }
    return gt_vehicle_certificate_data_value(['vehicle_certificate_data' => $cert]);
}

function gt_apply_master_csv_upload($field, $current_ref, $vehicle, &$errors, $apply_site_data = true, $apply_quote_data = true) {
    if (empty($_FILES[$field]['name']) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $vehicle;
    }
    if (($_FILES[$field]['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $errors[] = '見積兼サイト用マスターCSVのアップロードに失敗しました。';
        return $vehicle;
    }

    $tmp = $_FILES[$field]['tmp_name'];
    $mime = mime_content_type($tmp);
    $allowed_mimes = ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'];
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv' && !in_array($mime, $allowed_mimes, true)) {
        $errors[] = '見積兼サイト用マスターCSVはCSVファイルを選択してください。';
        return $vehicle;
    }

    $content = file_get_contents($tmp);
    $content = ltrim($content, "\xEF\xBB\xBF");
    file_put_contents($tmp, $content);

    $handle = fopen($tmp, 'r');
    if (!$handle) {
        $errors[] = '見積兼サイト用マスターCSVを読み込めませんでした。';
        return $vehicle;
    }

    $header_row = fgetcsv($handle);
    if (!$header_row) {
        fclose($handle);
        $errors[] = '見積兼サイト用マスターCSVが空か、形式が正しくありません。';
        return $vehicle;
    }

    $col_map = gt_detect_csv_columns($header_row);
    if ($col_map['ref_id'] === null) {
        fclose($handle);
        $errors[] = '見積兼サイト用マスターCSVに「Ref ID」列が見つかりません。';
        return $vehicle;
    }

    $target_ref = trim((string)$current_ref);
    $candidate_rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        if (empty(array_filter($row, function($v) { return trim((string)$v) !== ''; }))) continue;
        $candidate_rows[] = $row;
    }
    fclose($handle);

    if (empty($candidate_rows)) {
        $errors[] = '見積兼サイト用マスターCSVにデータ行が見つかりません。';
        return $vehicle;
    }

    $matched = null;
    foreach ($candidate_rows as $row) {
        $csv_ref = gt_cell($row, $col_map['ref_id']);
        if ($target_ref === '' || strcasecmp($csv_ref, $target_ref) === 0) {
            $matched = $row;
            break;
        }
    }

    if ($matched === null && count($candidate_rows) === 1) {
        $matched = $candidate_rows[0];
    }

    if ($matched === null) {
        $errors[] = '見積兼サイト用マスターCSV内に編集中のRef ID（' . htmlspecialchars($target_ref) . '）と一致する行がありません。';
        return $vehicle;
    }

    $csv_ref = gt_cell($matched, $col_map['ref_id']);
    if ($target_ref !== '' && strcasecmp($csv_ref, $target_ref) !== 0) {
        $errors[] = '見積兼サイト用マスターCSVのRef ID（' . htmlspecialchars($csv_ref) . '）が編集中のRef ID（' . htmlspecialchars($target_ref) . '）と一致しません。';
        return $vehicle;
    }

    if ($apply_site_data) {
        if ($csv_ref !== '') $vehicle['ref_id'] = $csv_ref;

        $string_fields = ['display_name_en', 'make', 'model', 'body_type', 'fuel_type', 'transmission', 'basis_from', 'basis_to', 'disclaimer_short', 'best_for_resale_in', 'typical_buyer_use', 'similar_units', 'bulk_repeat_order'];
        foreach ($string_fields as $field_name) {
            $value = gt_cell($matched, $col_map[$field_name] ?? null);
            if ($value !== '') $vehicle[$field_name] = $value;
        }
        if (!empty($vehicle['transmission'])) {
            $vehicle['transmission'] = gt_normalize_transmission($vehicle['transmission']);
        }
        if (!empty($vehicle['body_type'])) {
            $vehicle['body_type'] = gt_normalize_body_type($vehicle['body_type']);
        }
        if (!empty($vehicle['fuel_type'])) {
            $vehicle['fuel_type'] = gt_normalize_fuel_type($vehicle['fuel_type']);
        }

        $year = gt_cell($matched, $col_map['year'] ?? null);
        if ($year !== '') $vehicle['year'] = (int)$year;
        $mileage = gt_cell($matched, $col_map['mileage_km'] ?? null);
        if ($mileage !== '') $vehicle['mileage_km'] = (int)str_replace(',', '', $mileage);
        $engineCc = gt_cell($matched, $col_map['engine_cc'] ?? null);
        if ($engineCc !== '') $vehicle['engine_cc'] = (int)str_replace(',', '', $engineCc);
        $price = gt_cell($matched, $col_map['reference_price_usd'] ?? null);
        if ($price !== '') $vehicle['reference_price_usd'] = (int)str_replace(',', '', $price);

        $gallery_raw = gt_cell($matched, $col_map['gallery'] ?? null);
        if ($gallery_raw !== '') {
            $vehicle['gallery'] = array_values(array_unique(array_filter(array_map('trim', explode(';', $gallery_raw)))));
        }
    }

    if ($apply_quote_data) {
        if (empty($vehicle['quote_spec_data']) || !is_array($vehicle['quote_spec_data'])) {
            $vehicle['quote_spec_data'] = [];
        }

        $numeric_quote_fields = [
            'auction_price_jpy', 'exchange_rate_jpy_usd', 'purchase_price_usd', 'land_transport_usd', 'ocean_freight_usd',
            'inspection_fee_usd', 'other_cost_usd', 'profit_usd', 'target_price_usd',
            'insurance_usd'
        ];
        foreach ($numeric_quote_fields as $field_name) {
            $value = gt_cell($matched, $col_map[$field_name] ?? null);
            if ($value !== '') $vehicle['quote_spec_data'][$field_name] = (int)str_replace(',', '', $value);
        }

        $string_quote_fields = [
            'invoice_no', 'invoice_date', 'destination_country',
            'customer_name', 'customer_address', 'customer_tel', 'customer_email',
            'customer_contact', 'customer_attn', 'port_of_loading', 'port_of_discharge',
            'incoterms', 'payment_terms', 'currency', 'chassis_no', 'engine_cc',
            'quote_valid_until', 'quote_memo'
        ];
        foreach ($string_quote_fields as $field_name) {
            $value = gt_cell($matched, $col_map[$field_name] ?? null);
            if ($value !== '') $vehicle['quote_spec_data'][$field_name] = $value;
        }
    }

    return $vehicle;
}

$ref_id = $_GET['ref'] ?? '';
$data = loadVehicles();
$vehicles = &$data['vehicles'];
$is_edit = false;
$vehicle = [
    'ref_id' => '', 'display_name_en' => '', 'year' => '', 'make' => '',
    'model' => '', 'body_type' => '', 'fuel_type' => 'Diesel',
    'transmission' => 'Manual', 'mileage_km' => '', 'engine_cc' => '', 'reference_price_usd' => '',
    'basis_from' => '', 'basis_to' => '',
    'disclaimer_short' => 'Reference vehicle only. Not in stock. Photo for reference.',
    'best_for_resale_in' => "Ghana / Nigeria / Benin / Côte d'Ivoire",
    'typical_buyer_use' => '',
    'similar_units' => '',
    'bulk_repeat_order' => 'Dealer RFQ supported',
    'gallery' => [], 'quote_spec_files' => [], 'quote_image_files' => [],
    'quote_spec_data' => gt_quote_spec_data_value([])
];

if ($ref_id) {
    foreach ($vehicles as $v) {
        if ($v['ref_id'] === $ref_id) {
            $vehicle = $v;
            $is_edit = true;
            break;
        }
    }
}

$errors = [];
$success = false;

// --- 保存処理 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    $upload_mode = $_POST['upload_mode'] ?? 'both';
    if (!in_array($upload_mode, ['quote_only', 'site_only', 'both'], true)) {
        $upload_mode = 'both';
    }
    $process_quote_uploads = in_array($upload_mode, ['quote_only', 'both'], true);
    $process_site_uploads = in_array($upload_mode, ['site_only', 'both'], true);
    $has_master_csv_upload = !empty($_FILES['site_data_csv']['name']) && (($_FILES['site_data_csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);

    $submitted_ref = trim($_POST['ref_id'] ?? '');
    $submitted_vehicle = [
        'ref_id'           => $submitted_ref,
        'display_name_en'  => trim($_POST['display_name_en'] ?? ''),
        'year'             => (int)($_POST['year'] ?? 0),
        'make'             => trim($_POST['make'] ?? ''),
        'model'            => trim($_POST['model'] ?? ''),
        'body_type'        => gt_normalize_body_type($_POST['body_type'] ?? '') ?: ($is_edit ? gt_normalize_body_type($vehicle['body_type'] ?? '') : ''),
        'fuel_type'        => gt_normalize_fuel_type($_POST['fuel_type'] ?? '') ?: ($is_edit ? gt_normalize_fuel_type($vehicle['fuel_type'] ?? '') : ''),
        'transmission'     => gt_normalize_transmission($_POST['transmission'] ?? '') ?: ($is_edit ? gt_normalize_transmission($vehicle['transmission'] ?? '') : ''),
        'mileage_km'       => (int)str_replace(',', '', $_POST['mileage_km'] ?? '0'),
        'engine_cc'        => (int)str_replace(',', '', $_POST['engine_cc'] ?? '0'),
        'reference_price_usd' => (int)str_replace(',', '', $_POST['reference_price_usd'] ?? '0'),
        'basis_from'       => trim($_POST['basis_from'] ?? ''),
        'basis_to'         => trim($_POST['basis_to'] ?? ''),
        'disclaimer_short' => trim($_POST['disclaimer_short'] ?? ''),
        'best_for_resale_in' => gt_b2b_best_for_resale_from_post(),
        'typical_buyer_use' => trim($_POST['typical_buyer_use'] ?? ($_POST['typical_buyer_use_preset'] ?? '')),
        'similar_units' => trim($_POST['similar_units'] ?? ''),
        'bulk_repeat_order' => trim($_POST['bulk_repeat_order'] ?? 'Dealer RFQ supported'),
        'gallery'          => json_decode($_POST['gallery_json'] ?? '[]', true) ?: [],
        'quote_spec_files' => json_decode($_POST['quote_spec_files_json'] ?? '[]', true) ?: [],
        'quote_image_files'=> json_decode($_POST['quote_image_files_json'] ?? '[]', true) ?: [],
        'vehicle_certificate_files' => json_decode($_POST['vehicle_certificate_files_json'] ?? '[]', true) ?: [],
        'quote_spec_data' => $vehicle['quote_spec_data'] ?? gt_quote_spec_data_value([]),
        'export_document_data' => $vehicle['export_document_data'] ?? gt_export_document_data_value($vehicle),
        'vehicle_certificate_data' => $vehicle['vehicle_certificate_data'] ?? gt_vehicle_certificate_data_value($vehicle)
    ];

    if ($upload_mode === 'quote_only') {
        if (!$is_edit) {
            $errors[] = '見積書作成用だけ保存は、登録済み車両の編集画面で使用してください。新規車両は先に「両方を同時保存」または「サイト表示用だけ保存」で登録してください。';
            $new_vehicle = $submitted_vehicle;
        } else {
            // 見積書作成用だけ保存では、サイト表示用データ・サイト表示用画像を変更しない。
            $new_vehicle = $vehicle;
            $new_vehicle['quote_spec_files'] = $vehicle['quote_spec_files'] ?? [];
            $new_vehicle['quote_image_files'] = $vehicle['quote_image_files'] ?? [];
            $new_vehicle['vehicle_certificate_files'] = $vehicle['vehicle_certificate_files'] ?? [];
            $new_vehicle['quote_spec_data'] = $vehicle['quote_spec_data'] ?? gt_quote_spec_data_value([]);
            $new_vehicle['export_document_data'] = $vehicle['export_document_data'] ?? gt_export_document_data_value($vehicle);
            $new_vehicle['vehicle_certificate_data'] = $vehicle['vehicle_certificate_data'] ?? gt_vehicle_certificate_data_value($vehicle);
        }
    } else {
        // サイト表示用だけ、または両方同時保存では、画面入力値をサイト表示用データとして反映する。
        $new_vehicle = $submitted_vehicle;
        if ($upload_mode === 'site_only' && $is_edit) {
            // サイト表示用だけ保存では、見積書作成用ファイル一覧を現在値のまま保持する。
            $new_vehicle['quote_spec_files'] = $vehicle['quote_spec_files'] ?? [];
            $new_vehicle['quote_image_files'] = $vehicle['quote_image_files'] ?? [];
            $new_vehicle['vehicle_certificate_files'] = $vehicle['vehicle_certificate_files'] ?? [];
            $new_vehicle['quote_spec_data'] = $vehicle['quote_spec_data'] ?? gt_quote_spec_data_value([]);
            $new_vehicle['export_document_data'] = $vehicle['export_document_data'] ?? gt_export_document_data_value($vehicle);
            $new_vehicle['vehicle_certificate_data'] = $vehicle['vehicle_certificate_data'] ?? gt_vehicle_certificate_data_value($vehicle);
        }
    }

    $new_ref = $new_vehicle['ref_id'] ?? $submitted_ref;

    // 見積兼サイト用マスターCSVアップロード処理
    if ($process_site_uploads || $process_quote_uploads) {
        $new_vehicle = gt_apply_master_csv_upload('site_data_csv', $new_ref, $new_vehicle, $errors, $process_site_uploads, $process_quote_uploads);
        $new_ref = $new_vehicle['ref_id'] ?? $new_ref;
    }

    // 見積書専用項目の手入力は、CSVをアップロードしない保存時に反映する。
    // CSVアップロード時は、CSV内の見積項目を優先して反映する。
    if ($process_quote_uploads && !$has_master_csv_upload) {
        $new_vehicle['quote_spec_data'] = gt_quote_data_from_post($new_vehicle['quote_spec_data'] ?? gt_quote_spec_data_value($new_vehicle));
        $new_vehicle['export_document_data'] = gt_export_document_data_from_post($new_vehicle['export_document_data'] ?? gt_export_document_data_value($new_vehicle));
        $new_vehicle['vehicle_certificate_data'] = gt_vehicle_certificate_data_from_post($new_vehicle['vehicle_certificate_data'] ?? gt_vehicle_certificate_data_value($new_vehicle));
    }

    // バリデーション
    if (empty($new_ref)) $errors[] = 'Ref IDは必須です。';
    if (empty($new_vehicle['display_name_en'])) $errors[] = '車両名は必須です。';
    if (($new_vehicle['year'] ?? 0) < 1990 || ($new_vehicle['year'] ?? 0) > 2030) $errors[] = '年式を正しく入力してください。';

    // Ref IDの重複チェック（新規の場合）
    if (!$is_edit && empty($errors)) {
        foreach ($vehicles as $v) {
            if ($v['ref_id'] === $new_ref) {
                $errors[] = 'このRef IDはすでに使用されています。';
                break;
            }
        }
    }

    // サイト表示用画像アップロード処理
    if ($process_site_uploads) {
        if (!is_dir(IMAGES_DIR)) mkdir(IMAGES_DIR, 0755, true);

        if (!empty($_FILES['new_images']['name'][0])) {
            $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            foreach ($_FILES['new_images']['tmp_name'] as $i => $tmp) {
                if ($_FILES['new_images']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $mime = mime_content_type($tmp);
                if (!in_array($mime, $allowed)) continue;
                $ext = pathinfo($_FILES['new_images']['name'][$i], PATHINFO_EXTENSION);
                $safe_ref = preg_replace('/[^a-zA-Z0-9\-]/', '', strtolower($new_ref));
                $filename = $safe_ref . '-' . (count($new_vehicle['gallery']) + 1) . '.' . strtolower($ext);
                $dest = IMAGES_DIR . $filename;
                if (move_uploaded_file($tmp, $dest)) {
                    $new_vehicle['gallery'][] = 'images/vehicles/' . $filename;
                }
            }
        }
    }

    // 見積もり用スペック・画像アップロード処理
    if ($process_quote_uploads) {
        $spec_mimes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
            'text/csv',
            'image/jpeg',
            'image/png',
            'image/webp'
        ];
        $quote_image_mimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $new_vehicle['quote_spec_files'] = array_merge($new_vehicle['quote_spec_files'] ?? [], gt_save_quote_uploads('quote_spec_files', $new_ref, 'specs', $spec_mimes));
        $new_vehicle['quote_image_files'] = array_merge($new_vehicle['quote_image_files'] ?? [], gt_save_quote_uploads('quote_image_files', $new_ref, 'images', $quote_image_mimes));
        $new_vehicle['vehicle_certificate_files'] = array_merge($new_vehicle['vehicle_certificate_files'] ?? [], gt_save_quote_uploads('vehicle_certificate_files', $new_ref, 'vehicle-certificates', $spec_mimes));
    }

    if (empty($errors)) {
        if ($is_edit) {
            foreach ($vehicles as &$v) {
                if ($v['ref_id'] === $ref_id) { $v = $new_vehicle; break; }
            }
            unset($v);
        } else {
            $vehicles[] = $new_vehicle;
        }
        saveVehicles($data);
        header('Location: index.php?saved=1&mode=' . urlencode($upload_mode));
        exit;
    }
    $vehicle = $new_vehicle;
}


// --- 見積もり用ファイル削除 ---
if (isset($_POST['action']) && $_POST['action'] === 'delete_quote_file') {
    $file_path = $_POST['file_path'] ?? '';
    $file_type = $_POST['file_type'] ?? '';
    $key = $file_type === 'image' ? 'quote_image_files' : ($file_type === 'certificate' ? 'vehicle_certificate_files' : 'quote_spec_files');
    foreach ($vehicles as &$v) {
        if ($v['ref_id'] === $ref_id) {
            $v[$key] = array_values(array_filter($v[$key] ?? [], function ($g) use ($file_path) {
                return $g !== $file_path;
            }));
            $vehicle = $v;
            break;
        }
    }
    unset($v);
    saveVehicles($data);
    $full_path = FRONTEND_DIR . '/' . ltrim($file_path, '/');
    if (file_exists($full_path)) @unlink($full_path);
    header('Location: edit.php?ref=' . urlencode($ref_id) . '&quote_file_deleted=1');
    exit;
}

// --- 画像削除 ---
if (isset($_POST['action']) && $_POST['action'] === 'delete_image') {
    $img_path = $_POST['img_path'] ?? '';
    foreach ($vehicles as &$v) {
        if ($v['ref_id'] === $ref_id) {
            $v['gallery'] = array_values(array_filter($v['gallery'], function ($g) use ($img_path) {
                return $g !== $img_path;
            }));
            $vehicle = $v;
            break;
        }
    }
    unset($v);
    saveVehicles($data);
    // ファイル削除
    $full_path = FRONTEND_DIR . '/' . ltrim($img_path, '/');
    if (file_exists($full_path)) @unlink($full_path);
    header('Location: edit.php?ref=' . urlencode($ref_id) . '&img_deleted=1');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $is_edit ? '車両編集' : '新規車両追加' ?> - Gloria Trading Admin</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f2f5; color: #333; }
.topbar { background: #1a1a2e; color: #fff; padding: 0.9rem 1.5rem; display: flex; align-items: center; justify-content: space-between; }
.topbar .brand { font-size: 1.1rem; font-weight: 700; color: #4da6ff; }
.topbar .nav a { color: #ccc; text-decoration: none; font-size: 0.9rem; margin-left: 1.2rem; }
.topbar .nav a:hover { color: #fff; }
.container { max-width: 900px; margin: 0 auto; padding: 1.5rem; }
.page-header { display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem; }
.page-header h1 { font-size: 1.4rem; color: #1a1a2e; }
.btn { display: inline-block; padding: 0.6rem 1.2rem; border-radius: 6px; font-size: 0.9rem; font-weight: 600; text-decoration: none; cursor: pointer; border: none; transition: all 0.2s; }
.btn-primary { background: #0066cc; color: #fff; }
.btn-primary:hover { background: #0052a3; }
.btn-secondary { background: #6c757d; color: #fff; }
.btn-secondary:hover { background: #5a6268; }
.btn-preview { background: #6f42c1; color: #fff; }
.btn-preview:hover { background: #59359a; }
.btn-danger { background: #dc3545; color: #fff; font-size: 0.8rem; padding: 0.3rem 0.7rem; }
.card { background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.07); margin-bottom: 1.5rem; overflow: hidden; }
.card-header { padding: 1rem 1.5rem; border-bottom: 1px solid #eee; font-weight: 600; color: #1a1a2e; background: #fafafa; font-size: 1rem; }
.card-body { padding: 1.5rem; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.form-group { margin-bottom: 1rem; }
.form-group.full { grid-column: 1 / -1; }
label { display: block; font-size: 0.85rem; font-weight: 600; color: #444; margin-bottom: 0.4rem; }
label .req { color: #dc3545; margin-left: 2px; }
input[type=text], input[type=number], select, textarea {
  width: 100%; padding: 0.65rem 0.9rem; border: 1px solid #ddd; border-radius: 6px;
  font-size: 0.95rem; outline: none; transition: border-color 0.2s; background: #fff;
}
input:focus, select:focus, textarea:focus { border-color: #0066cc; box-shadow: 0 0 0 3px rgba(0,102,204,0.1); }
textarea { resize: vertical; min-height: 80px; }
.hint { font-size: 0.78rem; color: #888; margin-top: 0.3rem; }
.errors { background: #fff0f0; border: 1px solid #ffcccc; color: #cc0000; padding: 1rem 1.2rem; border-radius: 6px; margin-bottom: 1rem; }
.errors ul { margin-left: 1.2rem; }
.alert-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.9rem; }

/* 画像アップロードエリア */
.upload-area {
  border: 2px dashed #ccc; border-radius: 8px; padding: 2rem; text-align: center;
  cursor: pointer; transition: all 0.2s; background: #fafafa; position: relative;
}
.upload-area:hover, .upload-area.dragover { border-color: #0066cc; background: #f0f7ff; }
.upload-area input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
.upload-area .icon { font-size: 2.5rem; margin-bottom: 0.5rem; }
.upload-area p { color: #666; font-size: 0.9rem; }
.upload-area .sub { font-size: 0.8rem; color: #999; margin-top: 0.3rem; }

/* 画像ギャラリー */
.gallery-grid { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 1rem; }
.gallery-item { position: relative; width: 130px; }
.gallery-item img { width: 130px; height: 95px; object-fit: cover; border-radius: 6px; border: 2px solid #eee; display: block; }
.gallery-item .badge { position: absolute; top: 4px; left: 4px; background: #0066cc; color: #fff; font-size: 0.65rem; padding: 2px 6px; border-radius: 3px; }
.gallery-item .del-btn { position: absolute; top: 4px; right: 4px; background: rgba(220,53,69,0.9); color: #fff; border: none; border-radius: 3px; padding: 2px 6px; font-size: 0.7rem; cursor: pointer; }
.gallery-item .del-btn:hover { background: #c82333; }
.gallery-item .move-btn { position: absolute; bottom: 4px; left: 4px; background: rgba(0,0,0,0.5); color: #fff; border: none; border-radius: 3px; padding: 2px 5px; font-size: 0.7rem; cursor: pointer; }

.preview-list { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.75rem; }
.preview-item { width: 100px; height: 75px; object-fit: cover; border-radius: 4px; border: 2px solid #0066cc; }

.form-actions { display: flex; flex-wrap: wrap; gap: 0.75rem; justify-content: flex-end; padding-top: 1rem; border-top: 1px solid #eee; }
.btn-quote { background: #6f42c1; color: #fff; }
.btn-quote:hover { background: #59359a; }
.btn-site { background: #198754; color: #fff; }
.btn-site:hover { background: #146c43; }
.save-mode-note { background: #fff8e1; border: 1px solid #ffe08a; color: #5f4700; border-radius: 8px; padding: 1rem 1.2rem; margin-bottom: 1rem; font-size: 0.9rem; line-height: 1.65; }
.save-mode-note strong { color: #3d2d00; }
.save-mode-note table { width: 100%; border-collapse: collapse; margin-top: 0.75rem; background: #fff; }
.save-mode-note th, .save-mode-note td { border: 1px solid #f1d36d; padding: 0.45rem 0.55rem; text-align: left; vertical-align: top; }
.save-mode-note th { background: #fff3c4; }
.market-options { display: flex; flex-wrap: wrap; gap: 0.55rem 1rem; margin-bottom: 0.65rem; }
.market-options label { display: inline-flex; align-items: center; gap: 0.35rem; margin: 0; font-size: 0.85rem; font-weight: 500; color: #444; }
.b2b-note { background: #f0f7ff; border: 1px solid #cfe5ff; color: #214c78; border-radius: 8px; padding: 0.8rem 1rem; margin-bottom: 1rem; font-size: 0.86rem; line-height: 1.6; }

@media (max-width: 640px) {
  .form-grid { grid-template-columns: 1fr; }
  .gallery-item { width: 100px; }
  .gallery-item img { width: 100px; height: 75px; }
}
</style>
</head>
<body>
<div class="topbar">
  <div class="brand">Gloria Trading 管理画面</div>
  <div class="nav">
    <a href="index.php">← 車両一覧</a>
    <a href="uploads.php">汎用アップロード</a>
    <a href="../index.html" target="_blank">サイトを見る</a>
    <a href="index.php?logout=1">ログアウト</a>
  </div>
</div>

<div class="container">
  <div class="page-header">
    <h1><?= $is_edit ? '車両データ編集' : '新規車両を追加' ?></h1>
    <?php if ($is_edit): ?>
    <span style="background:#e8f0fe;color:#1a56db;padding:0.3rem 0.8rem;border-radius:4px;font-size:0.85rem;font-weight:600;"><?= htmlspecialchars($ref_id) ?></span>
    <a href="quote-preview.php?ref=<?= urlencode($ref_id) ?>" class="btn btn-preview">見積書プレビュー</a>
    <a href="quote-pdf.php?type=quotation&ref=<?= urlencode($ref_id) ?>" class="btn btn-preview" target="_blank">QUOTATION PDF</a>
    <a href="quote-pdf.php?type=commercial_invoice&ref=<?= urlencode($ref_id) ?>" class="btn btn-preview" target="_blank">Commercial Invoice</a>
    <a href="quote-pdf.php?type=packing_list&ref=<?= urlencode($ref_id) ?>" class="btn btn-preview" target="_blank">Packing List</a>
    <?php endif; ?>
  </div>

  <?php if (!empty($errors)): ?>
  <div class="errors">
    <strong>入力エラー：</strong>
    <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
  </div>
  <?php endif; ?>
  <?php if (isset($_GET['img_deleted'])): ?>
  <div class="alert-success">画像を削除しました。</div>
  <?php endif; ?>
  <?php if (isset($_GET['quote_file_deleted'])): ?>
  <div class="alert-success">見積もり用ファイルを削除しました。</div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" id="vehicle-form">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="gallery_json" id="gallery_json" value="<?= htmlspecialchars(json_encode($vehicle['gallery'])) ?>">
    <input type="hidden" name="quote_spec_files_json" value="<?= htmlspecialchars(json_encode($vehicle['quote_spec_files'] ?? [])) ?>">
    <input type="hidden" name="quote_image_files_json" value="<?= htmlspecialchars(json_encode($vehicle['quote_image_files'] ?? [])) ?>">
    <input type="hidden" name="vehicle_certificate_files_json" value="<?= htmlspecialchars(json_encode($vehicle['vehicle_certificate_files'] ?? [])) ?>">

    <!-- 基本情報 -->
    <div class="card">
      <div class="card-header">基本情報</div>
      <div class="card-body">
        <div class="form-grid">
          <div class="form-group">
            <label>Ref ID <span class="req">*</span></label>
            <input type="text" name="ref_id" value="<?= htmlspecialchars($vehicle['ref_id']) ?>"
              placeholder="例: REF-006" <?= $is_edit ? 'readonly style="background:#f5f5f5;"' : '' ?>>
            <div class="hint">例: REF-001, REF-002 （一度登録すると変更できません）</div>
          </div>
          <div class="form-group">
            <label>車両名（英語）<span class="req">*</span></label>
            <input type="text" name="display_name_en" value="<?= htmlspecialchars($vehicle['display_name_en']) ?>" placeholder="例: Toyota Hiace 2020">
          </div>
          <div class="form-group">
            <label>メーカー（Make）</label>
            <input type="text" name="make" value="<?= htmlspecialchars($vehicle['make']) ?>" placeholder="例: Toyota">
          </div>
          <div class="form-group">
            <label>モデル（Model）</label>
            <input type="text" name="model" value="<?= htmlspecialchars($vehicle['model']) ?>" placeholder="例: Hiace">
          </div>
          <div class="form-group">
            <label>年式（Year）<span class="req">*</span></label>
            <input type="number" name="year" value="<?= htmlspecialchars($vehicle['year']) ?>" placeholder="例: 2020" min="1990" max="2030">
          </div>
          <div class="form-group">
            <label>ボディタイプ（Body Type）</label>
            <select name="body_type">
              <?php
                $body_options = ['Van','Sedan','Hatchback','SUV','Pickup','Wagon','Minivan','Truck','Bus','Other'];
                $current_body = gt_normalize_body_type($vehicle['body_type'] ?? '');
              ?>
              <option value="">-- Select --</option>
              <?php foreach ($body_options as $bt): ?>
              <option value="<?= $bt ?>" <?= ($current_body === $bt) ? 'selected' : '' ?>><?= $bt ?></option>
              <?php endforeach; ?>
            </select>
            <div class="hint">「Hatchback / Compact」「Station Wagon」などは保存時に選択肢の表記へ統一されます。</div>
          </div>
        </div>
      </div>
    </div>

    <!-- スペック -->
    <div class="card">
      <div class="card-header">スペック</div>
      <div class="card-body">
        <div class="form-grid">
          <div class="form-group">
            <label>燃料タイプ（Fuel Type）</label>
            <select name="fuel_type">
              <?php
                $current_fuel = gt_normalize_fuel_type($vehicle['fuel_type'] ?? '');
              ?>
              <option value="">-- Select --</option>
              <?php foreach (['Diesel','Petrol','Hybrid','Electric','LPG'] as $ft): ?>
              <option value="<?= $ft ?>" <?= ($current_fuel === $ft) ? 'selected' : '' ?>><?= $ft ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>ミッション（Transmission）</label>
            <select name="transmission">
              <option value="">-- Select --</option>
              <?php
                $current_transmission = gt_normalize_transmission($vehicle['transmission'] ?? '');
                foreach (['Manual','Automatic','CVT'] as $tr):
              ?>
              <option value="<?= $tr ?>" <?= ($current_transmission === $tr) ? 'selected' : '' ?>><?= $tr ?></option>
              <?php endforeach; ?>
            </select>
            <div class="hint">CSVの AT / MT は保存時に Automatic / Manual へ統一されます。未選択のまま保存しても既存値は維持されます。</div>
          </div>
          <div class="form-group">
            <label>走行距離 km（Mileage）</label>
            <input type="number" name="mileage_km" value="<?= htmlspecialchars($vehicle['mileage_km']) ?>" placeholder="例: 45000" min="0">
          </div>
          <div class="form-group">
            <label>排気量 cc（Engine CC）</label>
            <input type="number" name="engine_cc" value="<?= htmlspecialchars($vehicle['engine_cc'] ?? '') ?>" placeholder="例: 1300" min="0">
          </div>
        </div>
      </div>
    </div>

    <!-- 価格 -->
    <div class="card">
      <div class="card-header">参考価格・参考情報</div>
      <div class="card-body">
        <div class="form-grid">
          <div class="form-group">
            <label>FOB価格 USD（FOB Price）</label>
            <input type="number" name="reference_price_usd" value="<?= htmlspecialchars($vehicle['reference_price_usd']) ?>" placeholder="例: 12000" min="0">
            <div class="hint">車両詳細ページでは「FOB Price」として表示されます。価格算定項目から見積用価格を調整できます。</div>
          </div>
          <input type="hidden" name="basis_from" value="<?= htmlspecialchars($vehicle['basis_from'] ?? '') ?>">
          <input type="hidden" name="basis_to" value="<?= htmlspecialchars($vehicle['basis_to'] ?? '') ?>">
          <div class="form-group full">
            <label>免責事項（Disclaimer）</label>
            <textarea name="disclaimer_short"><?= htmlspecialchars($vehicle['disclaimer_short']) ?></textarea>
          </div>
        </div>
      </div>
    </div>


    <!-- B2B販売ガイダンス -->
    <div class="card">
      <div class="card-header">B2B販売ガイダンス（サイト表示用）</div>
      <div class="card-body">
        <div class="b2b-note">
          西アフリカの業者向けに、どの国・用途・類似車両・複数台相談に向いているかを表示します。ここで入力した内容は、カタログ一覧と車両詳細ページに反映されます。
        </div>
        <?php
          $best_for_resale_current = (string)($vehicle['best_for_resale_in'] ?? '');
          $market_options = ['Ghana', 'Nigeria', 'Benin', "Côte d'Ivoire"];
          $market_extra = trim(str_replace($market_options, '', str_replace([' / ', '/'], ' ', $best_for_resale_current)));
          $market_extra = trim(preg_replace('/\s+/', ' ', $market_extra));
          $typical_current = (string)($vehicle['typical_buyer_use'] ?? '');
          $similar_default = trim(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? ''));
          if ($similar_default !== '') $similar_default = $similar_default . ' / comparable Japanese used units';
        ?>
        <div class="form-grid">
          <div class="form-group full">
            <label>Best for resale in</label>
            <div class="market-options">
              <?php foreach ($market_options as $market): ?>
              <label><input type="checkbox" name="best_for_resale_markets[]" value="<?= htmlspecialchars($market) ?>" <?= ($best_for_resale_current === '' || strpos($best_for_resale_current, $market) !== false) ? 'checked' : '' ?>> <?= htmlspecialchars($market) ?></label>
              <?php endforeach; ?>
            </div>
            <input type="text" name="best_for_resale_extra" value="<?= htmlspecialchars($market_extra) ?>" placeholder="必要に応じて追加市場を入力">
            <div class="hint">例: Ghana / Nigeria / Benin / Côte d'Ivoire</div>
          </div>
          <div class="form-group full">
            <label>Typical buyer use</label>
            <select name="typical_buyer_use_preset" id="typical-buyer-preset">
              <option value="">プリセットを選択して本文に反映</option>
              <option value="Taxi and ride-hailing use, small business fleet, and family transportation.">Taxi / ride-hailing / family</option>
              <option value="Commercial delivery, spare parts resale, and small business logistics.">Commercial delivery / logistics</option>
              <option value="School, hotel, company shuttle, and group transport business.">Shuttle / group transport</option>
              <option value="Dealer resale stock for repeat buyers seeking reliable Japanese used vehicles.">Dealer resale stock</option>
            </select>
            <textarea name="typical_buyer_use" id="typical-buyer-use" placeholder="例: Taxi and ride-hailing use, small business fleet, and family transportation."><?= htmlspecialchars($typical_current) ?></textarea>
          </div>
          <div class="form-group">
            <label>Similar units</label>
            <input type="text" name="similar_units" value="<?= htmlspecialchars($vehicle['similar_units'] ?? $similar_default) ?>" placeholder="例: Toyota Vitz / Passo / Fit class units">
          </div>
          <div class="form-group">
            <label>Bulk / repeat order</label>
            <input type="text" name="bulk_repeat_order" value="<?= htmlspecialchars($vehicle['bulk_repeat_order'] ?? 'Dealer RFQ supported') ?>" placeholder="Dealer RFQ supported">
          </div>
        </div>
      </div>
    </div>


    <?php $quote_form = $vehicle['quote_spec_data'] ?? gt_quote_spec_data_value($vehicle); ?>
    <!-- 見積書専用データ -->
    <div class="card">
      <div class="card-header">見積書専用データ（必要に応じて加工）</div>
      <div class="card-body">
        <p style="font-size:0.9rem;color:#555;margin-bottom:1rem;">車両名・年式・参考価格などの基本情報は上の車両データを元に使用します。ここでは、顧客情報、輸出先、費用、利益、販売価格など見積書だけで使う項目を調整できます。</p>
        <div class="form-grid">
          <div class="form-group"><label>見積番号</label><input type="text" name="quote_invoice_no" value="<?= htmlspecialchars($quote_form['invoice_no'] ?? '') ?>" placeholder="未入力ならPDFで自動採番"></div>
          <div class="form-group"><label>見積日</label><input type="date" name="quote_invoice_date" value="<?= htmlspecialchars($quote_form['invoice_date'] ?? '') ?>"></div>
          <div class="form-group"><label>顧客名 / 会社名</label><input type="text" name="quote_customer_name" value="<?= htmlspecialchars($quote_form['customer_name'] ?? '') ?>"></div>
          <div class="form-group"><label>担当者名</label><input type="text" name="quote_customer_attn" value="<?= htmlspecialchars($quote_form['customer_attn'] ?? '') ?>"></div>
          <div class="form-group"><label>電話番号</label><input type="text" name="quote_customer_tel" value="<?= htmlspecialchars($quote_form['customer_tel'] ?? '') ?>"></div>
          <div class="form-group"><label>メールアドレス</label><input type="email" name="quote_customer_email" value="<?= htmlspecialchars($quote_form['customer_email'] ?? '') ?>"></div>
          <div class="form-group full"><label>顧客住所</label><textarea name="quote_customer_address"><?= htmlspecialchars($quote_form['customer_address'] ?? '') ?></textarea></div>
          <div class="form-group"><label>輸出先国</label><input type="text" name="quote_destination_country" value="<?= htmlspecialchars($quote_form['destination_country'] ?? '') ?>" placeholder="例: Ghana"></div>
          <div class="form-group"><label>積出港</label><input type="text" name="quote_port_of_loading" value="<?= htmlspecialchars($quote_form['port_of_loading'] ?? 'Sendai Port, Japan') ?>"></div>
          <div class="form-group"><label>荷揚港</label><input type="text" name="quote_port_of_discharge" value="<?= htmlspecialchars($quote_form['port_of_discharge'] ?? '') ?>"></div>
          <div class="form-group"><label>インコタームズ</label><input type="text" name="quote_incoterms" value="<?= htmlspecialchars($quote_form['incoterms'] ?? 'CIF') ?>"></div>
          <div class="form-group"><label>支払条件</label><input type="text" name="quote_payment_terms" value="<?= htmlspecialchars($quote_form['payment_terms'] ?? 'T/T in advance') ?>"></div>
          <div class="form-group"><label>通貨</label><input type="text" name="quote_currency" value="<?= htmlspecialchars($quote_form['currency'] ?? 'USD') ?>"></div>
          <div class="form-group"><label>車台番号</label><input type="text" name="quote_chassis_no" value="<?= htmlspecialchars($quote_form['chassis_no'] ?? '') ?>"></div>
          <div class="form-group"><label>排気量 cc</label><input type="number" name="quote_engine_cc" value="<?= htmlspecialchars($quote_form['engine_cc'] ?? ($vehicle['engine_cc'] ?? '')) ?>" min="0"></div>
          <div class="form-group"><label>オークション落札価格 JPY</label><input type="number" name="quote_auction_price_jpy" value="<?= htmlspecialchars($quote_form['auction_price_jpy'] ?? 0) ?>" min="0"><div class="hint">価格算定の元データです。</div></div>
          <div class="form-group"><label>為替レート JPY/USD</label><input type="number" name="quote_exchange_rate_jpy_usd" value="<?= htmlspecialchars($quote_form['exchange_rate_jpy_usd'] ?? 0) ?>" min="0"><div class="hint">例: 155。落札価格をUSD換算するために使います。</div></div>
          <div class="form-group"><label>仕入価格 USD</label><input type="number" name="quote_purchase_price_usd" value="<?= htmlspecialchars($quote_form['purchase_price_usd'] ?? 0) ?>" min="0"><div class="hint">未入力の場合、落札価格 ÷ 為替レートで自動計算します。</div></div>
          <div class="form-group"><label>陸送費 USD</label><input type="number" name="quote_land_transport_usd" value="<?= htmlspecialchars($quote_form['land_transport_usd'] ?? 0) ?>" min="0"></div>
          <div class="form-group"><label>船賃 USD</label><input type="number" name="quote_ocean_freight_usd" value="<?= htmlspecialchars($quote_form['ocean_freight_usd'] ?? 0) ?>" min="0"></div>
          <div class="form-group"><label>保険料 USD</label><input type="number" name="quote_insurance_usd" value="<?= htmlspecialchars($quote_form['insurance_usd'] ?? 0) ?>" min="0"></div>
          <div class="form-group"><label>検査費 USD</label><input type="number" name="quote_inspection_fee_usd" value="<?= htmlspecialchars($quote_form['inspection_fee_usd'] ?? 0) ?>" min="0"></div>
          <div class="form-group"><label>その他費用 USD</label><input type="number" name="quote_other_cost_usd" value="<?= htmlspecialchars($quote_form['other_cost_usd'] ?? 0) ?>" min="0"></div>
          <div class="form-group"><label>利益 USD</label><input type="number" name="quote_profit_usd" value="<?= htmlspecialchars($quote_form['profit_usd'] ?? 0) ?>" min="0"></div>
          <div class="form-group"><label>FOB販売価格 / 目標価格 USD</label><input type="number" name="quote_target_price_usd" value="<?= htmlspecialchars($quote_form['target_price_usd'] ?? $vehicle['reference_price_usd']) ?>" min="0"><div class="hint">未入力の場合、仕入価格・陸送費・検査費・その他費用・利益から自動算定します。CIFの場合はPDF側で船賃と保険料を加算表示します。</div></div>
          <div class="form-group"><label>見積有効期限</label><input type="date" name="quote_quote_valid_until" value="<?= htmlspecialchars($quote_form['quote_valid_until'] ?? '') ?>"></div>
          <div class="form-group full"><label>見積メモ</label><textarea name="quote_quote_memo"><?= htmlspecialchars($quote_form['quote_memo'] ?? '') ?></textarea></div>
        </div>
      </div>
    </div>


    <?php
      $export_doc_form = $vehicle['export_document_data'] ?? gt_export_document_data_value($vehicle);
      $cert_form = $vehicle['vehicle_certificate_data'] ?? gt_vehicle_certificate_data_value($vehicle);
    ?>
    <!-- 輸出書類用データ -->
    <div class="card">
      <div class="card-header">輸出書類用データ（Commercial Invoice / Packing List）</div>
      <div class="card-body">
        <p style="font-size:0.9rem;color:#555;margin-bottom:1rem;">Commercial Invoice と Packing List では、Buyer とは別に Consignee / Notify Party を管理します。未入力の番号・日付はPDF側で自動補完します。</p>
        <div class="form-grid">
          <div class="form-group"><label>Commercial Invoice No.</label><input type="text" name="export_commercial_invoice_no" value="<?= htmlspecialchars($export_doc_form['commercial_invoice_no'] ?? '') ?>" placeholder="未入力ならCIで自動採番"></div>
          <div class="form-group"><label>Commercial Invoice Date</label><input type="date" name="export_commercial_invoice_date" value="<?= htmlspecialchars($export_doc_form['commercial_invoice_date'] ?? '') ?>"></div>
          <div class="form-group"><label>Packing List No.</label><input type="text" name="export_packing_list_no" value="<?= htmlspecialchars($export_doc_form['packing_list_no'] ?? '') ?>" placeholder="未入力ならPLで自動採番"></div>
          <div class="form-group"><label>Packing List Date</label><input type="date" name="export_packing_list_date" value="<?= htmlspecialchars($export_doc_form['packing_list_date'] ?? '') ?>"></div>
          <div class="form-group full"><label>Consignee Name</label><input type="text" name="export_consignee_name" value="<?= htmlspecialchars($export_doc_form['consignee_name'] ?? '') ?>"></div>
          <div class="form-group full"><label>Consignee Address</label><textarea name="export_consignee_address"><?= htmlspecialchars($export_doc_form['consignee_address'] ?? '') ?></textarea></div>
          <div class="form-group"><label>Consignee Tel</label><input type="text" name="export_consignee_tel" value="<?= htmlspecialchars($export_doc_form['consignee_tel'] ?? '') ?>"></div>
          <div class="form-group"><label>Consignee Email</label><input type="email" name="export_consignee_email" value="<?= htmlspecialchars($export_doc_form['consignee_email'] ?? '') ?>"></div>
          <div class="form-group full"><label>Notify Party Name</label><input type="text" name="export_notify_name" value="<?= htmlspecialchars($export_doc_form['notify_name'] ?? '') ?>"></div>
          <div class="form-group full"><label>Notify Party Address</label><textarea name="export_notify_address"><?= htmlspecialchars($export_doc_form['notify_address'] ?? '') ?></textarea></div>
          <div class="form-group"><label>Notify Party Tel</label><input type="text" name="export_notify_tel" value="<?= htmlspecialchars($export_doc_form['notify_tel'] ?? '') ?>"></div>
          <div class="form-group"><label>Notify Party Email</label><input type="email" name="export_notify_email" value="<?= htmlspecialchars($export_doc_form['notify_email'] ?? '') ?>"></div>
          <div class="form-group"><label>Vessel Name</label><input type="text" name="export_vessel_name" value="<?= htmlspecialchars($export_doc_form['vessel_name'] ?? '') ?>"></div>
          <div class="form-group"><label>Voyage No.</label><input type="text" name="export_voyage_no" value="<?= htmlspecialchars($export_doc_form['voyage_no'] ?? '') ?>"></div>
          <div class="form-group"><label>Booking No.</label><input type="text" name="export_booking_no" value="<?= htmlspecialchars($export_doc_form['booking_no'] ?? '') ?>"></div>
          <div class="form-group"><label>B/L No.</label><input type="text" name="export_bl_no" value="<?= htmlspecialchars($export_doc_form['bl_no'] ?? '') ?>"></div>
          <div class="form-group"><label>Final Destination</label><input type="text" name="export_final_destination" value="<?= htmlspecialchars($export_doc_form['final_destination'] ?? '') ?>"></div>
          <div class="form-group"><label>Marks & Numbers</label><input type="text" name="export_marks_and_numbers" value="<?= htmlspecialchars($export_doc_form['marks_and_numbers'] ?? '') ?>" placeholder="例: AS PER INVOICE"></div>
          <div class="form-group full"><label>Shipping Memo</label><textarea name="export_shipping_memo"><?= htmlspecialchars($export_doc_form['shipping_memo'] ?? '') ?></textarea></div>
        </div>
      </div>
    </div>

    <!-- 車検証データ -->
    <div class="card">
      <div class="card-header">車検証データ取り込み（Invoice / Packing List 用）</div>
      <div class="card-body">
        <p style="font-size:0.9rem;color:#555;margin-bottom:1rem;">車検証PDF・画像を保存し、抽出済みテキストまたは手入力値を確認してから帳票に反映します。添付サンプルに合わせ、車台番号・型式・原動機型式・排気量・寸法・重量を中心に管理します。</p>
        <div class="form-grid">
          <div class="form-group full"><label>車検証PDF / 画像</label><input type="file" name="vehicle_certificate_files[]" multiple accept=".pdf,image/*"><div class="hint">PDF / JPG / PNG / WebP に対応。画像OCR結果は下のテキスト欄へ貼り付け、必要項目を確認・修正してください。</div></div>
          <div class="form-group"><label>登録番号</label><input type="text" name="cert_registration_no" value="<?= htmlspecialchars($cert_form['registration_no'] ?? '') ?>" placeholder="例: 宮城 503 の 9481"></div>
          <div class="form-group"><label>登録年月日</label><input type="text" name="cert_registration_date" value="<?= htmlspecialchars($cert_form['registration_date'] ?? '') ?>" placeholder="例: 令和6年4月2日"></div>
          <div class="form-group"><label>初度登録年月</label><input type="text" name="cert_first_registration" value="<?= htmlspecialchars($cert_form['first_registration'] ?? '') ?>" placeholder="例: 平成21年4月"></div>
          <div class="form-group"><label>自動車の種別</label><input type="text" name="cert_vehicle_type" value="<?= htmlspecialchars($cert_form['vehicle_type'] ?? '') ?>" placeholder="例: 小型"></div>
          <div class="form-group"><label>用途</label><input type="text" name="cert_use" value="<?= htmlspecialchars($cert_form['use'] ?? '') ?>" placeholder="例: 乗用"></div>
          <div class="form-group"><label>自家用・事業用</label><input type="text" name="cert_private_business" value="<?= htmlspecialchars($cert_form['private_business'] ?? '') ?>" placeholder="例: 自家用"></div>
          <div class="form-group"><label>車体の形状</label><input type="text" name="cert_body_shape" value="<?= htmlspecialchars($cert_form['body_shape'] ?? '') ?>" placeholder="例: 箱型"></div>
          <div class="form-group"><label>メーカー（日本語）</label><input type="text" name="cert_make_jp" value="<?= htmlspecialchars($cert_form['make_jp'] ?? '') ?>" placeholder="例: トヨタ"></div>
          <div class="form-group"><label>Make（英語）</label><input type="text" name="cert_make_en" value="<?= htmlspecialchars($cert_form['make_en'] ?? '') ?>" placeholder="例: TOYOTA"></div>
          <div class="form-group"><label>型式</label><input type="text" name="cert_model_code" value="<?= htmlspecialchars($cert_form['model_code'] ?? '') ?>" placeholder="例: DBA-KSP90"></div>
          <div class="form-group"><label>車台番号</label><input type="text" name="cert_chassis_no" value="<?= htmlspecialchars($cert_form['chassis_no'] ?? '') ?>" placeholder="例: KSP90-5147139"></div>
          <div class="form-group"><label>原動機の型式</label><input type="text" name="cert_engine_model" value="<?= htmlspecialchars($cert_form['engine_model'] ?? '') ?>" placeholder="例: 1KR"></div>
          <div class="form-group"><label>燃料</label><input type="text" name="cert_fuel" value="<?= htmlspecialchars($cert_form['fuel'] ?? '') ?>" placeholder="例: ガソリン"></div>
          <div class="form-group"><label>排気量 L</label><input type="text" name="cert_displacement_l" value="<?= htmlspecialchars($cert_form['displacement_l'] ?? '') ?>" placeholder="例: 0.99"></div>
          <div class="form-group"><label>排気量 cc</label><input type="text" name="cert_engine_cc" value="<?= htmlspecialchars($cert_form['engine_cc'] ?? '') ?>" placeholder="例: 990"></div>
          <div class="form-group"><label>乗車定員</label><input type="text" name="cert_capacity" value="<?= htmlspecialchars($cert_form['capacity'] ?? '') ?>" placeholder="例: 5"></div>
          <div class="form-group"><label>最大積載量 kg</label><input type="text" name="cert_max_load_kg" value="<?= htmlspecialchars($cert_form['max_load_kg'] ?? '') ?>"></div>
          <div class="form-group"><label>車両重量 kg</label><input type="text" name="cert_vehicle_weight_kg" value="<?= htmlspecialchars($cert_form['vehicle_weight_kg'] ?? '') ?>" placeholder="例: 990"></div>
          <div class="form-group"><label>車両総重量 kg</label><input type="text" name="cert_gross_weight_kg" value="<?= htmlspecialchars($cert_form['gross_weight_kg'] ?? '') ?>" placeholder="例: 1265"></div>
          <div class="form-group"><label>長さ cm</label><input type="text" name="cert_length_cm" value="<?= htmlspecialchars($cert_form['length_cm'] ?? '') ?>" placeholder="例: 378"></div>
          <div class="form-group"><label>幅 cm</label><input type="text" name="cert_width_cm" value="<?= htmlspecialchars($cert_form['width_cm'] ?? '') ?>" placeholder="例: 169"></div>
          <div class="form-group"><label>高さ cm</label><input type="text" name="cert_height_cm" value="<?= htmlspecialchars($cert_form['height_cm'] ?? '') ?>" placeholder="例: 152"></div>
          <div class="form-group"><label>所有者名</label><input type="text" name="cert_owner_name" value="<?= htmlspecialchars($cert_form['owner_name'] ?? '') ?>"></div>
          <div class="form-group full"><label>備考</label><textarea name="cert_remarks"><?= htmlspecialchars($cert_form['remarks'] ?? '') ?></textarea></div>
          <div class="form-group full"><label>車検証OCRテキスト / メモ</label><textarea name="cert_ocr_text" placeholder="OCR結果や読み取りメモを保存できます。現在はPDF/画像ファイル保存と、確認済み項目の手入力・保存を安全運用の基本にします。\n例: トヨタ / DBA-KSP90 / KSP90-5147139 / 1KR / 0.99L / 990kg / 1265kg / 378 x 169 x 152 cm"><?= htmlspecialchars($cert_form['ocr_text'] ?? '') ?></textarea></div>
        </div>
        <?php if (!empty($vehicle['vehicle_certificate_files'])): ?>
        <div style="margin-top:1rem;">
          <h4 style="font-size:0.9rem;margin-bottom:0.5rem;">登録済み車検証ファイル</h4>
          <?php foreach (($vehicle['vehicle_certificate_files'] ?? []) as $file): ?>
          <div style="font-size:0.85rem;margin-bottom:0.4rem;display:flex;gap:0.5rem;align-items:center;">
            <a href="<?= htmlspecialchars('../' . $file) ?>" target="_blank"><?= htmlspecialchars(basename($file)) ?></a>
            <?php if ($is_edit): ?><button type="button" class="btn btn-danger" onclick="deleteQuoteFile('<?= htmlspecialchars($file, ENT_QUOTES) ?>','certificate')">削除</button><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- 見積兼サイト用マスターCSV -->
    <div class="card">
      <div class="card-header">見積兼サイト用マスターCSVアップロード</div>
      <div class="card-body">
        <p style="font-size:0.9rem;color:#555;margin-bottom:1rem;">1つのマスターCSVから、サイト表示用データと見積書作成用データを保存ボタンごとに振り分けて反映できます。</p>
        <div class="form-group full">
          <label>見積兼サイト用マスターCSV</label>
          <input type="file" name="site_data_csv" accept=".csv,text/csv">
          <div class="hint">既存のサイト表示用14列に加え、仕入価格・陸送費・船賃・検査費・その他費用・利益・販売目標価格・輸出先国・顧客名・顧客連絡先・見積有効期限・見積メモに対応。押した保存ボタンに応じて反映範囲を切り替えます。</div>
        </div>
      </div>
    </div>

    <!-- 見積もり用ファイル -->
    <div class="card">
      <div class="card-header">見積もり用スペック・画像</div>
      <div class="card-body">
        <p style="font-size:0.9rem;color:#555;margin-bottom:1rem;">見積もり作成時に使う仕様書、検査表、オークションシート、参考画像などを車両ごとに保存できます。公開カタログのメイン画像とは別管理です。</p>
        <div class="form-grid">
          <div class="form-group">
            <label>見積もり用スペックファイル</label>
            <input type="file" name="quote_spec_files[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,image/*">
            <div class="hint">PDF / Word / Excel / CSV / 画像に対応。複数選択できます。</div>
          </div>
          <div class="form-group">
            <label>見積もり用画像</label>
            <input type="file" name="quote_image_files[]" multiple accept="image/*">
            <div class="hint">カタログ画像とは別に、見積もり用の参考画像を保存します。</div>
          </div>
        </div>
        <?php if (!empty($vehicle['quote_spec_files']) || !empty($vehicle['quote_image_files'])): ?>
        <div style="margin-top:1rem;display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
          <div>
            <h4 style="font-size:0.9rem;margin-bottom:0.5rem;">登録済みスペック</h4>
            <?php foreach (($vehicle['quote_spec_files'] ?? []) as $file): ?>
            <div style="font-size:0.85rem;margin-bottom:0.4rem;display:flex;gap:0.5rem;align-items:center;">
              <a href="<?= htmlspecialchars('../' . $file) ?>" target="_blank"><?= htmlspecialchars(basename($file)) ?></a>
              <?php if ($is_edit): ?><button type="button" class="btn btn-danger" onclick="deleteQuoteFile('<?= htmlspecialchars($file, ENT_QUOTES) ?>','spec')">削除</button><?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <div>
            <h4 style="font-size:0.9rem;margin-bottom:0.5rem;">登録済み見積もり用画像</h4>
            <div class="gallery-grid">
              <?php foreach (($vehicle['quote_image_files'] ?? []) as $file): ?>
              <div class="gallery-item">
                <img src="<?= htmlspecialchars('../' . $file) ?>" alt="">
                <?php if ($is_edit): ?><button type="button" class="del-btn" onclick="deleteQuoteFile('<?= htmlspecialchars($file, ENT_QUOTES) ?>','image')">✕</button><?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- 画像管理 -->
    <div class="card">
      <div class="card-header">サイト表示用画像（画像管理）</div>
      <div class="card-body">

        <?php if (!empty($vehicle['gallery'])): ?>
        <p style="font-size:0.9rem;color:#555;margin-bottom:0.75rem;">登録済み画像（最初の画像がメイン画像になります）</p>
        <div class="gallery-grid" id="gallery-grid">
          <?php foreach ($vehicle['gallery'] as $i => $img_path): ?>
          <div class="gallery-item" data-path="<?= htmlspecialchars($img_path) ?>">
            <img src="<?= htmlspecialchars('../' . $img_path) ?>" alt="">
            <?php if ($i === 0): ?><span class="badge">メイン</span><?php endif; ?>
            <?php if ($is_edit): ?>
            <button type="button" class="del-btn" onclick="deleteImage('<?= htmlspecialchars($img_path, ENT_QUOTES) ?>')">✕</button>
            <?php endif; ?>
            <?php if ($i > 0): ?>
            <button type="button" class="move-btn" onclick="moveImageUp(this)">↑ 前へ</button>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div style="margin-top:1.25rem;">
          <p style="font-size:0.9rem;color:#555;margin-bottom:0.75rem;">サイト表示用の新しい画像を追加（複数選択可）</p>
          <div class="upload-area" id="upload-area">
            <input type="file" name="new_images[]" id="file-input" multiple accept="image/*">
            <div class="icon">IMAGE</div>
            <p>クリックまたはドラッグ＆ドロップで画像を追加</p>
            <p class="sub">JPEG / PNG / WebP 対応 ・ 複数枚同時アップロード可</p>
          </div>
          <div class="preview-list" id="preview-list"></div>
        </div>
      </div>
    </div>

    <div class="save-mode-note">
      <strong>保存方法を選んでください。</strong>
      <table>
        <thead><tr><th>保存ボタン</th><th>反映される内容</th><th>反映されない内容</th></tr></thead>
        <tbody>
          <tr><td>見積書作成用だけ保存</td><td>マスターCSV内の見積書作成用項目、見積もり用スペックファイル、見積もり用画像</td><td>マスターCSV内のサイト表示用項目、サイト表示用画像、画面のサイト表示項目</td></tr>
          <tr><td>サイト表示用だけ保存</td><td>マスターCSV内のサイト表示用項目、サイト表示用画像、画面のサイト表示項目</td><td>マスターCSV内の見積書作成用項目、見積もり用スペックファイル、見積もり用画像</td></tr>
          <tr><td>両方を同時保存</td><td>マスターCSV、見積書作成用ファイル、サイト表示用画像の両方</td><td>なし</td></tr>
        </tbody>
      </table>
    </div>

    <div class="form-actions">
      <a href="index.php" class="btn btn-secondary">キャンセル</a>
      <button type="submit" name="upload_mode" value="quote_only" class="btn btn-quote">見積書作成用だけ保存</button>
      <button type="submit" name="upload_mode" value="site_only" class="btn btn-site">サイト表示用だけ保存</button>
      <button type="submit" name="upload_mode" value="both" class="btn btn-primary">両方を同時保存</button>
    </div>
  </form>
</div>

<script>
// B2Bプリセット反映
const typicalPreset = document.getElementById('typical-buyer-preset');
const typicalUse = document.getElementById('typical-buyer-use');
if (typicalPreset && typicalUse) {
  typicalPreset.addEventListener('change', function() {
    if (this.value && (!typicalUse.value || confirm('Typical buyer use の本文をプリセットで置き換えますか？'))) {
      typicalUse.value = this.value;
    }
  });
}

// 画像プレビュー
document.getElementById('file-input').addEventListener('change', function() {
  const list = document.getElementById('preview-list');
  list.innerHTML = '';
  Array.from(this.files).forEach(file => {
    const reader = new FileReader();
    reader.onload = e => {
      const img = document.createElement('img');
      img.src = e.target.result;
      img.className = 'preview-item';
      img.title = file.name;
      list.appendChild(img);
    };
    reader.readAsDataURL(file);
  });
});

// ドラッグ＆ドロップ
const uploadArea = document.getElementById('upload-area');
uploadArea.addEventListener('dragover', e => { e.preventDefault(); uploadArea.classList.add('dragover'); });
uploadArea.addEventListener('dragleave', () => uploadArea.classList.remove('dragover'));
uploadArea.addEventListener('drop', e => {
  e.preventDefault();
  uploadArea.classList.remove('dragover');
  const input = document.getElementById('file-input');
  input.files = e.dataTransfer.files;
  input.dispatchEvent(new Event('change'));
});

// 画像の順序変更（↑ 前へ）
function moveImageUp(btn) {
  const item = btn.closest('.gallery-item');
  const prev = item.previousElementSibling;
  if (prev) {
    item.parentNode.insertBefore(item, prev);
    updateGalleryJson();
    updateBadges();
  }
}

function updateGalleryJson() {
  const items = document.querySelectorAll('#gallery-grid .gallery-item');
  const paths = Array.from(items).map(el => el.dataset.path);
  document.getElementById('gallery_json').value = JSON.stringify(paths);
}


function deleteQuoteFile(filePath, fileType) {
  if (!confirm('この見積もり用ファイルを削除しますか？')) return;
  const form = document.createElement('form');
  form.method = 'post';
  form.style.display = 'none';
  const actionInput = document.createElement('input');
  actionInput.name = 'action';
  actionInput.value = 'delete_quote_file';
  const pathInput = document.createElement('input');
  pathInput.name = 'file_path';
  pathInput.value = filePath;
  const typeInput = document.createElement('input');
  typeInput.name = 'file_type';
  typeInput.value = fileType;
  form.appendChild(actionInput);
  form.appendChild(pathInput);
  form.appendChild(typeInput);
  document.body.appendChild(form);
  form.submit();
}

// 画像削除（Ajaxで送信）
function deleteImage(imgPath) {
  if (!confirm('この画像を削除しますか？')) return;
  const form = document.createElement('form');
  form.method = 'post';
  form.style.display = 'none';
  const actionInput = document.createElement('input');
  actionInput.name = 'action';
  actionInput.value = 'delete_image';
  const pathInput = document.createElement('input');
  pathInput.name = 'img_path';
  pathInput.value = imgPath;
  form.appendChild(actionInput);
  form.appendChild(pathInput);
  document.body.appendChild(form);
  form.submit();
}

function updateBadges() {
  const items = document.querySelectorAll('#gallery-grid .gallery-item');
  items.forEach((el, i) => {
    const badge = el.querySelector('.badge');
    if (i === 0) {
      if (!badge) {
        const b = document.createElement('span');
        b.className = 'badge';
        b.textContent = 'メイン';
        el.appendChild(b);
      }
    } else {
      if (badge) badge.remove();
    }
    const moveBtn = el.querySelector('.move-btn');
    if (i === 0) {
      if (moveBtn) moveBtn.style.display = 'none';
    } else {
      if (moveBtn) moveBtn.style.display = '';
    }
  });
}
</script>
</body>
</html>
