<?php
/**
 * Shared path configuration for admin (frontend/ + west_africa/ layout).
 */

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
        echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><title>管理画面エラー</title></head><body>';
        echo '<h1>管理画面でエラーが発生しました</h1>';
        echo '<p>サーバー設定またはデプロイ内容を確認してください。</p>';
        echo '<pre style="background:#f5f5f5;padding:1rem;overflow:auto;">';
        echo htmlspecialchars($error['message'] . ' in ' . $error['file'] . ':' . $error['line'], ENT_QUOTES, 'UTF-8');
        echo '</pre></body></html>';
    });
}

function gt_admin_verify_environment(bool $strict = true): void
{
    $frontend_dir = dirname(__DIR__) . '/frontend';
    $checks = [
        'frontend directory' => is_dir($frontend_dir),
        'vehicles.json' => is_file($frontend_dir . '/data/vehicles.json'),
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
    define('VEHICLES_JSON', FRONTEND_DIR . '/data/vehicles.json');
}
if (!defined('IMAGES_DIR')) {
    define('IMAGES_DIR', FRONTEND_DIR . '/images/vehicles/');
}
if (!defined('IMAGES_URL')) {
    define('IMAGES_URL', '../images/vehicles/');
}
if (!defined('GENERAL_UPLOAD_DIR')) {
    define('GENERAL_UPLOAD_DIR', FRONTEND_DIR . '/uploads/general/');
}
if (!defined('GENERAL_UPLOAD_URL')) {
    define('GENERAL_UPLOAD_URL', 'uploads/general/');
}

if (!defined('QUOTE_UPLOAD_DIR')) {
    define('QUOTE_UPLOAD_DIR', FRONTEND_DIR . '/uploads/quotes/');
}
if (!defined('QUOTE_UPLOAD_URL')) {
    define('QUOTE_UPLOAD_URL', 'uploads/quotes/');
}

function getConfiguredAdminPassword(): string
{
    $env_password = getenv('GLORIA_ADMIN_PASSWORD');
    if (is_string($env_password) && trim($env_password) !== '') {
        return trim($env_password);
    }

    $password_file = __DIR__ . '/password.txt';
    if (is_readable($password_file)) {
        $file_password = trim((string) file_get_contents($password_file));
        if ($file_password !== '') {
            return $file_password;
        }
    }

    return '';
}
