<?php declare(strict_types = 1);

/**
 * Contract test: Order Chain.
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

$store = new \Fast();

$store['a'] = 1;
$store['b'] = 2;
$store['c'] = 3;
$store['b'] = 20;
unset($store['b']);
$store['b'] = 200;

$seenKeys = [];
$seenValues = [];
foreach ($store as $key => $value) {
    $seenKeys[] = $key;
    $seenValues[] = $value;
}

if ($seenKeys !== ['a', 'c', 'b']) {
    $fail('iteration order mismatch: ' . \json_encode($seenKeys));
}

if ($seenValues !== [1, 3, 200]) {
    $fail('iteration values mismatch: ' . \json_encode($seenValues));
}

$store->rewind();
if ($store->key() !== 'a' || $store->current() !== 1) {
    $fail('rewind should start at first live entry');
}

$store->seek(1);
if ($store->key() !== 'c' || $store->current() !== 3) {
    $fail('seek(1) should land on c');
}

$store->seek(2);
if ($store->key() !== 'b' || $store->current() !== 200) {
    $fail('seek(2) should land on reinserted b');
}

echo 'order chain ok' . PHP_EOL;
