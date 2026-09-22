<?php
declare(strict_types=1);

class EaStoreException extends RuntimeException {}
final class EaValidationException extends EaStoreException {}
final class EaConflictException extends EaStoreException {}

function ea_environment_value(string $name): string
{
    $value = getenv($name);
    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }
    foreach ([$_SERVER, $_ENV] as $source) {
        $candidate = $source[$name] ?? null;
        if (is_string($candidate) && trim($candidate) !== '') {
            return trim($candidate);
        }
    }
    return '';
}

function ea_root_dir(): string
{
    return dirname(__DIR__);
}

function ea_seed_json_path(): string
{
    return ea_root_dir() . '/data/vehicles.json';
}

function ea_runtime_dir(): string
{
    $configured = ea_environment_value('GLORIA_EA_DATA_DIR');
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    return '/home/gltr/ea-admin-data';
}

function ea_runtime_json_path(): string
{
    return ea_runtime_dir() . '/vehicles.json';
}

function ea_backup_dir(): string
{
    return ea_runtime_dir() . '/backups';
}

function ea_static_image_dir(): string
{
    return ea_root_dir() . '/images';
}

function ea_runtime_image_dir(): string
{
    return ea_runtime_dir() . '/images';
}

function ea_make_directory(string $path, int $mode): void
{
    if (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path)) {
        throw new EaStoreException('Required directory could not be created.');
    }
    @chmod($path, $mode);
}

function ea_ensure_runtime_directories(): void
{
    ea_make_directory(ea_runtime_dir(), 0700);
    ea_make_directory(ea_backup_dir(), 0700);
    ea_make_directory(ea_runtime_image_dir(), 0700);
}

function ea_read_json_file(string $path, bool $checkImages = true): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new EaStoreException('Vehicle data is not readable.');
    }
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        throw new EaStoreException('Vehicle data is empty.');
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new EaValidationException('Vehicle data is not valid JSON.', 0, $exception);
    }
    if (!is_array($decoded)) {
        throw new EaValidationException('Vehicle data root must be an object.');
    }
    ea_validate_vehicle_data($decoded, $checkImages);
    return [
        'data' => $decoded,
        'raw' => $raw,
        'version' => hash('sha256', $raw),
        'path' => $path,
        'source' => $path === ea_runtime_json_path() ? 'runtime' : 'seed',
    ];
}

function ea_load_vehicle_data(bool $fallbackFromInvalidRuntime = false, bool $checkImages = true): array
{
    $runtime = ea_runtime_json_path();
    if (is_file($runtime)) {
        try {
            return ea_read_json_file($runtime, $checkImages);
        } catch (Throwable $exception) {
            if (!$fallbackFromInvalidRuntime) {
                throw $exception;
            }
            error_log('East Africa runtime vehicle data rejected: ' . $exception->getMessage());
        }
    }
    return ea_read_json_file(ea_seed_json_path(), $checkImages);
}

function ea_validate_vehicle_data(array $data, bool $checkImages = true): void
{
    if (!array_key_exists('vehicles', $data) || !is_array($data['vehicles']) || !array_is_list($data['vehicles'])) {
        throw new EaValidationException('Vehicle data must contain a vehicles list.');
    }
    $seen = [];
    foreach ($data['vehicles'] as $index => $vehicle) {
        if (!is_array($vehicle)) {
            throw new EaValidationException('Every vehicle must be an object.');
        }
        ea_validate_vehicle_record($vehicle, $checkImages);
        $ref = $vehicle['ref_id'];
        if (isset($seen[$ref])) {
            throw new EaValidationException('Duplicate Ref ID: ' . $ref);
        }
        $seen[$ref] = $index;
    }
}

