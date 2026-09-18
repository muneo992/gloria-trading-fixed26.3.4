<?php
declare(strict_types=1);

class SaStoreException extends RuntimeException {}
final class SaValidationException extends SaStoreException {}
final class SaConflictException extends SaStoreException {}

function sa_environment_value(string $name): string
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

function sa_root_dir(): string
{
    return dirname(__DIR__);
}

function sa_seed_json_path(): string
{
    return sa_root_dir() . '/data/vehicles.json';
}

function sa_runtime_dir(): string
{
    $configured = sa_environment_value('GLORIA_SA_DATA_DIR');
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    return '/home/gltr/sa-admin-data';
}

function sa_runtime_json_path(): string
{
    return sa_runtime_dir() . '/vehicles.json';
}

function sa_backup_dir(): string
{
    return sa_runtime_dir() . '/backups';
}

function sa_static_image_dir(): string
{
    return sa_root_dir() . '/images';
}

function sa_runtime_image_dir(): string
{
    return sa_runtime_dir() . '/images';
}

function sa_make_directory(string $path, int $mode): void
{
    if (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path)) {
        throw new SaStoreException('Required directory could not be created.');
    }
    @chmod($path, $mode);
}

function sa_ensure_runtime_directories(): void
{
    sa_make_directory(sa_runtime_dir(), 0700);
    sa_make_directory(sa_backup_dir(), 0700);
    sa_make_directory(sa_runtime_image_dir(), 0700);
}

function sa_read_json_file(string $path, bool $checkImages = true): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new SaStoreException('Vehicle data is not readable.');
    }
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        throw new SaStoreException('Vehicle data is empty.');
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new SaValidationException('Vehicle data is not valid JSON.', 0, $exception);
    }
    if (!is_array($decoded)) {
        throw new SaValidationException('Vehicle data root must be an object.');
    }
    sa_validate_vehicle_data($decoded, $checkImages);
    return [
        'data' => $decoded,
        'raw' => $raw,
        'version' => hash('sha256', $raw),
        'path' => $path,
        'source' => $path === sa_runtime_json_path() ? 'runtime' : 'seed',
    ];
}

function sa_load_vehicle_data(bool $fallbackFromInvalidRuntime = false, bool $checkImages = true): array
{
    $runtime = sa_runtime_json_path();
    if (is_file($runtime)) {
        try {
            return sa_read_json_file($runtime, $checkImages);
        } catch (Throwable $exception) {
            if (!$fallbackFromInvalidRuntime) {
                throw $exception;
            }
            error_log('South Africa runtime vehicle data rejected: ' . $exception->getMessage());
        }
    }
    return sa_read_json_file(sa_seed_json_path(), $checkImages);
}

function sa_validate_vehicle_data(array $data, bool $checkImages = true): void
{
    if (!array_key_exists('vehicles', $data) || !is_array($data['vehicles']) || !array_is_list($data['vehicles'])) {
        throw new SaValidationException('Vehicle data must contain a vehicles list.');
    }
    $seen = [];
    foreach ($data['vehicles'] as $vehicle) {
        if (!is_array($vehicle)) {
            throw new SaValidationException('Every vehicle must be an object.');
        }
        sa_validate_vehicle_record($vehicle, $checkImages);
        $ref = $vehicle['ref_id'];
        if (isset($seen[$ref])) {
            throw new SaValidationException('Duplicate Ref ID: ' . $ref);
        }
        $seen[$ref] = true;
    }
}

function sa_optional_string_ok(mixed $value, int $max): bool
{
    return $value === null || (is_string($value) && strlen($value) <= $max);
}

