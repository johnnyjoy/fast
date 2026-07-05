<?php declare(strict_types = 1);

/**
 * Contract test: Fork Unset Stale Cache.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

if (!\function_exists('pcntl_fork')) {
    echo 'fork unset stale cache ok (skipped: pcntl unavailable)' . PHP_EOL;
    exit(0);
}

$name = 'fast-store-fork-unset-' . \bin2hex(\random_bytes(6));
$parent = new \Fast(['name' => $name, 'persistent' => true]);

$key = 'cross_unset_key';

if (isset($parent[$key])) {
    \fwrite(STDERR, 'parent should not see key before child creates it' . PHP_EOL);
    exit(1);
}

$pid = \pcntl_fork();
if ($pid === -1) {
    \fwrite(STDERR, 'fork failed' . PHP_EOL);
    exit(1);
}

if ($pid > 0) {
    \pcntl_waitpid($pid, $status);

    if (!\pcntl_wifexited($status) || \pcntl_wexitstatus($status) !== 0) {
        \fwrite(STDERR, 'child failed' . PHP_EOL);
        exit(1);
    }

    // Parent sees the child's insert, then deletes it.
    unset($parent[$key]);

    if (isset($parent[$key])) {
        \fwrite(STDERR, 'parent unset failed; key still present' . PHP_EOL);
        exit(1);
    }

    $reader = new \Fast(['name' => $name]);
    if (isset($reader[$key])) {
        \fwrite(STDERR, 'a fresh reader still sees the unset key' . PHP_EOL);
        exit(1);
    }

    $parent->destroy();
    echo 'fork unset stale cache ok' . PHP_EOL;
    exit(0);
}

$child = new \Fast(['name' => $name]);
$child[$key] = 42;
exit(0);
