<?php declare(strict_types = 1);

/**
 * Contract test: Fork New Key Visibility.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

if (!\function_exists('pcntl_fork')) {
    echo 'fork new key visibility ok (skipped: pcntl unavailable)' . PHP_EOL;
    exit(0);
}

$name = 'fast-store-fork-newkey-' . \bin2hex(\random_bytes(6));
$parent = new \Fast(['name' => $name, 'persistent' => true]);

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

    if (($parent['a'] ?? null) !== 1) {
        \fwrite(STDERR, 'parent expected a=1 got ' . \var_export($parent['a'] ?? null, true) . PHP_EOL);
        exit(1);
    }

    $parent->destroy();
    echo 'fork new key visibility ok' . PHP_EOL;
    exit(0);
}

$child = new \Fast(['name' => $name]);
$child['a'] = 1;
exit(0);