function sa_validate_vehicle_record(array $vehicle, bool $checkImages = true): void
{
    $ref = $vehicle['ref_id'] ?? null;
    if (!is_string($ref) || !preg_match('/^SA-[A-Z0-9]+(?:-[A-Z0-9]+)*$/', $ref)) {
        throw new SaValidationException('Ref ID must use the SA-ABC-001 format.');
    }
    foreach (['make', 'model'] as $required) {
        $value = $vehicle[$required] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen($value) > 100) {
            throw new SaValidationException($required . ' is required and must be 100 characters or fewer.');
        }
    }
    $year = $vehicle['year'] ?? null;
    if ($year !== null && (!is_int($year) || $year < 1900 || $year > 2100)) {
        throw new SaValidationException('Year must be blank or between 1900 and 2100.');
    }
    $listing = $vehicle['listing_type'] ?? null;
    if (!in_array($listing, ['sample', 'available'], true)) {
        throw new SaValidationException('Listing type must be Sample or Available.');
    }
    $status = $vehicle['status'] ?? null;
    if (!in_array($status, ['published', 'draft'], true)) {
        throw new SaValidationException('Status must be Published or Draft.');
    }
    foreach (['display_name_en', 'powertrain', 'battery', 'range', 'body', 'doors', 'transmission', 'steering'] as $field) {
        if (array_key_exists($field, $vehicle) && !sa_optional_string_ok($vehicle[$field], 150)) {
            throw new SaValidationException($field . ' must be a string of 150 characters or fewer.');
        }
    }
    foreach (['notes', 'auction_condition'] as $field) {
        if (array_key_exists($field, $vehicle) && !sa_optional_string_ok($vehicle[$field], 2000)) {
            throw new SaValidationException($field . ' must be 2000 characters or fewer.');
        }
    }
    if (array_key_exists('mileage_km', $vehicle) && $vehicle['mileage_km'] !== null && (!is_int($vehicle['mileage_km']) || $vehicle['mileage_km'] < 0)) {
        throw new SaValidationException('Mileage must be blank or a non-negative integer.');
    }
    if (array_key_exists('reference_price_usd', $vehicle) && $vehicle['reference_price_usd'] !== null) {
        if (!is_int($vehicle['reference_price_usd']) || $vehicle['reference_price_usd'] <= 0) {
            throw new SaValidationException('FOB Japan price must be blank or a positive integer.');
        }
        $priceDate = $vehicle['price_as_of'] ?? '';
        if (!is_string($priceDate) || !sa_valid_date($priceDate)) {
            throw new SaValidationException('Price reference date is required when a price is entered.');
        }
    }
    $priceDate = $vehicle['price_as_of'] ?? '';
    if ($priceDate !== null && $priceDate !== '' && (!is_string($priceDate) || !sa_valid_date($priceDate))) {
        throw new SaValidationException('Price reference date must use YYYY-MM-DD.');
    }
    $video = $vehicle['video_url'] ?? null;
    if ($video !== null && $video !== '') {
        if (!is_string($video) || !sa_valid_video_url($video)) {
            throw new SaValidationException('Video URL must be a https YouTube or Vimeo link, or blank.');
        }
    }
    $gallery = $vehicle['gallery'] ?? [];
    if (!is_array($gallery) || !array_is_list($gallery) || count($gallery) > 100) {
        throw new SaValidationException('Gallery must be a list containing no more than 100 images.');
    }
    $unique = [];
    foreach ($gallery as $path) {
        sa_validate_gallery_path($path, $ref, $checkImages);
        if (isset($unique[$path])) {
            throw new SaValidationException('Gallery contains a duplicate image path.');
        }
        $unique[$path] = true;
    }
}

function sa_valid_date(string $value): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $match)) {
        return false;
    }
    return checkdate((int)$match[2], (int)$match[3], (int)$match[1]);
}

function sa_valid_video_url(string $value): bool
{
    if (strlen($value) > 300 || !str_starts_with($value, 'https://')) {
        return false;
    }
    $parts = parse_url($value);
    $host = strtolower((string)($parts['host'] ?? ''));
    $allowed = ['youtube.com', 'www.youtube.com', 'youtu.be', 'www.youtu.be', 'vimeo.com', 'www.vimeo.com', 'player.vimeo.com'];
    return in_array($host, $allowed, true);
}