function ea_validate_vehicle_record(array $vehicle, bool $checkImages = true): void
{
    $ref = $vehicle['ref_id'] ?? null;
    if (!is_string($ref) || !preg_match('/^EA-[A-Z0-9]+(?:-[A-Z0-9]+)*$/', $ref)) {
        throw new EaValidationException('Ref ID must use the EA-ABC-001 format.');
    }
    foreach (['make', 'model'] as $required) {
        $value = $vehicle[$required] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen($value) > 100) {
            throw new EaValidationException($required . ' is required and must be 100 characters or fewer.');
        }
    }
    $year = $vehicle['year'] ?? null;
    if (!is_int($year) || $year < 1900 || $year > 2100) {
        throw new EaValidationException('Year must be between 1900 and 2100.');
    }
    foreach (['display_name_en', 'grade', 'fuel_type', 'transmission', 'drive', 'steering', 'auction_grade'] as $field) {
        if (array_key_exists($field, $vehicle) && (!is_string($vehicle[$field]) || strlen($vehicle[$field]) > 150)) {
            throw new EaValidationException($field . ' must be a string of 150 characters or fewer.');
        }
    }
    if (array_key_exists('availability', $vehicle)) {
        $availability = $vehicle['availability'];
        if (!is_string($availability) || !in_array($availability, ['not_in_stock', 'in_stock'], true)) {
            throw new EaValidationException('Availability must be not_in_stock or in_stock.');
        }
    }
    foreach (['mileage_km', 'engine_cc'] as $field) {
        if (array_key_exists($field, $vehicle) && (!is_int($vehicle[$field]) || $vehicle[$field] < 0)) {
            throw new EaValidationException($field . ' must be a non-negative integer.');
        }
    }
    $hasPrice = false;
    foreach (['reference_price_usd', 'estimated_cif_mombasa_usd'] as $field) {
        if (!array_key_exists($field, $vehicle) || $vehicle[$field] === null) {
            continue;
        }
        if (!is_int($vehicle[$field]) || $vehicle[$field] <= 0) {
            throw new EaValidationException($field . ' must be blank or a positive integer.');
        }
        $hasPrice = true;
    }
    $priceDate = $vehicle['price_as_of'] ?? '';
    if ($hasPrice && (!is_string($priceDate) || !ea_valid_date($priceDate))) {
        throw new EaValidationException('Price reference date is required when a price is entered.');
    }
    if ($priceDate !== '' && (!is_string($priceDate) || !ea_valid_date($priceDate))) {
        throw new EaValidationException('Price reference date must use YYYY-MM-DD.');
    }
    $gallery = $vehicle['gallery'] ?? [];
    if (!is_array($gallery) || !array_is_list($gallery) || count($gallery) > 100) {
        throw new EaValidationException('Gallery must be a list containing no more than 100 images.');
    }
    $unique = [];
    foreach ($gallery as $path) {
        ea_validate_gallery_path($path, $ref, $checkImages);
        if (isset($unique[$path])) {
            throw new EaValidationException('Gallery contains a duplicate image path.');
        }
        $unique[$path] = true;
    }
}

function ea_valid_date(string $value): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $match)) {
        return false;
    }
    return checkdate((int)$match[2], (int)$match[3], (int)$match[1]);
}

function ea_validate_gallery_path(mixed $path, string $ref, bool $checkExists = true): void
{
    if (!is_string($path) || strlen($path) > 255 || str_contains($path, '..') || str_contains($path, "\0")) {
        throw new EaValidationException('Gallery path is invalid.');
    }
    $pattern = '#^images/' . preg_quote($ref, '#') . '/[A-Za-z0-9][A-Za-z0-9._-]*\.(?:jpe?g|png|webp)$#i';
    if (!preg_match($pattern, $path)) {
        throw new EaValidationException('Gallery images must be stored in the matching Ref ID directory.');
    }
    if ($checkExists) {
        if (ea_resolve_gallery_image($path) === null) {
            throw new EaValidationException('Gallery image does not exist: ' . $path);
        }
    }
}

