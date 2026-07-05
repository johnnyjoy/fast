<?php declare(strict_types = 1);

/**
 * Contract test: Read Stale Coherence.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

if (!\function_exists('pcntl_fork')) {
    echo 'read stale coherence ok (skipped: pcntl unavailable)' . PHP_EOL;
    exit(0);
}

$name = 'fast-store-readstale-' . \bin2hex(\random_bytes(6));
$shm = new \Fast(['name' => $name, 'persistent' => true]);
$shm->counter = 0;
$shm->gate = 0;

$pid = \pcntl_fork();
if ($pid === -1) {
    \fwrite(STDERR, 'fork failed' . PHP_EOL);
    exit(1);
}

if ($pid > 0) {
    // Capture a value copy; it must not mutate when the peer later writes.
    $v1 = $shm->counter;
    $shm->gate = 1;

    $deadline = \microtime(true) + 2.0;
    while ($shm->gate !== 2) {
        if (\microtime(true) >= $deadline) {
            \fwrite(STDERR, 'timeout waiting for child write' . PHP_EOL);
            exit(1);
        }
        \usleep(10_000);
    }

    if ($v1 !== 0) {
        \fwrite(STDERR, 'expected captured copy v1=0, got ' . \var_export($v1, true) . PHP_EOL);
        exit(1);
    }

    $v2 = $shm->counter;
    if ($v2 !== 99) {
        \fwrite(STDERR, 'expected re-read counter=99, got ' . \var_export($v2, true) . PHP_EOL);
        exit(1);
    }

    \pcntl_waitpid($pid, $status);
    if (!\pcntl_wifexited($status) || \pcntl_wexitstatus($status) !== 0) {
        \fwrite(STDERR, 'child failed' . PHP_EOL);
        exit(1);
    }

    $shm->destroy();
    echo 'read stale coherence ok' . PHP_EOL;
    exit(0);
}

$child = new \Fast(['name' => $name]);
$deadline = \microtime(true) + 2.0;
while ($child->gate !== 1) {
    if (\microtime(true) >= $deadline) {
        \fwrite(STDERR, 'child timeout waiting for parent read' . PHP_EOL);
        exit(1);
    }
    \usleep(10_000);
}

$child->counter = 99;
$child->gate = 2;
exit(0);
