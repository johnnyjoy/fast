<?php declare(strict_types = 1);

/**
 * Contract test: Shared Iterator Reopen.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$name = 'fast-store-shared-iterator-' . \getmypid();
try {
    $cleanup = new \Fast($name);
    $cleanup->destroy();
} catch (\Throwable) {
    // best-effort cleanup only
}

$writer = new \Fast(['name' => $name]);
$writer['z'] = 'last';
$writer['a'] = 'first';
$writer['m'] = 'middle';
$writer[42] = 'int-key';

$reader = new \Fast($name);

$expected = ['z', 'a', 'm', 42];
$got = [];
foreach ($reader as $key => $value) {
    $got[$key] = $value;
}

if (\array_keys($got) !== $expected) {
    $fail('shared iteration order mismatch: ' . \json_encode(\array_keys($got)));
}

if ($got['z'] !== 'last' || $got['a'] !== 'first' || $got['m'] !== 'middle' || $got[42] !== 'int-key') {
    $fail('shared iteration value mismatch');
}

$reader->rewind();
if ($reader->key() !== 'z' || $reader->current() !== 'last') {
    $fail('shared rewind should land on first entry');
}

$reader->next();
if ($reader->key() !== 'a' || $reader->current() !== 'first') {
    $fail('shared next should land on second entry');
}

$reader->destroy();
$writer->close();

echo 'shared iterator reopen ok' . PHP_EOL;
