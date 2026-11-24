<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const GT_LHD_TRADE_TERMS = ['FOB', 'CFR', 'CIF'];
const GT_LHD_CURRENCIES = ['USD', 'AED', 'EUR', 'JPY'];
const GT_LHD_REF_PATTERN = '/^LHD-[A-F0-9]{12}$/D';
const GT_LHD_IMAGE_PATTERN = '/^[a-f0-9]{32}\.jpg$/D';

function gt_lhd_directory(bool $create = false): ?string
{
    $base = gt_wa_private_dir();
    if ($base === null) {
        return null;
    }
    $real = realpath($base);
    $site = gt_wa_site_root();
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if ($real === false || gt_wa_path_is_under($real, $site)
        || (str_starts_with($site, '/home/gltr/www/') && gt_wa_path_is_under($real, '/home/gltr/www'))
        || ($documentRoot !== '' && gt_wa_path_is_under($real, $documentRoot))) {
        throw new RuntimeException('Private LHD storage is unavailable.');
    }
    $dir = $real . '/lhd';
    if (is_link($dir) || (file_exists($dir) && !is_dir($dir)) || ($create && !gt_wa_mkdir_private($dir))) {
        throw new RuntimeException('Private LHD storage is unavailable.');
    }
    return $dir;
}

function gt_lhd_read(?string $directory): array
{
    if ($directory === null) {
        return [];
    }
    $path = $directory . '/lhd-vehicles.json';
    if (is_link($path)) {
        throw new RuntimeException('Invalid LHD master.');
    }
    if (!file_exists($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('LHD master is unavailable.');
    }
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || !isset($decoded['vehicles']) || !is_array($decoded['vehicles'])) {
        throw new RuntimeException('Invalid LHD master.');
    }
    foreach ($decoded['vehicles'] as $ref => $record) {
        if (!is_string($ref) || !preg_match(GT_LHD_REF_PATTERN, $ref) || !is_array($record)) {
            throw new RuntimeException('Invalid LHD record.');
        }
        $validated = gt_lhd_validate([...$record, 'confirmed' => ($record['confirmed'] ?? false) === true ? '1' : '']);
        foreach ($validated as $field => $value) {
            if (($record[$field] ?? null) !== $value) {
                throw new RuntimeException('Invalid LHD record.');
            }
        }
    }
    return $decoded['vehicles'];
}

function gt_lhd_version(?array $record): string
{
    return hash('sha256', json_encode($record, JSON_THROW_ON_ERROR));
}

function gt_lhd_image_path(?string $directory, array $record): ?string
{
    $image = $record['image'] ?? '';
    if ($directory === null || !is_string($image) || !preg_match(GT_LHD_IMAGE_PATTERN, $image)) {
        return null;
    }
    $path = $directory . '/' . $image;
    return is_file($path) && !is_link($path) && is_readable($path) ? $path : null;
}

function gt_lhd_valid_date(string $value): bool
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1
        && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
}

function gt_lhd_eligible(array $record, string $today): bool
{
    $confirmed = $record['confirmed_on'] ?? '';
    $expires = $record['expires_on'] ?? '';
    return ($record['confirmed'] ?? false) === true
        && ($record['published'] ?? false) === true
        && is_string($confirmed) && gt_lhd_valid_date($confirmed) && $confirmed <= $today
        && is_string($expires) && gt_lhd_valid_date($expires) && $today <= $expires;
}

function gt_lhd_public_record(string $ref, array $record, bool $hasImage): array
{
    $public = ['ref' => $ref, 'has_image' => $hasImage];
    foreach (['make', 'model', 'year', 'mileage', 'trade_term', 'destination_port'] as $field) {
        $public[$field] = $record[$field];
    }
    if ($record['price_mode'] === 'display') {
        $public['price'] = $record['price'];
        $public['currency'] = $record['currency'];
    }
    return $public;
}