function ea_resolve_gallery_image(string $path): ?string
{
    if (!str_starts_with($path, 'images/')) {
        return null;
    }
    $suffix = substr($path, strlen('images/'));
    $candidates = [
        [ea_static_image_dir(), ea_static_image_dir() . '/' . $suffix],
        [ea_runtime_image_dir(), ea_runtime_image_dir() . '/' . $suffix],
    ];
    foreach ($candidates as [$root, $candidate]) {
        if (!is_file($candidate)) {
            continue;
        }
        $realRoot = realpath($root);
        $realFile = realpath($candidate);
        if ($realRoot !== false && $realFile !== false && str_starts_with($realFile, $realRoot . DIRECTORY_SEPARATOR)) {
            return $realFile;
        }
    }
    return null;
}

function ea_find_vehicle(array $data, string $ref): ?array
{
    foreach ($data['vehicles'] as $index => $vehicle) {
        if (($vehicle['ref_id'] ?? '') === $ref) {
            return ['index' => $index, 'vehicle' => $vehicle];
        }
    }
    return null;
}

function ea_build_vehicle_record(array $existing, array $input, array $gallery): array
{
    $record = $existing;
    $ref = strtoupper(trim((string)($input['ref_id'] ?? '')));
    $record['ref_id'] = $ref;
    $record['make'] = trim((string)($input['make'] ?? ''));
    $record['model'] = trim((string)($input['model'] ?? ''));
    $record['year'] = ea_parse_required_integer($input['year'] ?? '', 'Year', 1900, 2100);

    foreach (['display_name_en', 'grade', 'fuel_type', 'transmission', 'drive', 'steering', 'auction_grade'] as $field) {
        ea_assign_optional_string($record, $field, $input[$field] ?? '', 150);
    }
    $availability = trim((string)($input['availability'] ?? ''));
    if ($availability === '') {
        $availability = (string)($existing['availability'] ?? 'not_in_stock');
    }
    if (!in_array($availability, ['not_in_stock', 'in_stock'], true)) {
        throw new EaValidationException('Availability must be not_in_stock or in_stock.');
    }
    $record['availability'] = $availability;
    foreach (['mileage_km', 'engine_cc'] as $field) {
        $value = trim((string)($input[$field] ?? ''));
        if ($value === '') {
            unset($record[$field]);
        } else {
            $record[$field] = ea_parse_required_integer($value, $field, 0, 100000000);
        }
    }
    foreach (['reference_price_usd', 'estimated_cif_mombasa_usd'] as $field) {
        $value = trim(str_replace(',', '', (string)($input[$field] ?? '')));
        $record[$field] = $value === '' ? null : ea_parse_required_integer($value, $field, 1, 1000000000);
    }
    $date = trim((string)($input['price_as_of'] ?? ''));
    if ($date === '') {
        unset($record['price_as_of']);
    } else {
        $record['price_as_of'] = $date;
    }
    $record['gallery'] = array_values($gallery);
    ea_validate_vehicle_record($record, true);
    return $record;
}

function ea_assign_optional_string(array &$record, string $field, mixed $raw, int $maxLength): void
{
    $value = trim((string)$raw);
    if ($value === '') {
        unset($record[$field]);
        return;
    }
    if (strlen($value) > $maxLength) {
        throw new EaValidationException($field . ' is too long.');
    }
    $record[$field] = $value;
}

function ea_parse_required_integer(mixed $raw, string $label, int $minimum, int $maximum): int
{
    $value = trim(str_replace(',', '', (string)$raw));
    if ($value === '' || !preg_match('/^\d+$/', $value)) {
        throw new EaValidationException($label . ' must be a whole number.');
    }
    $number = (int)$value;
    if ($number < $minimum || $number > $maximum) {
        throw new EaValidationException($label . ' is outside the allowed range.');
    }
    return $number;
}

function ea_encode_vehicle_data(array $data): string
{
    try {
        return json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    } catch (JsonException $exception) {
        throw new EaStoreException('Vehicle data could not be encoded.', 0, $exception);
    }
}

