<?php
/**
 * Gloria Trading vehicle data helper (unified-key edition).
 *
 * Public pages, admin screens, CSV import/export, and image assignment all use
 * the same vehicle keys. Historical keys are accepted only while loading old
 * JSON and are not saved back.
 */

if (!defined('VEHICLES_JSON')) {
    require_once __DIR__ . '/bootstrap.php';
}

function gt_int_value($value) {
    return (int)str_replace(',', '', (string)($value ?? '0'));
}

function gt_string_value($value) {
    return trim((string)($value ?? ''));
}

/**
 * Canonical transmission values for admin + site: Manual / Automatic / CVT.
 * Accepts auction/CSV shorthand such as AT, MT, FAT, IAT.
 */
function gt_normalize_transmission($value) {
    $raw = gt_string_value($value);
    if ($raw === '') return '';

    $compact = strtoupper(preg_replace('/[^A-Za-z]/', '', $raw));
    if ($compact === '') return $raw;

    if (in_array($compact, ['CVT', 'CVTF', 'IVT'], true)) {
        return 'CVT';
    }

    if (in_array($compact, ['MT', 'FMT', 'IMT', 'SMT', 'MANUAL', 'MAN'], true)
        || (strpos($compact, 'MT') !== false && strpos($compact, 'AT') === false && strpos($compact, 'CVT') === false)) {
        return 'Manual';
    }

    if (in_array($compact, ['AT', 'FAT', 'IAT', 'DAT', 'CAT', 'AAT', 'AUTOMATIC', 'AUTO'], true)
        || strpos($compact, 'AT') !== false
        || strpos($compact, 'AUTO') !== false) {
        return 'Automatic';
    }

    $lower = strtolower($raw);
    if ($lower === 'manual') return 'Manual';
    if ($lower === 'automatic') return 'Automatic';
    if ($lower === 'cvt') return 'CVT';

    return $raw;
}

function gt_gallery_value($v) {
    $images = [];
    if (!empty($v['gallery']) && is_array($v['gallery'])) {
        $images = $v['gallery'];
    } elseif (!empty($v['images']) && is_array($v['images'])) {
        $images = $v['images'];
    } elseif (!empty($v['image_main'])) {
        $images = [$v['image_main']];
    }
    return array_values(array_unique(array_filter(array_map('trim', $images))));
}

function gt_quote_spec_data_value($v) {
    $source = [];
    if (!empty($v['quote_spec_data']) && is_array($v['quote_spec_data'])) {
        $source = $v['quote_spec_data'];
    }

    $reference_price = $v['reference_price_usd'] ?? $v['price_usd'] ?? $v['price_low_usd'] ?? $v['price_high_usd'] ?? 0;

    $numeric_defaults = [
        'auction_price_jpy' => 0,
        'exchange_rate_jpy_usd' => 0,
        'purchase_price_usd' => 0,
        'land_transport_usd' => 0,
        'ocean_freight_usd' => 0,
        'inspection_fee_usd' => 0,
        'other_cost_usd' => 0,
        'profit_usd' => 0,
        'target_price_usd' => gt_int_value($reference_price),
        'insurance_usd' => 0,
    ];
    $string_defaults = [
        'invoice_no' => '',
        'invoice_date' => '',
        'destination_country' => '',
        'customer_name' => '',
        'customer_address' => '',
        'customer_tel' => '',
        'customer_email' => '',
        'customer_contact' => '',
        'customer_attn' => '',
        'port_of_loading' => 'Sendai Port, Japan',
        'port_of_discharge' => '',
        'incoterms' => 'CIF',
        'payment_terms' => 'T/T in advance',
        'currency' => 'USD',
        'chassis_no' => gt_string_value($v['chassis_no'] ?? ''),
        'engine_cc' => gt_string_value($v['engine_cc'] ?? $v['displacement_cc'] ?? ''),
        'quote_valid_until' => '',
        'quote_memo' => '',
    ];

    $normalized = [];
    foreach ($numeric_defaults as $field => $default) {
        $raw = $source[$field] ?? null;
        $normalized[$field] = ($raw === null || $raw === '') ? $default : gt_int_value($raw);
    }
    foreach ($string_defaults as $field => $default) {
        $raw = $source[$field] ?? null;
        $normalized[$field] = ($raw === null || $raw === '') ? $default : gt_string_value($raw);
    }

    if (($normalized['purchase_price_usd'] ?? 0) <= 0 && ($normalized['auction_price_jpy'] ?? 0) > 0 && ($normalized['exchange_rate_jpy_usd'] ?? 0) > 0) {
        $normalized['purchase_price_usd'] = (int)ceil($normalized['auction_price_jpy'] / $normalized['exchange_rate_jpy_usd']);
    }

    $calculated_fob = (int)(
        ($normalized['purchase_price_usd'] ?? 0) +
        ($normalized['land_transport_usd'] ?? 0) +
        ($normalized['inspection_fee_usd'] ?? 0) +
        ($normalized['other_cost_usd'] ?? 0) +
        ($normalized['profit_usd'] ?? 0)
    );
    if (($source['target_price_usd'] ?? '') === '' && $calculated_fob > 0) {
        $normalized['target_price_usd'] = $calculated_fob;
    }

    return $normalized;
}

