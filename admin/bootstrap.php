<?php
/**
 * Shared path configuration for admin (frontend/ + west_africa/ layout).
 *
 * Operational records live outside the web root. The repository JSON is only
 * a public catalog snapshot for local preview and Netlify.
 */

function gt_wa_env(string $name): string
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

function gt_wa_repo_root(): string
{
    return dirname(__DIR__);
}

function gt_wa_site_root(): string
{
    $root = gt_wa_repo_root();
    $real = realpath($root);
    return $real !== false ? $real : $root;
}

/**
 * Test and production never share a default directory.
 * Any other site root, including local checkouts, has no implicit storage.
 */
function gt_wa_private_dir_for_site(string $siteRoot): ?string
{
    $siteRoot = rtrim($siteRoot, '/');
    if ($siteRoot === '/home/gltr/www/gloria-test') {
        return '/home/gltr/private/west-africa-test';
    }
    if ($siteRoot === '/home/gltr/www/gloria-site') {
        return '/home/gltr/private/west-africa';
    }
    return null;
}

function gt_wa_private_dir(): ?string
{
    $configured = gt_wa_env('GLORIA_WA_DATA_DIR');
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    return gt_wa_private_dir_for_site(gt_wa_site_root());
}

function gt_wa_private_storage_ready(): bool
{
    $dir = gt_wa_private_dir();
    return $dir !== null && is_dir($dir);
}

function gt_wa_public_snapshot_path(): string
{
    return gt_wa_repo_root() . '/frontend/data/vehicles.json';
}

function gt_wa_vehicles_read_path(): ?string
{
    $private = gt_wa_private_dir();
    if ($private !== null) {
        $path = $private . '/vehicles.json';
        return is_file($path) ? $path : null;
    }
    $snapshot = gt_wa_public_snapshot_path();
    return is_file($snapshot) ? $snapshot : null;
}

function gt_wa_vehicles_write_path(): ?string
{
    $private = gt_wa_private_dir();
    if ($private === null || !is_dir($private)) {
        return null;
    }
    $path = $private . '/vehicles.json';
    return is_file($path) ? $path : null;
}

function gt_wa_feed_source_path(): ?string
{
    $private = gt_wa_private_dir();
    if ($private !== null) {
        $path = $private . '/vehicles.json';
        return is_readable($path) ? $path : null;
    }
    $snapshot = gt_wa_public_snapshot_path();
    return is_readable($snapshot) ? $snapshot : null;
}

function gt_wa_backup_dir(): ?string
{
    $private = gt_wa_private_dir();
    if ($private === null) {
        return null;
    }
    return $private . '/backups';
}

function gt_wa_backup_archive_dir(): ?string
{
    $private = gt_wa_private_dir();
    if ($private === null) {
        return null;
    }
    return $private . '/archive/backups';
}

function gt_wa_password_path(): ?string
{
    $private = gt_wa_private_dir();
    if ($private === null) {
        return null;
    }
    return $private . '/password.txt';
}

function gt_wa_invoice_sequence_path(): ?string
{
    $private = gt_wa_private_dir();
    if ($private === null || !is_dir($private)) {
        return null;
    }
    return $private . '/invoice-sequence.json';
}

function gt_wa_invoice_bank_defaults(): array
{
    return [
        'bank_name' => '',
        'swift_code' => '',
        'branch_name' => '',
        'branch_phone' => '',
        'account_name' => '',
        'account_number' => '',
        'branch_address' => '',
    ];
}

function gt_wa_invoice_bank(): array
{
    $bank = gt_wa_invoice_bank_defaults();
    $private = gt_wa_private_dir();
    if ($private === null || !is_dir($private)) {
        return $bank;
    }
    $path = $private . '/invoice-bank.php';
    if (!is_file($path)) {
        return $bank;
    }
    $root = realpath($private);
    $file = realpath($path);
    if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
        return $bank;
    }
    $loaded = require $file;
    if (is_array($loaded) && isset($loaded['bank']) && is_array($loaded['bank'])) {
        $loaded = $loaded['bank'];
    }
    if (!is_array($loaded)) {
        return $bank;
    }
    foreach ($bank as $key => $unused) {
        $value = $loaded[$key] ?? '';
        if (is_string($value) && strlen($value) <= 200) {
            $bank[$key] = $value;
        }
    }
    return $bank;
}

function gt_wa_save_unavailable_message(): string
{
    return '保存できません。業務データの保存先が利用できないため、変更は書き込まれていません。';
}

function gt_wa_path_is_under(string $path, string $root): bool
{
    $realRoot = realpath($root);
    $realPath = realpath($path);
    if ($realRoot === false || $realPath === false) {
        return false;
    }
    return $realPath === $realRoot || str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR);
}

