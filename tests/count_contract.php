<?php declare(strict_types = 1);

/**
 * Contract test: Count Contract.
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

$name = 'fast-store-count-' . \bin2hex(\random_bytes(6));
$shm = new \Fast(['name' => $name, 'persistent' => true]);

if (\count($shm) !== 0) {
    $fail('empty store count expected 0 got ' . \count($shm));
}

$shm['a'] = 1;
if (\count($shm) !== 1) {
    $fail('after set count expected 1 got ' . \count($shm));
}

// A second handle to the same named store shares the live count.
$attached = new \Fast(['name' => $name]);
if (\count($attached) !== 1) {
    $fail('reattach to existing store expected count 1 got ' . \count($attached));
}
if ($attached['a'] !== 1) {
    $fail('reattach to existing store lost the stored value');
}

$attached['b'] = 2;
if (\count($shm) !== 2 || \count($attached) !== 2) {
    $fail('reattach should share the live count across handles');
}

$attached->close();
if (\count($shm) !== 2) {
    $fail('closing one handle must not change the live count');
}

$shm['a'] = 2;
if (\count($shm) !== 2) {
    $fail('overwrite must not change count, got ' . \count($shm));
}

$shm[100] = 'x';
if (\count($shm) !== 3) {
    $fail('integer key insert expected count 3 got ' . \count($shm));
}

unset($shm['a']);
if (\count($shm) !== 2) {
    $fail('after unset count expected 2 got ' . \count($shm));
}

// A stored null is still a live, counted entry.
$shm['n'] = null;
if (\count($shm) !== 3) {
    $fail('a stored null must be counted, got ' . \count($shm));
}

$shm->destroy();

echo 'count contract ok' . PHP_EOL;