function sa_validate_gallery_path(mixed $path, string $ref, bool $checkExists = true): void
{
    if (!is_string($path) || strlen($path) > 255 || str_contains($path, '..') || str_contains($path, "\0")) {
        throw new SaValidationException('Gallery path is invalid.');
    }
    $pattern = '#^images/' . preg_quote($ref, '#') . '/[A-Za-z0-9][A-Za-z0-9._-]*\.(?:jpe?g|png|webp)$#i';
    if (!preg_match($pattern, $path)) {
        throw new SaValidationException('Gallery images must be stored in the matching Ref ID directory.');
    }
    if ($checkExists && sa_resolve_gallery_image($path) === null) {
        throw new SaValidationException('Gallery image does not exist: ' . $path);
    }
}

function sa_resolve_gallery_image(string $path): ?string
{
    if (!str_starts_with($path, 'images/')) {
        return null;
    }
    $suffix = substr($path, strlen('images/'));
    $candidates = [
        [sa_static_image_dir(), sa_static_image_dir() . '/' . $suffix],
        [sa_runtime_image_dir(), sa_runtime_image_dir() . '/' . $suffix],
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

function sa_find_vehicle(array $data, string $ref): ?array
{
    foreach ($data['vehicles'] as $index => $vehicle) {
        if (($vehicle['ref_id'] ?? '') === $ref) {
            return ['index' => $index, 'vehicle' => $vehicle];
        }
    }
    return null;
}

function sa_published_vehicle_data(array $data): array
{
    $published = [];
    foreach ($data['vehicles'] as $vehicle) {
        if (($vehicle['status'] ?? '') === 'published') {
            $published[] = $vehicle;
        }
    }
    return ['vehicles' => $published];
}

function sa_build_vehicle_record(array $existing, array $input, array $gallery): array
{
    $record = [];
    $record['ref_id'] = strtoupper(trim((string)($input['ref_id'] ?? '')));
    $record['make'] = trim((string)($input['make'] ?? ''));
    $record['model'] = trim((string)($input['model'] ?? ''));
    $listing = strtolower(trim((string)($input['listing_type'] ?? 'sample')));
    $record['listing_type'] = $listing === 'available' ? 'available' : 'sample';
    $status = strtolower(trim((string)($input['status'] ?? 'draft')));
    $record['status'] = $status === 'published' ? 'published' : 'draft';

    $year = trim((string)($input['year'] ?? ''));
    $record['year'] = $year === '' ? null : sa_parse_required_integer($year, 'Year', 1900, 2100);

    foreach (['display_name_en', 'powertrain', 'battery', 'range', 'body', 'doors', 'transmission', 'steering'] as $field) {
        sa_assign_optional_string($record, $field, $input[$field] ?? '', 150);
    }
    foreach (['notes', 'auction_condition'] as $field) {
        sa_assign_optional_string($record, $field, $input[$field] ?? '', 2000);
    }
    $mileage = trim((string)($input['mileage_km'] ?? ''));
    $record['mileage_km'] = $mileage === '' ? null : sa_parse_required_integer($mileage, 'Mileage', 0, 100000000);

    $price = trim(str_replace(',', '', (string)($input['reference_price_usd'] ?? '')));
    $record['reference_price_usd'] = $price === '' ? null : sa_parse_required_integer($price, 'FOB Japan price', 1, 1000000000);
    $date = trim((string)($input['price_as_of'] ?? ''));
    $record['price_as_of'] = $date === '' ? null : $date;

    $video = trim((string)($input['video_url'] ?? ''));
    $record['video_url'] = $video === '' ? null : $video;
    $record['gallery'] = array_values($gallery);
    sa_validate_vehicle_record($record, true);
    return $record;
}

function sa_assign_optional_string(array &$record, string $field, mixed $raw, int $maxLength): void
{
    $value = trim((string)$raw);
    $record[$field] = $value === '' ? null : $value;
    if ($value !== '' && strlen($value) > $maxLength) {
        throw new SaValidationException($field . ' is too long.');
    }
}

function sa_parse_required_integer(mixed $raw, string $label, int $minimum, int $maximum): int
{
    $value = trim(str_replace(',', '', (string)$raw));
    if ($value === '' || !preg_match('/^\d+$/', $value)) {
        throw new SaValidationException($label . ' must be a whole number.');
    }
    $number = (int)$value;
    if ($number < $minimum || $number > $maximum) {
        throw new SaValidationException($label . ' is outside the allowed range.');
    }
    return $number;
}

function sa_encode_vehicle_data(array $data): string
{
    try {
        return json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    } catch (JsonException $exception) {
        throw new SaStoreException('Vehicle data could not be encoded.', 0, $exception);
    }
}

function sa_write_all($handle, string $content): void
{
    $remaining = $content;
    while ($remaining !== '') {
        $written = fwrite($handle, $remaining);
        if ($written === false || $written === 0) {
            throw new SaStoreException('A file write did not complete.');
        }
        $remaining = substr($remaining, $written);
    }
    if (!fflush($handle)) {
        throw new SaStoreException('A file write could not be flushed.');
    }
    if (function_exists('fsync') && !fsync($handle)) {
        throw new SaStoreException('A file write could not be synchronized.');
    }
}

function sa_create_backup(string $currentRaw): string
{
    sa_ensure_runtime_directories();
    $freeSpace = disk_free_space(sa_runtime_dir());
    $minimumFree = max(20 * 1024 * 1024, strlen($currentRaw) * 5);
    if ($freeSpace !== false && $freeSpace < $minimumFree) {
        throw new SaStoreException('There is not enough free space to create a verified backup safely.');
    }
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $path = sa_backup_dir() . '/vehicles-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            continue;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new SaStoreException('Backup lock could not be acquired.');
            }
            sa_write_all($handle, $currentRaw);
            flock($handle, LOCK_UN);
            fclose($handle);
            @chmod($path, 0600);
            $saved = file_get_contents($path);
            if ($saved === false || !hash_equals(hash('sha256', $currentRaw), hash('sha256', $saved))) {
                @unlink($path);
                throw new SaStoreException('Backup verification failed.');
            }
            sa_read_json_file($path, false);
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
    throw new SaStoreException('A unique backup file could not be created.');
}

function sa_atomic_replace_runtime(string $newRaw): void
{
    sa_ensure_runtime_directories();
    $temporary = sa_runtime_dir() . '/.vehicles-' . bin2hex(random_bytes(8)) . '.tmp';
    $handle = @fopen($temporary, 'x+b');
    if ($handle === false) {
        throw new SaStoreException('Temporary vehicle data file could not be created.');
    }
    try {
        sa_write_all($handle, $newRaw);
        fclose($handle);
        @chmod($temporary, 0600);
        $temporaryRaw = file_get_contents($temporary);
        if ($temporaryRaw === false || !hash_equals(hash('sha256', $newRaw), hash('sha256', $temporaryRaw))) {
            throw new SaStoreException('Temporary vehicle data verification failed.');
        }
        sa_read_json_file($temporary, true);
        if (!rename($temporary, sa_runtime_json_path())) {
            throw new SaStoreException('Vehicle data could not be atomically replaced.');
        }
        @chmod(sa_runtime_json_path(), 0600);
    } catch (Throwable $exception) {
        if (is_resource($handle)) {
            @fclose($handle);
        }
        @unlink($temporary);
        throw $exception;
    }
}

function sa_with_vehicle_lock(callable $callback): mixed
{
    sa_ensure_runtime_directories();
    $lockPath = sa_runtime_dir() . '/vehicles.lock';
    $lock = @fopen($lockPath, 'c+b');
    if ($lock === false) {
        throw new SaStoreException('Vehicle data lock could not be opened.');
    }
    @chmod($lockPath, 0600);
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new SaStoreException('Vehicle data lock could not be acquired.');
        }
        $result = $callback();
        flock($lock, LOCK_UN);
        fclose($lock);
        return $result;
    } catch (Throwable $exception) {
        if (is_resource($lock)) {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
        throw $exception;
    }
}

