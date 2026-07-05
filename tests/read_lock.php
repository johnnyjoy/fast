<?php declare(strict_types = 1);

/**
 * Contract test: Read Lock.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

if (!\function_exists('pcntl_fork')) {
    echo 'read lock ok (skipped: pcntl unavailable)' . PHP_EOL;
    exit(0);
}

$name = 'fast-store-readlock-' . \bin2hex(\random_bytes(6));
$shm = new \Fast(['name' => $name, 'persistent' => true]);
$shm->gate = 0;

$pid = \pcntl_fork();
if ($pid === -1) {
    \fwrite(STDERR, 'fork failed' . PHP_EOL);
    exit(1);
}

if ($pid > 0) {
    $_ = $shm->gate;
    \usleep(400_000);

    if ($shm->gate !== 1) {
        \fwrite(STDERR, 'expected gate=1 after child write, got ' . \var_export($shm->gate, true) . PHP_EOL);
        exit(1);
    }

    \pcntl_waitpid($pid, $status);
    if (!\pcntl_wifexited($status) || \pcntl_wexitstatus($status) !== 0) {
        \fwrite(STDERR, 'child failed' . PHP_EOL);
        exit(1);
    }

    $shm->destroy();
    echo 'read lock ok' . PHP_EOL;
    exit(0);
}

$child = new \Fast(['name' => $name]);
\usleep(100_000);
$child->gate = 1;
exit(0);
