<?php declare(strict_types = 1);

/**
 * Contract test: Fork Alloc.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

if (!\function_exists('pcntl_fork')) {
    echo 'fork allocation ok (skipped: pcntl unavailable)' . PHP_EOL;
    exit(0);
}

$name = 'fast-store-fork-alloc-' . \bin2hex(\random_bytes(6));
$parent = new \Fast(['name' => $name, 'persistent' => true]);

$workers = 8;
$keys = [];
for ($i = 0; $i < $workers; $i++) {
    $keys[] = 'p' . $i;
}

$pids = [];
for ($w = 0; $w < $workers; $w++) {
    $pid = \pcntl_fork();
    if ($pid === -1) {
        \fwrite(STDERR, 'fork failed' . PHP_EOL);
        exit(1);
    }
    if ($pid === 0) {
        $child = new \Fast(['name' => $name]);
        foreach ($keys as $k) {
            $child[$k] = 1;
        }
        exit(0);
    }
    $pids[] = $pid;
}

foreach ($pids as $pid) {
    \pcntl_waitpid($pid, $status);
}

$check = new \Fast(['name' => $name]);
$missing = [];
foreach ($keys as $k) {
    if (!isset($check[$k]) || $check[$k] !== 1) {
        $missing[] = $k;
    }
}

if ($missing !== []) {
    \fwrite(STDERR, 'missing keys: ' . \implode(', ', $missing) . PHP_EOL);
    exit(1);
}

$parent->destroy();
echo 'fork allocation ok: ' . \count($keys) . ' keys' . PHP_EOL;
