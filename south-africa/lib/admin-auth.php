<?php
declare(strict_types=1);

const SA_ADMIN_PASSWORD_MIN_LENGTH = 12;

function sa_admin_hash_file_path(): string
{
    return sa_runtime_dir() . '/admin-password.hash';
}

function sa_bootstrap_token_path(): string
{
    return sa_runtime_dir() . '/bootstrap.token';
}

function sa_admin_password_hash(): string
{
    $environment = sa_environment_value('GLORIA_SA_ADMIN_PASSWORD_HASH');
    if ($environment !== '') {
        return $environment;
    }
    $path = sa_admin_hash_file_path();
    if (!is_readable($path) || is_link($path)) {
        return '';
    }
    return trim((string)file_get_contents($path));
}

function sa_admin_password_is_configured(): bool
{
    $hash = sa_admin_password_hash();
    if ($hash === '') {
        return false;
    }
    $info = password_get_info($hash);
    return ($info['algoName'] ?? 'unknown') !== 'unknown';
}

function sa_bootstrap_token_value(): string
{
    $path = sa_bootstrap_token_path();
    if (!is_readable($path) || is_link($path)) {
        return '';
    }
    $token = trim((string)file_get_contents($path));
    if ($token === '' || strlen($token) < 32) {
        return '';
    }
    return $token;
}

function sa_admin_can_bootstrap(): bool
{
    if (sa_environment_value('GLORIA_SA_ADMIN_PASSWORD_HASH') !== '') {
        return false;
    }
    if (sa_admin_password_is_configured()) {
        return false;
    }
    $hashPath = sa_admin_hash_file_path();
    if (file_exists($hashPath) || is_link($hashPath)) {
        return false;
    }
    return sa_bootstrap_token_value() !== '';
}

function sa_consume_bootstrap_token(): void
{
    $path = sa_bootstrap_token_path();
    if (is_file($path)) {
        @unlink($path);
    }
}

function sa_write_admin_password_hash(string $password): void
{
    if (sa_environment_value('GLORIA_SA_ADMIN_PASSWORD_HASH') !== '') {
        throw new RuntimeException('South Africa admin authentication is already configured.');
    }
    if (strlen($password) < SA_ADMIN_PASSWORD_MIN_LENGTH) {
        throw new RuntimeException('The password must be at least 12 characters.');
    }

    $path = sa_admin_hash_file_path();
    $dir = sa_runtime_dir();
    if ($dir === '/home/gltr/ea-admin-data' || str_contains($dir, 'ea-admin-data')) {
        throw new RuntimeException('South Africa admin data directory is invalid.');
    }
    if (!is_dir($dir) || is_link($dir)) {
        throw new RuntimeException('The South Africa admin data directory is not ready.');
    }
    if (file_exists($path) || is_link($path)) {
        throw new RuntimeException('South Africa admin authentication is already configured.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash) || $hash === '') {
        throw new RuntimeException('The password could not be stored.');
    }
    $info = password_get_info($hash);
    if (($info['algoName'] ?? 'unknown') === 'unknown') {
        throw new RuntimeException('The password could not be stored.');
    }

    sa_ensure_runtime_directories();
    $handle = @fopen($path, 'x');
    if ($handle === false) {
        throw new RuntimeException('South Africa admin authentication is already configured.');
    }
    try {
        sa_write_all($handle, $hash);
        if (!fclose($handle)) {
            throw new RuntimeException('The password could not be stored.');
        }
        $handle = null;
        if (!chmod($path, 0600)) {
            @unlink($path);
            throw new RuntimeException('The password could not be stored.');
        }
        clearstatcache(true, $path);
        $stored = file_get_contents($path);
        $mode = fileperms($path);
        if (!is_string($stored) || $stored !== $hash) {
            @unlink($path);
            throw new RuntimeException('The password could not be stored.');
        }
        if (!is_int($mode) || (($mode & 0777) !== 0600)) {
            @unlink($path);
            throw new RuntimeException('The password could not be stored.');
        }
        if (!password_verify($password, $stored)) {
            @unlink($path);
            throw new RuntimeException('The password could not be stored.');
        }
    } catch (Throwable $exception) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        if (is_file($path) && !sa_admin_password_is_configured()) {
            @unlink($path);
        }
        throw $exception;
    }
}

function sa_attempt_bootstrap(string $token, string $password, string $confirm): void
{
    if (!sa_admin_can_bootstrap()) {
        throw new RuntimeException('Initial password setup is not available.');
    }
    $expected = sa_bootstrap_token_value();
    if ($expected === '' || !hash_equals($expected, $token)) {
        throw new RuntimeException('Initial password setup is not available.');
    }
    if (!hash_equals($password, $confirm)) {
        throw new RuntimeException('The password confirmation does not match.');
    }
    sa_write_admin_password_hash($password);
    sa_consume_bootstrap_token();
}
