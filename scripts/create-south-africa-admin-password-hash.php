<?php
declare(strict_types=1);

/**
 * One-shot helper for South Africa Admin runtime initialization.
 * Reads the plaintext password from STDIN and writes admin-password.hash.
 * Never prints the password or the hash.
 */

$path = getenv('SA_ADMIN_HASH_PATH');
if (!is_string($path) || $path === '') {
    $path = '/home/gltr/sa-admin-data/admin-password.hash';
}

function fail_with_cleanup(string $message, ?string $pathToRemove = null): void
{
    if (is_string($pathToRemove) && $pathToRemove !== '') {
        @unlink($pathToRemove);
    }
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$password = stream_get_contents(STDIN);
if (!is_string($password) || $password === '') {
    fail_with_cleanup('SA admin password input is missing.');
}
if (strlen($password) < 12) {
    fail_with_cleanup('SA admin password is shorter than 12 characters.');
}
if (file_exists($path) || is_link($path)) {
    fail_with_cleanup('Refusing to overwrite the existing admin password hash.');
}

$hash = password_hash($password, PASSWORD_DEFAULT);
if (!is_string($hash) || $hash === '') {
    fail_with_cleanup('Failed to create the admin password hash.');
}

$createdInfo = password_get_info($hash);
if (($createdInfo['algoName'] ?? 'unknown') === 'unknown') {
    fail_with_cleanup('The created password hash was not recognized.');
}

umask(0077);
$handle = fopen($path, 'x');
if ($handle === false) {
    fail_with_cleanup('Refusing to overwrite the existing admin password hash.');
}

$written = fwrite($handle, $hash);
$closed = fclose($handle);
if ($written !== strlen($hash) || $closed === false) {
    fail_with_cleanup('Failed to write the admin password hash.', $path);
}

if (!chmod($path, 0600)) {
    fail_with_cleanup('Failed to set the admin password hash mode.', $path);
}

clearstatcache(true, $path);
$storedHash = file_get_contents($path);
$storedInfo = is_string($storedHash) ? password_get_info($storedHash) : [];
$mode = fileperms($path);

if (!is_string($storedHash) || $storedHash !== $hash) {
    fail_with_cleanup('The stored admin password hash does not match the created hash.', $path);
}
if (($storedInfo['algoName'] ?? 'unknown') === 'unknown') {
    fail_with_cleanup('The stored admin password hash was not recognized.', $path);
}
if (!is_int($mode) || (($mode & 0777) !== 0600)) {
    fail_with_cleanup('The stored admin password hash mode is not 600.', $path);
}
if (!password_verify($password, $storedHash)) {
    fail_with_cleanup('The stored admin password hash failed verification.', $path);
}

$password = '';
$hash = '';
$storedHash = '';
unset($password, $hash, $storedHash);
fwrite(STDOUT, "SA admin password hash created and verified.\n");
