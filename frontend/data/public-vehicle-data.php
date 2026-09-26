<?php
declare(strict_types=1);

/**
 * Public vehicle feed contract.
 *
 * Only fields explicitly listed here may cross the public HTTP boundary.
 * Administrative quotation, customer, export, certificate, and upload metadata
 * remain in the private master record.
 */
const GT_PUBLIC_VEHICLE_SCALAR_FIELDS = [
    'ref_id',
    'display_name_en',
    'year',
    'make',
    'model',
    'grade',
    'body_type',
    'fuel_type',
    'transmission',
    'mileage_km',
    'engine_cc',
    'reference_price_usd',
    'best_for_resale_in',
    'typical_buyer_use',
    'similar_units',
    'bulk_repeat_order',
];

function gt_public_vehicle_record(array $record): array
{
    $public = [];
    foreach (GT_PUBLIC_VEHICLE_SCALAR_FIELDS as $field) {
        if (array_key_exists($field, $record) && (is_scalar($record[$field]) || $record[$field] === null)) {
            $public[$field] = $record[$field];
        }
    }

    $public['gallery'] = [];
    if (isset($record['gallery']) && is_array($record['gallery'])) {
        $public['gallery'] = array_values(array_filter(
            $record['gallery'],
            static fn($path): bool => is_string($path)
        ));
    }

    return $public;
}

function gt_public_vehicle_data(mixed $decoded): array
{
    $records = is_array($decoded) && isset($decoded['vehicles']) && is_array($decoded['vehicles'])
        ? $decoded['vehicles']
        : [];

    return [
        'vehicles' => array_values(array_map(
            static fn($record): array => gt_public_vehicle_record(is_array($record) ? $record : []),
            $records
        )),
    ];
}
