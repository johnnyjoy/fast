<?php declare(strict_types = 1);

/**
 * Contract test: Example Blocks.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

$fail = static function (string $message): never {
    \fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

if (!\function_exists('pcntl_fork')) {
    echo 'example blocks ok (skipped: pcntl unavailable)' . PHP_EOL;
    exit(0);
}

$name = 'fast-store-ex1-' . \bin2hex(\random_bytes(6));
$shm = new \Fast(['name' => $name, 'persistent' => true]);

$shm['user:1'] = ['name' => 'Ada'];
$shm->flags = 1;

if ($shm['user:1']['name'] !== 'Ada') {
    $fail('example 1: user:1 name mismatch');
}

$defaulted = $shm['missing'] ?? 'default';
if ($defaulted !== 'default') {
    $fail('example 1: ?? on missing key failed');
}

if (\count($shm) !== 2) {
    $fail('example 1: count expected 2 got ' . \count($shm));
}

$name2 = 'fast-store-fork-demo-' . \bin2hex(\random_bytes(6));
$shm2 = new \Fast(['name' => $name2, 'persistent' => true]);
$shm2['a'] = 0;

$pid = \pcntl_fork();
if ($pid === -1) {
    $fail('fork failed');
}

if ($pid > 0) {
    // t=1: child has written a=2 (at t=0). Observe it, then overwrite with 0.
    \sleep(1);
    $peer = new \Fast(['name' => $name2]);
    if ($peer['a'] !== 2) {
        $fail('example 2 parent: expected a=2 got ' . \var_export($peer['a'], true));
    }
    $peer['a'] = 0;
    // t=3: child has incremented the 0 we wrote (at t=2) up to 1.
    \sleep(2);
    if ($peer['a'] !== 1) {
        $fail('example 2 parent: expected a=1 got ' . \var_export($peer['a'], true));
    }
    \pcntl_waitpid($pid, $status);
    $peer->destroy();
    $shm->destroy();
    echo 'example blocks ok' . PHP_EOL;
    exit(0);
}

// child: write at t=0, then at t=2 (after the parent's t=1 overwrite to 0)
// increment it so the parent observes 1 at t=3.
$c = new \Fast(['name' => $name2]);
$c['a'] = 2;
\sleep(2);
$c['a']++;
if ($c['a'] !== 1) {
    \fwrite(STDERR, 'example 2 child: expected a=1 got ' . \var_export($c['a'], true) . PHP_EOL);
    exit(1);
}
exit(0);