function gt_redact_private_paths(string $text): string
{
    $private = gt_wa_private_dir();
    if (is_string($private) && $private !== '') {
        $text = str_replace($private, '[private]', $text);
    }
    $redacted = preg_replace('#/home/gltr/private(?:/[^\s\'"]*)?#', '[private]', $text);
    return is_string($redacted) ? $redacted : $text;
}

function gt_admin_register_error_handler(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }
        $file = (string)($error['file'] ?? '');
        $private = gt_wa_private_dir();
        if ($private !== null && $private !== '' && str_starts_with($file, $private)) {
            $file = '[private]';
        }
        $message = gt_redact_private_paths((string)($error['message'] ?? ''));
        $file = gt_redact_private_paths($file);
        echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><title>管理画面エラー</title></head><body>';
        echo '<h1>管理画面でエラーが発生しました</h1>';
        echo '<p>サーバー設定またはデプロイ内容を確認してください。</p>';
        echo '<pre style="background:#f5f5f5;padding:1rem;overflow:auto;">';
        echo htmlspecialchars($message . ' in ' . $file . ':' . (int)($error['line'] ?? 0), ENT_QUOTES, 'UTF-8');
        echo '</pre></body></html>';
    });
}

function gt_admin_verify_environment(bool $strict = true): void
{
    $frontend_dir = dirname(__DIR__) . '/frontend';
    $vehicle_path = gt_wa_vehicles_read_path();
    $checks = [
        'frontend directory' => is_dir($frontend_dir),
        'vehicles.json' => $vehicle_path !== null && is_file($vehicle_path),
        'vehicle-data.php' => is_file(__DIR__ . '/vehicle-data.php'),
    ];

    $missing = [];
    foreach ($checks as $label => $ok) {
        if (!$ok) {
            $missing[] = $label;
        }
    }

    if ($missing === [] || (!$strict && count($missing) === 1 && $missing[0] === 'vehicles.json')) {
        return;
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }

    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><title>管理画面設定エラー</title></head><body>';
    echo '<h1>管理画面の配置パスが正しくありません</h1>';
    echo '<p>次の項目が見つかりません:</p><ul>';
    foreach ($missing as $item) {
        echo '<li>' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    echo '</ul>';
    echo '<p><code>frontend/</code> 構成へのデプロイ後、ルートの <code>scripts/sakura-publish-links.sh</code> を実行してください。</p>';
    echo '</body></html>';
    exit;
}

if (!defined('FRONTEND_DIR')) {
    define('FRONTEND_DIR', dirname(__DIR__) . '/frontend');
}
if (!defined('VEHICLES_JSON')) {
    $gt_wa_read_path = gt_wa_vehicles_read_path();
    if ($gt_wa_read_path === null) {
        $gt_wa_private = gt_wa_private_dir();
        $gt_wa_read_path = ($gt_wa_private !== null ? $gt_wa_private : FRONTEND_DIR . '/data') . '/vehicles.json';
    }
    define('VEHICLES_JSON', $gt_wa_read_path);
}
if (!defined('IMAGES_DIR')) {
    define('IMAGES_DIR', FRONTEND_DIR . '/images/vehicles/');
}
if (!defined('IMAGES_URL')) {
    define('IMAGES_URL', '../images/vehicles/');
}

$gt_wa_private_root = gt_wa_private_dir();
if (!defined('QUOTE_UPLOAD_DIR')) {
    define('QUOTE_UPLOAD_DIR', $gt_wa_private_root !== null ? $gt_wa_private_root . '/uploads/quotes/' : '');
}
if (!defined('CERTIFICATE_UPLOAD_DIR')) {
    define('CERTIFICATE_UPLOAD_DIR', $gt_wa_private_root !== null ? $gt_wa_private_root . '/uploads/certificates/' : '');
}
if (!defined('GENERAL_UPLOAD_DIR')) {
    define('GENERAL_UPLOAD_DIR', $gt_wa_private_root !== null ? $gt_wa_private_root . '/uploads/general/' : '');
}

function getConfiguredAdminPassword(): string
{
    $env_password = getenv('GLORIA_ADMIN_PASSWORD');
    if (is_string($env_password) && trim($env_password) !== '') {
        return trim($env_password);
    }

    $password_file = gt_wa_password_path();
    if ($password_file !== null && is_readable($password_file)) {
        $file_password = trim((string) file_get_contents($password_file));
        if ($file_password !== '') {
            return $file_password;
        }
    }

    return '';
}

function gt_wa_mkdir_private(string $dir): bool
{
    $private = gt_wa_private_dir();
    if ($private === null || !is_dir($private)) {
        return false;
    }
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }
    if (!gt_wa_path_is_under($dir, $private) && realpath($dir) !== realpath($private)) {
        return false;
    }
    @chmod($dir, 0700);
    return true;
}