function ea_write_all($handle, string $content): void
{
    $remaining = $content;
    while ($remaining !== '') {
        $written = fwrite($handle, $remaining);
        if ($written === false || $written === 0) {
            throw new EaStoreException('A file write did not complete.');
        }
        $remaining = substr($remaining, $written);
    }
    if (!fflush($handle)) {
        throw new EaStoreException('A file write could not be flushed.');
    }
    if (function_exists('fsync') && !fsync($handle)) {
        throw new EaStoreException('A file write could not be synchronized.');
    }
}

function ea_create_backup(string $currentRaw): string
{
    ea_ensure_runtime_directories();
    $freeSpace = disk_free_space(ea_runtime_dir());
    $minimumFree = max(20 * 1024 * 1024, strlen($currentRaw) * 5);
    if ($freeSpace !== false && $freeSpace < $minimumFree) {
        throw new EaStoreException('There is not enough free space to create a verified backup safely.');
    }
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $path = ea_backup_dir() . '/vehicles-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            continue;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new EaStoreException('Backup lock could not be acquired.');
            }
            ea_write_all($handle, $currentRaw);
            flock($handle, LOCK_UN);
            fclose($handle);
            @chmod($path, 0600);
            $saved = file_get_contents($path);
            if ($saved === false || !hash_equals(hash('sha256', $currentRaw), hash('sha256', $saved))) {
                @unlink($path);
                throw new EaStoreException('Backup verification failed.');
            }
            ea_read_json_file($path, false);
            return $path;
        } catch (Throwable $exception) {
            if (is_resource($handle)) {
                @flock($handle, LOCK_UN);
                @fclose($handle);
            }
            @unlink($path);
            throw $exception;
        }
    }
    throw new EaStoreException('A unique backup file could not be created.');
}

function ea_atomic_replace_runtime(string $newRaw): void
{
    ea_ensure_runtime_directories();
    $temporary = ea_runtime_dir() . '/.vehicles-' . bin2hex(random_bytes(8)) . '.tmp';
    $handle = @fopen($temporary, 'x+b');
    if ($handle === false) {
        throw new EaStoreException('Temporary vehicle data file could not be created.');
    }
    try {
        ea_write_all($handle, $newRaw);
        fclose($handle);
        @chmod($temporary, 0600);
        $temporaryRaw = file_get_contents($temporary);
        if ($temporaryRaw === false || !hash_equals(hash('sha256', $newRaw), hash('sha256', $temporaryRaw))) {
            throw new EaStoreException('Temporary vehicle data verification failed.');
        }
        ea_read_json_file($temporary, true);
        if (!rename($temporary, ea_runtime_json_path())) {
            throw new EaStoreException('Vehicle data could not be atomically replaced.');
        }
        @chmod(ea_runtime_json_path(), 0600);
    } catch (Throwable $exception) {
        if (is_resource($handle)) {
            @fclose($handle);
        }
        @unlink($temporary);
        throw $exception;
    }
}

function ea_save_vehicle_record(array $record, ?string $originalRef, string $expectedVersion): array
{
    ea_ensure_runtime_directories();
    $lockPath = ea_runtime_dir() . '/vehicles.lock';
    $lock = @fopen($lockPath, 'c+b');
    if ($lock === false) {
        throw new EaStoreException('Vehicle data lock could not be opened.');
    }
    @chmod($lockPath, 0600);
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new EaStoreException('Vehicle data lock could not be acquired.');
        }
        $current = ea_load_vehicle_data(false, true);
        if ($expectedVersion === '' || !hash_equals($current['version'], $expectedVersion)) {
            throw new EaConflictException('Vehicle data changed after this form was opened. Reload and try again.');
        }
        $data = $current['data'];
        if ($originalRef !== null) {
            $found = ea_find_vehicle($data, $originalRef);
            if ($found === null) {
                throw new EaConflictException('The vehicle being edited no longer exists.');
            }
            if (($record['ref_id'] ?? '') !== $originalRef) {
                throw new EaValidationException('Ref ID cannot be changed after registration.');
            }
            $data['vehicles'][$found['index']] = $record;
        } else {
            if (ea_find_vehicle($data, (string)($record['ref_id'] ?? '')) !== null) {
                throw new EaValidationException('This Ref ID is already registered.');
            }
            $data['vehicles'][] = $record;
        }
        ea_validate_vehicle_data($data, true);
        $newRaw = ea_encode_vehicle_data($data);
        $backupPath = ea_create_backup($current['raw']);
        try {
            ea_atomic_replace_runtime($newRaw);
            $saved = ea_read_json_file(ea_runtime_json_path(), true);
            if (!hash_equals(hash('sha256', $newRaw), $saved['version'])) {
                throw new EaStoreException('Saved vehicle data verification failed.');
            }
        } catch (Throwable $exception) {
            try {
                ea_atomic_replace_runtime($current['raw']);
            } catch (Throwable $restoreException) {
                error_log('East Africa vehicle data restore failed: ' . $restoreException->getMessage());
            }
            throw $exception;
        }
        flock($lock, LOCK_UN);
        fclose($lock);
        return ['version' => hash('sha256', $newRaw), 'backup' => $backupPath];
    } catch (Throwable $exception) {
        if (is_resource($lock)) {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
        throw $exception;
    }
}