function sa_replace_vehicle_data(array $data, string $expectedVersion): array
{
    return sa_with_vehicle_lock(static function () use ($data, $expectedVersion): array {
        $current = sa_load_vehicle_data(false, true);
        if ($expectedVersion === '' || !hash_equals($current['version'], $expectedVersion)) {
            throw new SaConflictException('Vehicle data changed after this form was opened. Reload and try again.');
        }
        sa_validate_vehicle_data($data, true);
        $newRaw = sa_encode_vehicle_data($data);
        $backupPath = sa_create_backup($current['raw']);
        try {
            sa_atomic_replace_runtime($newRaw);
            $saved = sa_read_json_file(sa_runtime_json_path(), true);
            if (!hash_equals(hash('sha256', $newRaw), $saved['version'])) {
                throw new SaStoreException('Saved vehicle data verification failed.');
            }
        } catch (Throwable $exception) {
            try {
                sa_atomic_replace_runtime($current['raw']);
            } catch (Throwable $restoreException) {
                error_log('South Africa vehicle data restore failed: ' . $restoreException->getMessage());
            }
            throw $exception;
        }
        return ['version' => hash('sha256', $newRaw), 'backup' => $backupPath];
    });
}

function sa_save_vehicle_record(array $record, ?string $originalRef, string $expectedVersion): array
{
    return sa_with_vehicle_lock(static function () use ($record, $originalRef, $expectedVersion): array {
        $current = sa_load_vehicle_data(false, true);
        if ($expectedVersion === '' || !hash_equals($current['version'], $expectedVersion)) {
            throw new SaConflictException('Vehicle data changed after this form was opened. Reload and try again.');
        }
        $data = $current['data'];
        if ($originalRef !== null) {
            $found = sa_find_vehicle($data, $originalRef);
            if ($found === null) {
                throw new SaConflictException('The vehicle being edited no longer exists.');
            }
            if (($record['ref_id'] ?? '') !== $originalRef) {
                throw new SaValidationException('Ref ID cannot be changed after registration.');
            }
            $removed = array_values(array_diff($found['vehicle']['gallery'] ?? [], $record['gallery'] ?? []));
            $data['vehicles'][$found['index']] = $record;
        } else {
            if (sa_find_vehicle($data, (string)($record['ref_id'] ?? '')) !== null) {
                throw new SaValidationException('This Ref ID is already registered.');
            }
            $removed = [];
            $data['vehicles'][] = $record;
        }
        sa_validate_vehicle_data($data, true);
        $newRaw = sa_encode_vehicle_data($data);
        $backupPath = sa_create_backup($current['raw']);
        try {
            sa_atomic_replace_runtime($newRaw);
            $saved = sa_read_json_file(sa_runtime_json_path(), true);
            if (!hash_equals(hash('sha256', $newRaw), $saved['version'])) {
                throw new SaStoreException('Saved vehicle data verification failed.');
            }
        } catch (Throwable $exception) {
            try {
                sa_atomic_replace_runtime($current['raw']);
            } catch (Throwable $restoreException) {
                error_log('South Africa vehicle data restore failed: ' . $restoreException->getMessage());
            }
            throw $exception;
        }
        sa_remove_created_images($removed);
        return ['version' => hash('sha256', $newRaw), 'backup' => $backupPath];
    });
}

