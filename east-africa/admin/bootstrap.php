<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/vehicle-store.php';

const EA_ADMIN_IDLE_TIMEOUT = 1800;
const EA_ADMIN_ABSOLUTE_TIMEOUT = 28800;
const EA_LOGIN_WINDOW = 900;
const EA_LOGIN_MAX_FAILURES = 5;
const EA_ADMIN_PASSWORD_MIN_LENGTH = 12;

function ea_admin_request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (ea_environment_value('GLORIA_EA_TRUST_PROXY_HTTPS') !== '1') {
        return false;
    }
    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return $forwardedProto === 'https';
}

function ea_admin_require_https(): void
{
    if (ea_admin_request_is_https()) {
        return;
    }
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        echo "HTTPS is required for East Africa Admin.\n";
        exit;
    }
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/admin/');
    if (!preg_match('/^[a-z0-9.-]+(?::\d+)?$/', $host) || !str_starts_with($uri, '/') || str_contains($uri, "\r") || str_contains($uri, "\n")) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "HTTPS is required for East Africa Admin.\n";
        exit;
    }
    header('Location: https://' . $host . $uri, true, 302);
    exit;
}

function ea_admin_security_headers(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' blob: data:; style-src 'self'; script-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    if (ea_admin_request_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function ea_admin_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('gloria_ea_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/admin/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    if (!session_start(['use_strict_mode' => 1, 'use_only_cookies' => 1])) {
        throw new RuntimeException('Admin session could not be started.');
    }
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

function ea_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ea_csrf_token(): string
{
    return (string)($_SESSION['csrf_token'] ?? '');
}

function ea_verify_csrf(mixed $token): void
{
    $stored = $_SESSION['csrf_token'] ?? '';
    if (!is_string($token) || !is_string($stored) || $stored === '' || !hash_equals($stored, $token)) {
        throw new RuntimeException('The request could not be verified. Reload the page and try again.');
    }
}

function ea_verify_same_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return;
    }
    $scheme = ea_admin_request_is_https() ? 'https' : 'http';
    $requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $expectedOrigin = $scheme . '://' . $requestHost;
    if (!hash_equals($expectedOrigin, strtolower(rtrim($origin, '/')))) {
        throw new RuntimeException('Cross-origin requests are not accepted.');
    }
}

function ea_admin_hash_file_path(): string
{
    return ea_runtime_dir() . '/admin-password.hash';
}

function ea_admin_password_uses_environment(): bool
{
    return ea_environment_value('GLORIA_EA_ADMIN_PASSWORD_HASH') !== '';
}

function ea_admin_password_hash(): string
{
    $environment = ea_environment_value('GLORIA_EA_ADMIN_PASSWORD_HASH');
    if ($environment !== '') {
        return $environment;
    }
    $path = ea_admin_hash_file_path();
    if (!is_readable($path) || is_link($path)) {
        return '';
    }
    return trim((string)file_get_contents($path));
}

function ea_admin_password_is_configured(): bool
{
    $hash = ea_admin_password_hash();
    if ($hash === '') {
        return false;
    }
    $info = password_get_info($hash);
    return ($info['algoName'] ?? 'unknown') !== 'unknown';
}

function ea_rate_limit_directory(): string
{
    return ea_runtime_dir() . '/login-attempts';
}

function ea_client_rate_key(): string
{
    $address = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256', $address);
}

function ea_rate_file(): string
{
    return ea_rate_limit_directory() . '/' . ea_client_rate_key() . '.json';
}

function ea_with_rate_record(callable $callback): mixed
{
    ea_ensure_runtime_directories();
    ea_make_directory(ea_rate_limit_directory(), 0700);
    $path = ea_rate_file();
    $handle = @fopen($path, 'c+b');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        throw new RuntimeException('Login protection is temporarily unavailable.');
    }
    try {
        rewind($handle);
        $raw = stream_get_contents($handle);
        $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
        $timestamps = is_array($decoded) ? $decoded : [];
        $cutoff = time() - EA_LOGIN_WINDOW;
        $timestamps = array_values(array_filter($timestamps, static fn($value): bool => is_int($value) && $value >= $cutoff));
        $result = $callback($timestamps);
        ftruncate($handle, 0);
        rewind($handle);
        ea_write_all($handle, json_encode($timestamps, JSON_THROW_ON_ERROR));
        flock($handle, LOCK_UN);
        fclose($handle);
        @chmod($path, 0600);
        return $result;
    } catch (Throwable $exception) {
        flock($handle, LOCK_UN);
        fclose($handle);
        throw $exception;
    }
}

function ea_login_retry_after(): int
{
    return ea_with_rate_record(static function (array &$timestamps): int {
        if (count($timestamps) < EA_LOGIN_MAX_FAILURES) {
            return 0;
        }
        return max(1, EA_LOGIN_WINDOW - (time() - min($timestamps)));
    });
}

function ea_record_login_failure(): void
{
    ea_with_rate_record(static function (array &$timestamps): null {
        $timestamps[] = time();
        return null;
    });
}

function ea_clear_login_failures(): void
{
    $path = ea_rate_file();
    if (is_file($path)) {
        @unlink($path);
    }
}

