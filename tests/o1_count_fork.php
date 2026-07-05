<?php declare(strict_types = 1);

/**
 * Contract test: O1 Count Fork.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

if (!\function_exists('pcntl_fork')) {
    echo 'o1 count fork ok (skipped: pcntl unavailable)' . PHP_EOL;
    exit(0);
}

$name = 'fast-store-o1count-' . \bin2hex(\random_bytes(6));
$parent = new \Fast(['name' => $name, 'persistent' => true]);

for ($i = 0; $i < 32; $i++) {
    $parent['base_' . $i] = $i;
}

if (\count($parent) !== 32) {
    \fwrite(STDERR, 'baseline count expected 32 got ' . \count($parent) . PHP_EOL);
    exit(1);
}

$pid = \pcntl_fork();
if ($pid === -1) {
    \fwrite(STDERR, 'fork failed' . PHP_EOL);
    exit(1);
}

if ($pid === 0) {
    $child = new \Fast(['name' => $name]);

    for ($n = 0; $n < 50; $n++) {
        $child['churn_' . $n] = $n;
    }

    if (\count($child) !== 32 + 50) {
        \fwrite(STDERR, 'child count expected 82 got ' . \count($child) . PHP_EOL);
        exit(1);
    }

    for ($n = 0; $n < 25; $n++) {
        unset($child['churn_' . $n]);
    }

    if (\count($child) !== 32 + 25) {
        \fwrite(STDERR, 'child after unset count expected 57 got ' . \count($child) . PHP_EOL);
        exit(1);
    }

    exit(0);
}

\pcntl_waitpid($pid, $status);

if (!\pcntl_wifexited($status) || \pcntl_wexitstatus($status) !== 0) {
    \fwrite(STDERR, 'child failed' . PHP_EOL);
    exit(1);
}

$parentCount = \count($parent);
if ($parentCount !== 57) {
    \fwrite(STDERR, "parent count expected 57 got {$parentCount}" . PHP_EOL);
    exit(1);
}

$parent->destroy();
echo 'o1 count fork ok' . PHP_EOL;
exit(0);