function gt_quote_has_saved_user_data(array $quote): bool {
    $numeric_fields = [
        'auction_price_jpy', 'exchange_rate_jpy_usd', 'purchase_price_usd', 'land_transport_usd', 'ocean_freight_usd',
        'inspection_fee_usd', 'other_cost_usd', 'profit_usd', 'insurance_usd'
    ];
    foreach ($numeric_fields as $field) {
        if (gt_int_value($quote[$field] ?? 0) > 0) return true;
    }
    $string_fields = [
        'invoice_no', 'invoice_date', 'destination_country',
        'customer_name', 'customer_address', 'customer_tel', 'customer_email',
        'customer_contact', 'customer_attn', 'port_of_discharge',
        'chassis_no', 'engine_cc', 'quote_valid_until', 'quote_memo'
    ];
    foreach ($string_fields as $field) {
        if (gt_string_value($quote[$field] ?? '') !== '') return true;
    }
    return false;
}


function gt_export_document_data_value($v) {
    $source = [];
    if (!empty($v['export_document_data']) && is_array($v['export_document_data'])) {
        $source = $v['export_document_data'];
    }
    $quote = gt_quote_spec_data_value($v);
    $defaults = [
        'commercial_invoice_no' => '',
        'commercial_invoice_date' => '',
        'packing_list_no' => '',
        'packing_list_date' => '',
        'consignee_name' => '',
        'consignee_address' => '',
        'consignee_tel' => '',
        'consignee_email' => '',
        'notify_name' => '',
        'notify_address' => '',
        'notify_tel' => '',
        'notify_email' => '',
        'vessel_name' => '',
        'voyage_no' => '',
        'booking_no' => '',
        'bl_no' => '',
        'final_destination' => gt_string_value($quote['destination_country'] ?? ''),
        'marks_and_numbers' => '',
        'shipping_memo' => '',
    ];
    $normalized = [];
    foreach ($defaults as $field => $default) {
        $raw = $source[$field] ?? null;
        $normalized[$field] = ($raw === null || $raw === '') ? $default : gt_string_value($raw);
    }
    return $normalized;
}

function gt_vehicle_certificate_data_value($v) {
    $source = [];
    if (!empty($v['vehicle_certificate_data']) && is_array($v['vehicle_certificate_data'])) {
        $source = $v['vehicle_certificate_data'];
    }
    $defaults = [
        'registration_no' => '',
        'registration_date' => '',
        'first_registration' => '',
        'vehicle_type' => '',
        'use' => '',
        'private_business' => '',
        'body_shape' => '',
        'make_jp' => '',
        'make_en' => gt_string_value($v['make'] ?? ''),
        'model_code' => '',
        'chassis_no' => gt_string_value($v['chassis_no'] ?? ($v['quote_spec_data']['chassis_no'] ?? '')),
        'engine_model' => '',
        'fuel' => gt_string_value($v['fuel_type'] ?? ''),
        'displacement_l' => '',
        'engine_cc' => gt_string_value($v['engine_cc'] ?? ($v['quote_spec_data']['engine_cc'] ?? '')),
        'capacity' => '',
        'max_load_kg' => '',
        'vehicle_weight_kg' => '',
        'gross_weight_kg' => '',
        'length_cm' => '',
        'width_cm' => '',
        'height_cm' => '',
        'owner_name' => '',
        'remarks' => '',
        'ocr_text' => '',
    ];
    $normalized = [];
    foreach ($defaults as $field => $default) {
        $raw = $source[$field] ?? null;
        $normalized[$field] = ($raw === null || $raw === '') ? $default : gt_string_value($raw);
    }
    if ($normalized['engine_cc'] === '' && $normalized['displacement_l'] !== '') {
        $normalized['engine_cc'] = (string)(int)round(((float)$normalized['displacement_l']) * 1000);
    }
    if ($normalized['displacement_l'] === '' && gt_int_value($normalized['engine_cc']) > 0) {
        $normalized['displacement_l'] = rtrim(rtrim(number_format(gt_int_value($normalized['engine_cc']) / 1000, 2, '.', ''), '0'), '.');
    }
    return $normalized;
}

