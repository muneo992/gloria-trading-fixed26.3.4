<?php
declare(strict_types=1);

/**
 * One-shot helper to remove the EA-PBX-004 test listing from runtime data.
 * Does not modify West Africa, Git seed JSON, or EA-PBX-001 photos.
 */

const EA_REMOVE_REF = 'EA-PBX-004';
const EA_STORE = '/home/gltr/www/gloria-ea/lib/vehicle-store.php';

if (!is_readable(EA_STORE)) {
    fwrite(STDERR, "East Africa vehicle store is not readable.\n");
    exit(1);
}

require_once EA_STORE;

$expectedGallery = [];
for ($number = 1; $number <= 8; $number++) {
    $expectedGallery[] = sprintf('images/EA-PBX-001/EA-PBX-001-%02d.jpg', $number);
}

function ea_remaining_vehicles_after_test_removal(array $vehicles, array $expectedGallery): array
{
    $found = false;
    $remaining = [];
    foreach ($vehicles as $vehicle) {
        if (!is_array($vehicle) || !isset($vehicle['ref_id'])) {
            throw new EaStoreException('Vehicle data is invalid.');
        }
        if ($vehicle['ref_id'] === EA_REMOVE_REF) {
            $found = true;
            continue;
        }
        $remaining[] = $vehicle;
    }
    if (!$found) {
        throw new EaStoreException('EA-PBX-004 is not present in runtime data.');
    }
    $refs = array_map(static fn(array $vehicle): string => (string)$vehicle['ref_id'], $remaining);
    if ($refs !== ['EA-PBX-001', 'EA-PBX-002', 'EA-PBX-003']) {
        throw new EaStoreException('Remaining vehicles are not EA-PBX-001/002/003.');
    }
    if (($remaining[0]['gallery'] ?? null) !== $expectedGallery) {
        throw new EaStoreException('EA-PBX-001 gallery would change.');
    }
    return $remaining;
}

$lockPath = ea_runtime_dir() . '/vehicles.lock';
$lock = @fopen($lockPath, 'c+b');
if ($lock === false) {
    fwrite(STDERR, "Vehicle data lock could not be opened.\n");
    exit(1);
}
@chmod($lockPath, 0600);

try {
    if (!flock($lock, LOCK_EX)) {
        throw new EaStoreException('Vehicle data lock could not be acquired.');
    }

    $current = ea_load_vehicle_data(false, true);
    if ($current['source'] !== 'runtime' || $current['path'] !== ea_runtime_json_path()) {
        throw new EaStoreException('Refusing to change seed/fallback data.');
    }

    $data = $current['data'];
    $data['vehicles'] = ea_remaining_vehicles_after_test_removal($data['vehicles'], $expectedGallery);
    ea_validate_vehicle_data($data, true);
    $newRaw = ea_encode_vehicle_data($data);
    $backupPath = ea_create_backup($current['raw']);
    ea_atomic_replace_runtime($newRaw);
    $saved = ea_read_json_file(ea_runtime_json_path(), true);
    if (!hash_equals(hash('sha256', $newRaw), $saved['version'])) {
        throw new EaStoreException('Saved vehicle data verification failed.');
    }

    flock($lock, LOCK_UN);
    fclose($lock);
    fwrite(STDOUT, "Removed EA-PBX-004. Backup: " . basename($backupPath) . PHP_EOL);
} catch (Throwable $exception) {
    if (is_resource($lock)) {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