function sa_delete_vehicle_record(string $ref, string $expectedVersion): array
{
    return sa_with_vehicle_lock(static function () use ($ref, $expectedVersion): array {
        $current = sa_load_vehicle_data(false, true);
        if ($expectedVersion === '' || !hash_equals($current['version'], $expectedVersion)) {
            throw new SaConflictException('Vehicle data changed after this form was opened. Reload and try again.');
        }
        $found = sa_find_vehicle($current['data'], $ref);
        if ($found === null) {
            throw new SaConflictException('The vehicle no longer exists.');
        }
        $removed = array_values($found['vehicle']['gallery'] ?? []);
        array_splice($current['data']['vehicles'], $found['index'], 1);
        sa_validate_vehicle_data($current['data'], true);
        $newRaw = sa_encode_vehicle_data($current['data']);
        $backupPath = sa_create_backup($current['raw']);
        try {
            sa_atomic_replace_runtime($newRaw);
        } catch (Throwable $exception) {
            try {
                sa_atomic_replace_runtime($current['raw']);
            } catch (Throwable $restoreException) {
                error_log('South Africa vehicle data restore failed: ' . $restoreException->getMessage());
            }
            throw $exception;
        }
        sa_remove_created_images($removed);
        return ['backup' => $backupPath];
    });
}