function ea_store_uploaded_images(array $files, string $ref): array
{
    if (!preg_match('/^EA-[A-Z0-9]+(?:-[A-Z0-9]+)*$/', $ref)) {
        throw new EaValidationException('A valid Ref ID is required before images can be uploaded.');
    }
    $items = ea_uploaded_file_items($files);
    if ($items === []) {
        return [];
    }
    if (count($items) > 20) {
        throw new EaValidationException('No more than 20 images can be uploaded at once.');
    }
    $mimeMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $validated = [];
    $totalSize = 0;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    foreach ($items as $index => $item) {
        if ($item['error'] !== UPLOAD_ERR_OK) {
            throw new EaValidationException('Image upload failed for file number ' . ($index + 1) . '.');
        }
        $tmp = $item['tmp_name'];
        if (!is_string($tmp) || !is_uploaded_file($tmp)) {
            throw new EaValidationException('Uploaded image could not be verified.');
        }
        $size = (int)$item['size'];
        if ($size <= 0 || $size > 10 * 1024 * 1024) {
            throw new EaValidationException('Each image must be 10 MiB or smaller.');
        }
        $totalSize += $size;
        if ($totalSize > 40 * 1024 * 1024) {
            throw new EaValidationException('The total image upload must be 40 MiB or smaller.');
        }
        $mime = $finfo->file($tmp);
        if (!is_string($mime) || !isset($mimeMap[$mime])) {
            throw new EaValidationException('Only JPEG, PNG, and WebP images are accepted.');
        }
        $dimensions = @getimagesize($tmp);
        if ($dimensions === false) {
            throw new EaValidationException('An uploaded file is not a valid image.');
        }
        $width = (int)$dimensions[0];
        $height = (int)$dimensions[1];
        if ($width <= 0 || $height <= 0 || $width > 12000 || $height > 12000 || ($width * $height) > 40000000) {
            throw new EaValidationException('An image exceeds the allowed dimensions.');
        }
        $validated[] = ['tmp' => $tmp, 'extension' => $mimeMap[$mime]];
    }

    ea_ensure_runtime_directories();
    $directory = ea_runtime_image_dir() . '/' . $ref;
    ea_make_directory($directory, 0700);
    $savedPaths = [];
    try {
        foreach ($validated as $item) {
            $destination = '';
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $filename = $ref . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.' . $item['extension'];
                $candidate = $directory . '/' . $filename;
                $placeholder = @fopen($candidate, 'x');
                if ($placeholder !== false) {
                    fclose($placeholder);
                    $destination = $candidate;
                    break;
                }
            }
            if ($destination === '') {
                throw new EaStoreException('A unique image filename could not be created.');
            }
            if (!move_uploaded_file($item['tmp'], $destination)) {
                @unlink($destination);
                throw new EaStoreException('An uploaded image could not be stored.');
            }
            @chmod($destination, 0600);
            $savedPaths[] = 'images/' . $ref . '/' . basename($destination);
        }
        return $savedPaths;
    } catch (Throwable $exception) {
        ea_remove_created_images($savedPaths);
        throw $exception;
    }
}

