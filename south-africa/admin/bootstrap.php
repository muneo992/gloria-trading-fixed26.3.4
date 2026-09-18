<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/vehicle-store.php';
require_once dirname(__DIR__) . '/lib/admin-auth.php';

const SA_ADMIN_IDLE_TIMEOUT = 1800;
const SA_ADMIN_ABSOLUTE_TIMEOUT = 28800;
const SA_LOGIN_WINDOW = 900;
const SA_LOGIN_MAX_FAILURES = 5;

function sa_admin_request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (sa_environment_value('GLORIA_SA_TRUST_PROXY_HTTPS') !== '1') {
        return false;
    }
    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return $forwardedProto === 'https';
}

function sa_admin_allow_insecure_http(): bool
{
    return sa_environment_value('GLORIA_SA_ALLOW_INSECURE_HTTP') === '1';
}

function sa_admin_require_https(): void
{
    if (sa_admin_request_is_https() || sa_admin_allow_insecure_http()) {
        return;
    }
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        echo "HTTPS is required for South Africa Admin.\n";
        exit;
    }
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/admin/');
    if (!preg_match('/^[a-z0-9.-]+(?::\d+)?$/', $host) || !str_starts_with($uri, '/') || str_contains($uri, "\r") || str_contains($uri, "\n")) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "HTTPS is required for South Africa Admin.\n";
        exit;
    }
    header('Location: https://' . $host . $uri, true, 302);
    exit;
}

function sa_admin_security_headers(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' blob: data:; style-src 'self'; script-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    if (sa_admin_request_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function sa_admin_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('gloria_sa_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/admin/',
        'secure' => sa_admin_request_is_https() || !sa_admin_allow_insecure_http(),
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

function sa_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sa_csrf_token(): string
{
    return (string)($_SESSION['csrf_token'] ?? '');
}

function sa_verify_csrf(mixed $token): void
{
    $stored = $_SESSION['csrf_token'] ?? '';
    if (!is_string($token) || !is_string($stored) || $stored === '' || !hash_equals($stored, $token)) {
        throw new RuntimeException('The request could not be verified. Reload the page and try again.');
    }
}

function sa_verify_same_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return;
    }
    $scheme = sa_admin_request_is_https() ? 'https' : 'http';
    $requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $expectedOrigin = $scheme . '://' . $requestHost;
    if (!hash_equals($expectedOrigin, strtolower(rtrim($origin, '/')))) {
        throw new RuntimeException('Cross-origin requests are not accepted.');
    }
}

function sa_rate_limit_directory(): string
{
    return sa_runtime_dir() . '/login-attempts';
}

function sa_client_rate_key(): string
{
    $address = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256', $address);
}

function sa_rate_file(): string
{
    return sa_rate_limit_directory() . '/' . sa_client_rate_key() . '.json';
}

function sa_with_rate_record(callable $callback): mixed
{
    sa_ensure_runtime_directories();
    sa_make_directory(sa_rate_limit_directory(), 0700);
    $path = sa_rate_file();
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
        $cutoff = time() - SA_LOGIN_WINDOW;
        $timestamps = array_values(array_filter($timestamps, static fn($value): bool => is_int($value) && $value >= $cutoff));
        $result = $callback($timestamps);
        ftruncate($handle, 0);
        rewind($handle);
        sa_write_all($handle, json_encode($timestamps, JSON_THROW_ON_ERROR));
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

function sa_login_retry_after(): int
{
    return sa_with_rate_record(static function (array &$timestamps): int {
        if (count($timestamps) < SA_LOGIN_MAX_FAILURES) {
            return 0;
        }
        return max(1, SA_LOGIN_WINDOW - (time() - min($timestamps)));
    });
}

function sa_record_login_failure(): void
{
    sa_with_rate_record(static function (array &$timestamps): null {
        $timestamps[] = time();
        return null;
    });
}

function sa_clear_login_failures(): void
{
    $path = sa_rate_file();
    if (is_file($path)) {
        @unlink($path);
    }
}

function sa_attempt_login(string $password): void
{
    $retryAfter = sa_login_retry_after();
    if ($retryAfter > 0) {
        throw new RuntimeException('Too many login attempts. Try again later.');
    }
    $hash = sa_admin_password_hash();
    if ($hash === '' || !sa_admin_password_is_configured()) {
        throw new RuntimeException('South Africa admin authentication is not configured.');
    }
    if (!password_verify($password, $hash)) {
        sa_record_login_failure();
        usleep(random_int(250000, 500000));
        throw new RuntimeException('The password is incorrect.');
    }
    sa_clear_login_failures();
    sa_establish_admin_session();
}

function sa_establish_admin_session(): void
{
    session_regenerate_id(true);
    $now = time();
    $_SESSION['sa_admin_logged_in'] = true;
    $_SESSION['sa_admin_login_at'] = $now;
    $_SESSION['sa_admin_last_activity'] = $now;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function sa_guarded_bootstrap(string $token, string $password, string $confirm): void
{
    $retryAfter = sa_login_retry_after();
    if ($retryAfter > 0) {
        throw new RuntimeException('Too many login attempts. Try again later.');
    }
    try {
        sa_attempt_bootstrap($token, $password, $confirm);
    } catch (Throwable $exception) {
        sa_record_login_failure();
        usleep(random_int(250000, 500000));
        throw $exception;
    }
    sa_clear_login_failures();
    sa_establish_admin_session();
}

function sa_admin_is_logged_in(): bool
{
    if (empty($_SESSION['sa_admin_logged_in'])) {
        return false;
    }
    $now = time();
    $loginAt = (int)($_SESSION['sa_admin_login_at'] ?? 0);
    $lastActivity = (int)($_SESSION['sa_admin_last_activity'] ?? 0);
    if ($loginAt <= 0 || $lastActivity <= 0 || ($now - $lastActivity) > SA_ADMIN_IDLE_TIMEOUT || ($now - $loginAt) > SA_ADMIN_ABSOLUTE_TIMEOUT) {
        sa_admin_logout();
        return false;
    }
    $_SESSION['sa_admin_last_activity'] = $now;
    return true;
}

function sa_require_admin(): void
{
    if (!sa_admin_is_logged_in()) {
        sa_redirect('index.php?expired=1');
    }
}

function sa_admin_logout(): void
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

function sa_redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

function sa_flash(string $type, string $message): void
{
    $_SESSION['sa_flash'] = ['type' => $type, 'message' => $message];
}

function sa_take_flash(): ?array
{
    $flash = $_SESSION['sa_flash'] ?? null;
    unset($_SESSION['sa_flash']);
    return is_array($flash) ? $flash : null;
}

if (PHP_SAPI !== 'cli') {
    sa_admin_require_https();
    sa_admin_security_headers();
    sa_admin_start_session();
}
