<?php
declare(strict_types=1);

$repository = dirname(__DIR__);

if (($argv[1] ?? '') === '--worker') {
    if (count($argv) !== 8) {
        fwrite(STDERR, "Invalid worker arguments.\n");
        exit(2);
    }
    [, , $runtimeDirectory, $version, $ref, $mileage, $barrier, $result] = $argv;
    putenv('GLORIA_EA_DATA_DIR=' . $runtimeDirectory);
    require_once $repository . '/east-africa/lib/vehicle-store.php';
    $deadline = microtime(true) + 10;
    while (!is_file($barrier)) {
        if (microtime(true) >= $deadline) {
            file_put_contents($result, 'timeout');
            exit(3);
        }
        usleep(10000);
    }
    try {
        $loaded = ea_load_vehicle_data(false, true);
        $found = ea_find_vehicle($loaded['data'], $ref);
        if ($found === null) {
            throw new RuntimeException('Worker vehicle missing.');
        }
        $record = $found['vehicle'];
        $record['mileage_km'] = (int)$mileage;
        ea_validate_vehicle_record($record, true);
        ea_save_vehicle_record($record, $ref, $version);
        file_put_contents($result, 'saved');
        exit(0);
    } catch (EaConflictException $exception) {
        file_put_contents($result, 'conflict');
        exit(0);
    } catch (Throwable $exception) {
        file_put_contents($result, 'error:' . $exception->getMessage());
        exit(4);
    }
}

function concurrency_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

function concurrency_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

$temp = sys_get_temp_dir() . '/gloria-ea-concurrency-' . bin2hex(random_bytes(6));
putenv('GLORIA_EA_DATA_DIR=' . $temp);
require_once $repository . '/east-africa/lib/vehicle-store.php';

try {
    ea_ensure_runtime_directories();
    $seed = file_get_contents(ea_seed_json_path());
    if ($seed === false) {
        throw new RuntimeException('Seed data could not be read.');
    }
    file_put_contents(ea_runtime_json_path(), $seed, LOCK_EX);
    chmod(ea_runtime_json_path(), 0600);
    $loaded = ea_load_vehicle_data(false, true);
    $barrier = $temp . '/start';
    $results = [$temp . '/worker-a.result', $temp . '/worker-b.result'];
    $commands = [
        [PHP_BINARY, __FILE__, '--worker', $temp, $loaded['version'], 'EA-PBX-002', '22001', $barrier, $results[0]],
        [PHP_BINARY, __FILE__, '--worker', $temp, $loaded['version'], 'EA-PBX-003', '33001', $barrier, $results[1]],
    ];
    $processes = [];
    foreach ($commands as $command) {
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Worker could not be started.');
        }
        $processes[] = ['process' => $process, 'stderr' => $pipes[2]];
    }
    file_put_contents($barrier, "start\n", LOCK_EX);
    $errors = [];
    foreach ($processes as $entry) {
        $errors[] = stream_get_contents($entry['stderr']);
        fclose($entry['stderr']);
        concurrency_assert(proc_close($entry['process']) === 0, 'worker process exits successfully');
    }
    $outcomes = array_map(static fn(string $path): string => trim((string)file_get_contents($path)), $results);
    sort($outcomes);
    concurrency_assert($outcomes === ['conflict', 'saved'], 'exactly one simultaneous writer succeeds');
    $final = ea_load_vehicle_data(false, true);
    concurrency_assert(count($final['data']['vehicles']) === 3, 'concurrent save preserves all records');
    $two = ea_find_vehicle($final['data'], 'EA-PBX-002')['vehicle']['mileage_km'] ?? null;
    $three = ea_find_vehicle($final['data'], 'EA-PBX-003')['vehicle']['mileage_km'] ?? null;
    concurrency_assert(($two === 22001) xor ($three === 33001), 'only the winning change is committed');
    concurrency_assert(count(glob(ea_backup_dir() . '/vehicles-*.json') ?: []) === 1, 'only the winning save creates a backup');
    echo "EA admin concurrency check passed: one writer saved and one stale writer was rejected.\n";
} finally {
    concurrency_remove_tree($temp);
}