function ea_attempt_login(string $password): void
{
    $retryAfter = ea_login_retry_after();
    if ($retryAfter > 0) {
        throw new RuntimeException('Too many login attempts. Try again later.');
    }
    $hash = ea_admin_password_hash();
    if ($hash === '' || !ea_admin_password_is_configured()) {
        throw new RuntimeException('East Africa admin authentication is not configured.');
    }
    if (!password_verify($password, $hash)) {
        ea_record_login_failure();
        usleep(random_int(250000, 500000));
        throw new RuntimeException('The password is incorrect.');
    }
    ea_clear_login_failures();
    session_regenerate_id(true);
    $now = time();
    $_SESSION['ea_admin_logged_in'] = true;
    $_SESSION['ea_admin_login_at'] = $now;
    $_SESSION['ea_admin_last_activity'] = $now;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function ea_change_admin_password(string $current, string $new, string $confirm): void
{
    if (ea_admin_password_uses_environment()) {
        throw new RuntimeException('This password is set by a server environment variable and cannot be changed here.');
    }
    if (!ea_admin_password_is_configured()) {
        throw new RuntimeException('East Africa admin authentication is not configured.');
    }
    $hash = ea_admin_password_hash();
    if ($hash === '' || !password_verify($current, $hash)) {
        usleep(random_int(250000, 500000));
        throw new RuntimeException('The current password is incorrect.');
    }
    if (!hash_equals($new, $confirm)) {
        throw new RuntimeException('The new password confirmation does not match.');
    }
    if (strlen($new) < EA_ADMIN_PASSWORD_MIN_LENGTH) {
        throw new RuntimeException('The new password must be at least ' . EA_ADMIN_PASSWORD_MIN_LENGTH . ' characters.');
    }
    if (hash_equals($current, $new)) {
        throw new RuntimeException('The new password must be different from the current password.');
    }

    $path = ea_admin_hash_file_path();
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException('The password could not be stored.');
    }

    $newHash = password_hash($new, PASSWORD_DEFAULT);
    if (!is_string($newHash) || $newHash === '') {
        throw new RuntimeException('The password could not be stored.');
    }
    $info = password_get_info($newHash);
    if (($info['algoName'] ?? 'unknown') === 'unknown') {
        throw new RuntimeException('The password could not be stored.');
    }

    ea_ensure_runtime_directories();
    $temporary = ea_runtime_dir() . '/.admin-password-' . bin2hex(random_bytes(8)) . '.tmp';
    $handle = @fopen($temporary, 'x');
    if ($handle === false) {
        throw new RuntimeException('The password could not be stored.');
    }
    try {
        ea_write_all($handle, $newHash);
        if (!fclose($handle)) {
            throw new RuntimeException('The password could not be stored.');
        }
        $handle = null;
        if (!chmod($temporary, 0600)) {
            throw new RuntimeException('The password could not be stored.');
        }
        clearstatcache(true, $temporary);
        $stored = file_get_contents($temporary);
        $mode = fileperms($temporary);
        if (!is_string($stored) || $stored !== $newHash) {
            throw new RuntimeException('The password could not be stored.');
        }
        if (DIRECTORY_SEPARATOR === '/' && (!is_int($mode) || (($mode & 0777) !== 0600))) {
            throw new RuntimeException('The password could not be stored.');
        }
        if (!password_verify($new, $stored)) {
            throw new RuntimeException('The password could not be stored.');
        }
        if (!rename($temporary, $path)) {
            throw new RuntimeException('The password could not be stored.');
        }
        @chmod($path, 0600);
        clearstatcache(true, $path);
        $final = file_get_contents($path);
        if (!is_string($final) || !password_verify($new, $final) || password_verify($current, $final)) {
            throw new RuntimeException('The password could not be stored.');
        }
    } catch (Throwable $exception) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        @unlink($temporary);
        throw $exception;
    }
}

function ea_admin_is_logged_in(): bool
{
    if (empty($_SESSION['ea_admin_logged_in'])) {
        return false;
    }
    $now = time();
    $loginAt = (int)($_SESSION['ea_admin_login_at'] ?? 0);
    $lastActivity = (int)($_SESSION['ea_admin_last_activity'] ?? 0);
    if ($loginAt <= 0 || $lastActivity <= 0 || ($now - $lastActivity) > EA_ADMIN_IDLE_TIMEOUT || ($now - $loginAt) > EA_ADMIN_ABSOLUTE_TIMEOUT) {
        ea_admin_logout();
        return false;
    }
    $_SESSION['ea_admin_last_activity'] = $now;
    return true;
}

function ea_require_admin(): void
{
    if (!ea_admin_is_logged_in()) {
        ea_redirect('index.php?expired=1');
    }
}

function ea_admin_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function ea_redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

function ea_flash(string $type, string $message): void
{
    $_SESSION['ea_flash'] = ['type' => $type, 'message' => $message];
}

function ea_take_flash(): ?array
{
    $flash = $_SESSION['ea_flash'] ?? null;
    unset($_SESSION['ea_flash']);
    return is_array($flash) ? $flash : null;
}

if (PHP_SAPI !== 'cli') {
    ea_admin_require_https();
}
ea_admin_security_headers();
ea_admin_start_session();