function ea_uploaded_file_items(array $files): array
{
    if (!isset($files['name'])) {
        return [];
    }
    $names = is_array($files['name']) ? $files['name'] : [$files['name']];
    $tmpNames = is_array($files['tmp_name'] ?? null) ? $files['tmp_name'] : [$files['tmp_name'] ?? ''];
    $errors = is_array($files['error'] ?? null) ? $files['error'] : [$files['error'] ?? UPLOAD_ERR_NO_FILE];
    $sizes = is_array($files['size'] ?? null) ? $files['size'] : [$files['size'] ?? 0];
    $items = [];
    foreach ($names as $index => $name) {
        $error = (int)($errors[$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE || $name === '') {
            continue;
        }
        $items[] = [
            'name' => (string)$name,
            'tmp_name' => (string)($tmpNames[$index] ?? ''),
            'error' => $error,
            'size' => (int)($sizes[$index] ?? 0),
        ];
    }
    return $items;
}

function ea_gallery_from_order(string $orderJson, array $existingGallery, array $uploadedPaths): array
{
    try {
        $tokens = json_decode($orderJson, true, 128, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new EaValidationException('Gallery order is invalid.', 0, $exception);
    }
    if (!is_array($tokens) || !array_is_list($tokens)) {
        throw new EaValidationException('Gallery order must be a list.');
    }
    $oldMap = [];
    foreach ($existingGallery as $path) {
        $oldMap['old:' . $path] = $path;
    }
    $newMap = [];
    foreach ($uploadedPaths as $index => $path) {
        $newMap['new:' . $index] = $path;
    }
    $containsNew = false;
    foreach ($tokens as $token) {
        if (is_string($token) && str_starts_with($token, 'new:')) {
            $containsNew = true;
            break;
        }
    }
    if (!$containsNew && $newMap !== []) {
        $tokens = array_merge($tokens, array_keys($newMap));
    }
    $expected = $oldMap + $newMap;
    if (count(array_unique($tokens, SORT_STRING)) !== count($tokens)) {
        throw new EaValidationException('Gallery contains a duplicate image.');
    }
    $gallery = [];
    $used = [];
    foreach ($tokens as $token) {
        if (!is_string($token) || !array_key_exists($token, $expected)) {
            throw new EaValidationException('Gallery order contains an unknown image.');
        }
        $used[$token] = true;
        $gallery[] = $expected[$token];
    }
    foreach (array_keys($newMap) as $newToken) {
        if (!isset($used[$newToken])) {
            throw new EaValidationException('Newly uploaded images must stay in this vehicle gallery.');
        }
    }
    return $gallery;
}

function ea_detached_gallery_paths(array $previousGallery, array $newGallery): array
{
    return array_values(array_diff($previousGallery, $newGallery));
}

function ea_assert_gallery_belongs_to_ref(array $paths, string $ref): void
{
    foreach ($paths as $path) {
        if (!is_string($path)) {
            throw new EaValidationException('Gallery path is invalid.');
        }
        ea_validate_gallery_path($path, $ref, false);
    }
}

function ea_remove_created_images(array $paths): void
{
    $imageRoot = realpath(ea_runtime_image_dir());
    if ($imageRoot === false) {
        return;
    }
    foreach ($paths as $path) {
        if (!is_string($path) || !str_starts_with($path, 'images/')) {
            continue;
        }
        $suffix = substr($path, strlen('images/'));
        $fullPath = ea_runtime_image_dir() . '/' . $suffix;
        $realPath = realpath($fullPath);
        if ($realPath !== false && str_starts_with($realPath, $imageRoot . DIRECTORY_SEPARATOR) && is_file($realPath)) {
            @unlink($realPath);
        }
    }
}
