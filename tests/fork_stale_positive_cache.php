<?php declare(strict_types = 1);

/**
 * Contract test: Fork Stale Positive Cache.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

if (!\function_exists('pcntl_fork')) {
    echo 'fork stale positive cache ok (skipped: pcntl unavailable)' . PHP_EOL;
    exit(0);
}

$name = 'fast-store-fork-stalepos-' . \bin2hex(\random_bytes(6));
$parent = new \Fast(['name' => $name, 'persistent' => true]);
$parent['x'] = 1;
// Warm a local positive view of 'x' before the peer deletes it.
$_ = $parent['x'];

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

    if (isset($parent['x'])) {
        \fwrite(STDERR, 'parent must not serve a stale positive for a peer-deleted key' . PHP_EOL);
        exit(1);
    }

    $parent['x'] = 2;

    $reader = new \Fast(['name' => $name]);
    if (!isset($reader['x']) || $reader['x'] !== 2) {
        \fwrite(STDERR, 'reader should see re-registered x=2 got ' . \var_export($reader['x'] ?? null, true) . PHP_EOL);
        exit(1);
    }

    $parent->destroy();
    echo 'fork stale positive cache ok' . PHP_EOL;
    exit(0);
}

$child = new \Fast(['name' => $name]);
unset($child['x']);
exit(0);