function gt_uploaded_certificate_files_value($v) {
    return array_values(array_filter($v['vehicle_certificate_files'] ?? []));
}

function gt_document_data_has_saved_user_data(array $doc): bool {
    foreach ($doc as $value) {
        if (gt_string_value($value) !== '') return true;
    }
    return false;
}

function normalizeVehicleRecord($v) {
    if (!is_array($v)) return [];

    $ref = gt_string_value($v['ref_id'] ?? $v['ref'] ?? '');
    $title = gt_string_value($v['display_name_en'] ?? $v['title'] ?? '');
    if ($title === '') {
        $title = gt_string_value(implode(' ', array_filter([
            $v['year'] ?? '', $v['make'] ?? '', $v['model'] ?? '', $v['grade'] ?? ''
        ])));
    }

    $reference_price = $v['reference_price_usd'] ?? null;
    if ($reference_price === null || $reference_price === '') {
        $reference_price = $v['price_usd'] ?? $v['price_low_usd'] ?? $v['price_high_usd'] ?? 0;
    }

    return [
        'ref_id'              => $ref,
        'display_name_en'     => $title,
        'year'                => gt_int_value($v['year'] ?? 0),
        'make'                => gt_string_value($v['make'] ?? ''),
        'model'               => gt_string_value($v['model'] ?? ''),
        'grade'               => gt_string_value($v['grade'] ?? ''),
        'body_type'           => gt_string_value($v['body_type'] ?? $v['body'] ?? ''),
        'fuel_type'           => gt_string_value($v['fuel_type'] ?? $v['fuel'] ?? ''),
        'transmission'        => gt_normalize_transmission($v['transmission'] ?? ''),
        'mileage_km'          => gt_int_value($v['mileage_km'] ?? $v['mileage'] ?? 0),
        'engine_cc'           => gt_int_value($v['engine_cc'] ?? $v['displacement_cc'] ?? $v['engine_displacement_cc'] ?? 0),
        'reference_price_usd' => gt_int_value($reference_price),
        'basis_from'          => gt_string_value($v['basis_from'] ?? $v['auction_date'] ?? ''),
        'basis_to'            => gt_string_value($v['basis_to'] ?? $v['auction_venue'] ?? ''),
        'disclaimer_short'    => gt_string_value($v['disclaimer_short'] ?? 'Reference vehicle only. Not in stock. Photo for reference.'),
        'best_for_resale_in'  => gt_string_value($v['best_for_resale_in'] ?? ''),
        'typical_buyer_use'   => gt_string_value($v['typical_buyer_use'] ?? ''),
        'similar_units'       => gt_string_value($v['similar_units'] ?? ''),
        'bulk_repeat_order'   => gt_string_value($v['bulk_repeat_order'] ?? ''),
        'gallery'             => gt_gallery_value($v),
        'quote_spec_files'    => array_values(array_filter($v['quote_spec_files'] ?? [])),
        'quote_image_files'   => array_values(array_filter($v['quote_image_files'] ?? [])),
        'vehicle_certificate_files' => gt_uploaded_certificate_files_value($v),
        'quote_spec_data'     => gt_quote_spec_data_value($v),
        'export_document_data' => gt_export_document_data_value($v),
        'vehicle_certificate_data' => gt_vehicle_certificate_data_value($v),
    ];
}

function normalizeVehicleData($decoded) {
    if (is_array($decoded) && array_key_exists('vehicles', $decoded) && is_array($decoded['vehicles'])) {
        $vehicles = $decoded['vehicles'];
    } elseif (is_array($decoded)) {
        $vehicles = $decoded;
    } else {
        $vehicles = [];
    }

    $vehicles = array_values(array_filter(array_map('normalizeVehicleRecord', $vehicles), function($v) {
        return !empty($v['ref_id']);
    }));

    return ['vehicles' => $vehicles];
}

function loadVehicles() {
    if (!file_exists(VEHICLES_JSON)) return ['vehicles' => []];
    $json = file_get_contents(VEHICLES_JSON);
    $decoded = json_decode($json, true);
    return normalizeVehicleData($decoded);
}

function saveVehicles($data) {
    $normalized = normalizeVehicleData($data);
    return file_put_contents(
        VEHICLES_JSON,
        json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}