function sa_store_uploaded_images(array $files, string $ref): array
{
    if (!preg_match('/^SA-[A-Z0-9]+(?:-[A-Z0-9]+)*$/', $ref)) {
        throw new SaValidationException('A valid Ref ID is required before images can be uploaded.');
    }
    $items = sa_uploaded_file_items($files);
    if ($items === []) {
        return [];
    }
    if (count($items) > 20) {
        throw new SaValidationException('No more than 20 images can be uploaded at once.');
    }
    $mimeMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $validated = [];
    $totalSize = 0;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    foreach ($items as $index => $item) {
        if ($item['error'] !== UPLOAD_ERR_OK) {
            throw new SaValidationException('Image upload failed for file number ' . ($index + 1) . '.');
        }
        $tmp = $item['tmp_name'];
        if (!is_string($tmp) || !is_uploaded_file($tmp)) {
            throw new SaValidationException('Uploaded image could not be verified.');
        }
        $size = (int)$item['size'];
        if ($size <= 0 || $size > 10 * 1024 * 1024) {
            throw new SaValidationException('Each image must be 10 MiB or smaller.');
        }
        $totalSize += $size;
        if ($totalSize > 40 * 1024 * 1024) {
            throw new SaValidationException('The total image upload must be 40 MiB or smaller.');
        }
        $mime = $finfo->file($tmp);
        if (!is_string($mime) || !isset($mimeMap[$mime])) {
            throw new SaValidationException('Only JPEG, PNG, and WebP images are accepted.');
        }
        $dimensions = @getimagesize($tmp);
        if ($dimensions === false) {
            throw new SaValidationException('An uploaded file is not a valid image.');
        }
        $width = (int)$dimensions[0];
        $height = (int)$dimensions[1];
        if ($width <= 0 || $height <= 0 || $width > 12000 || $height > 12000 || ($width * $height) > 40000000) {
            throw new SaValidationException('An image exceeds the allowed dimensions.');
        }
        $validated[] = ['tmp' => $tmp, 'extension' => $mimeMap[$mime]];
    }

    sa_ensure_runtime_directories();
    $directory = sa_runtime_image_dir() . '/' . $ref;
    sa_make_directory($directory, 0700);
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
                throw new SaStoreException('A unique image filename could not be created.');
            }
            if (!move_uploaded_file($item['tmp'], $destination)) {
                @unlink($destination);
                throw new SaStoreException('An uploaded image could not be stored.');
            }
            @chmod($destination, 0600);
            $savedPaths[] = 'images/' . $ref . '/' . basename($destination);
        }
        return $savedPaths;
    } catch (Throwable $exception) {
        sa_remove_created_images($savedPaths);
        throw $exception;
    }
}

function sa_uploaded_file_items(array $files): array
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

function sa_gallery_from_order(string $orderJson, array $existingGallery, array $uploadedPaths): array
{
    try {
        $tokens = json_decode($orderJson, true, 128, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new SaValidationException('Gallery order is invalid.', 0, $exception);
    }
    if (!is_array($tokens) || !array_is_list($tokens)) {
        throw new SaValidationException('Gallery order must be a list.');
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
    $allowed = $oldMap + $newMap;
    $gallery = [];
    $used = [];
    foreach ($tokens as $token) {
        if (!is_string($token) || !array_key_exists($token, $allowed)) {
            throw new SaValidationException('Gallery order contains an unknown image.');
        }
        if (isset($used[$token])) {
            throw new SaValidationException('Gallery contains a duplicate image.');
        }
        $used[$token] = true;
        $gallery[] = $allowed[$token];
    }
    return $gallery;
}

function sa_remove_created_images(array $paths): void
{
    $imageRoot = realpath(sa_runtime_image_dir());
    if ($imageRoot === false) {
        return;
    }
    foreach ($paths as $path) {
        if (!is_string($path) || !str_starts_with($path, 'images/')) {
            continue;
        }
        $suffix = substr($path, strlen('images/'));
        $fullPath = sa_runtime_image_dir() . '/' . $suffix;
        $realPath = realpath($fullPath);
        if ($realPath !== false && str_starts_with($realPath, $imageRoot . DIRECTORY_SEPARATOR) && is_file($realPath)) {
            @unlink($realPath);
        }
    }
}