function gt_lhd_validate(array $post): array
{
    $record = [];
    $limits = [
        'make' => 60, 'model' => 80, 'year' => 4, 'mileage' => 7,
        'confirmed_on' => 10, 'expires_on' => 10, 'price_mode' => 7,
        'price' => 12, 'currency' => 3, 'trade_term' => 3, 'destination_port' => 100,
    ];
    foreach ($limits as $field => $limit) {
        $value = $post[$field] ?? '';
        if (!is_string($value) || strlen($value) > $limit || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new DomainException('入力形式を確認してください。');
        }
        $record[$field] = trim($value);
    }
    if ($record['make'] === '' || $record['model'] === ''
        || !preg_match('/^\d{4}$/D', $record['year'])
        || (int) $record['year'] < 1900 || (int) $record['year'] > (int) gmdate('Y') + 1
        || ($record['mileage'] !== '' && !ctype_digit($record['mileage']))) {
        throw new DomainException('メーカー、車種、年式と走行距離を確認してください。');
    }
    foreach (['confirmed_on', 'expires_on'] as $field) {
        if ($record[$field] !== '' && !gt_lhd_valid_date($record[$field])) {
            throw new DomainException('確認日と掲載期限を確認してください。');
        }
    }
    if (!in_array($record['price_mode'], ['quote', 'display'], true)
        || !in_array($record['trade_term'], ['', ...GT_LHD_TRADE_TERMS], true)) {
        throw new DomainException('価格表示と取引条件を確認してください。');
    }
    if ($record['price_mode'] === 'display') {
        if (!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', $record['price'])
            || (float) $record['price'] <= 0
            || !in_array($record['currency'], GT_LHD_CURRENCIES, true)
            || $record['trade_term'] === ''
            || (in_array($record['trade_term'], ['CFR', 'CIF'], true) && $record['destination_port'] === '')) {
            throw new DomainException('価格表示には正の金額、通貨、取引条件が必要です。C&F・CIFでは仕向港も入力してください。');
        }
    } else {
        $record['price'] = '';
        $record['currency'] = '';
    }
    if (!in_array($record['trade_term'], ['CFR', 'CIF'], true)) {
        $record['destination_port'] = '';
    }
    $record['confirmed'] = ($post['confirmed'] ?? '') === '1';
    return $record;
}

function gt_lhd_store_image(string $directory, array $file): string
{
    $source = $file['tmp_name'] ?? '';
    if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($source) || !is_uploaded_file($source)
        || ($file['size'] ?? 0) > 8 * 1024 * 1024) {
        throw new DomainException('画像は8 MB以下のJPEGまたはPNGを指定してください。');
    }
    $size = @getimagesize($source);
    if ($size === false || $size[0] * $size[1] > 12000000
        || !in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
        throw new DomainException('画像は1200万画素以下のJPEGまたはPNGを指定してください。');
    }
    if (!function_exists('imagecreatefromjpeg') || !function_exists('imagecreatefrompng')) {
        throw new DomainException('画像処理に必要なPHP GDが利用できません。');
    }
    $image = $size[2] === IMAGETYPE_JPEG ? @imagecreatefromjpeg($source) : @imagecreatefrompng($source);
    if ($image === false) {
        throw new DomainException('画像を読み取れませんでした。');
    }
    $name = bin2hex(random_bytes(16)) . '.jpg';
    $path = $directory . '/' . $name;
    if (!@imagejpeg($image, $path, 85) || !@chmod($path, 0600)) {
        @unlink($path);
        throw new RuntimeException('LHD image save failed.');
    }
    return $name;
}

function gt_lhd_write(string $directory, array $records): void
{
    $json = json_encode(['vehicles' => (object) $records], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $temporary = tempnam($directory, '.lhd-');
    if ($temporary === false) {
        throw new RuntimeException('LHD save failed.');
    }
    try {
        if (file_put_contents($temporary, $json) !== strlen($json) || !chmod($temporary, 0600)
            || !rename($temporary, $directory . '/lhd-vehicles.json')) {
            throw new RuntimeException('LHD save failed.');
        }
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

function gt_lhd_save(string $directory, string $ref, string $version, array $record, ?array $file): string
{
    $umask = umask(0077);
    $lockPath = $directory . '/master.lock';
    $lock = null;
    $newImage = null;
    try {
        $lock = is_link($lockPath) ? false : fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('LHD save lock is unavailable.');
        }
        $records = gt_lhd_read($directory);
        $previous = $records[$ref] ?? null;
        if (($ref !== '' && $previous === null) || !hash_equals(gt_lhd_version($previous), $version)) {
            throw new DomainException('他の操作で更新されました。ページを開き直してください。');
        }
        $record['image'] = ($record['remove_image'] ?? false) ? '' : ($previous['image'] ?? '');
        unset($record['remove_image']);
        if ($file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $newImage = gt_lhd_store_image($directory, $file);
            $record['image'] = $newImage;
        }
        if ($ref === '') {
            do {
                $ref = 'LHD-' . strtoupper(bin2hex(random_bytes(6)));
            } while (isset($records[$ref]));
        }
        $records[$ref] = $record;
        gt_lhd_write($directory, $records);
        $newImage = null;
        if ($previous !== null && ($previous['image'] ?? '') !== $record['image']) {
            $oldImage = gt_lhd_image_path($directory, $previous);
            if ($oldImage !== null) {
                @unlink($oldImage);
            }
        }
        return $ref;
    } finally {
        if ($newImage !== null) {
            @unlink($directory . '/' . $newImage);
        }
        if (is_resource($lock)) {
            fclose($lock);
        }
        umask($umask);
    }
}

function gt_lhd_send_image(?string $path): void
{
    if ($path === null || ($stream = @fopen($path, 'rb')) === false) {
        http_response_code(404);
        return;
    }
    header('Content-Type: image/jpeg');
    fpassthru($stream);
    fclose($stream);
}
