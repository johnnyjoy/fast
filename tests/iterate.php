<?php declare(strict_types = 1);

/**
 * Contract test: Iterate.
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

$store->z = 'last';
$store->a = 'first';
$store->m = 'middle';
$store[42] = 'int-key';

$expected = ['z', 'a', 'm', 42];
$got = [];

foreach ($store as $key => $_value) {
    $got[] = $key;
}

if ($got !== $expected) {
    $fail('insertion order fail: expected ' . \json_encode($expected) . ' got ' . \json_encode($got));
}

unset($store->m);

$expected = ['z', 'a', 42];
$got = [];

foreach ($store as $key => $_value) {
    $got[] = $key;
}

if ($got !== $expected) {
    $fail('order after unset fail: expected ' . \json_encode($expected) . ' got ' . \json_encode($got));
}

$store->newkey = 'fresh';

$expected = ['z', 'a', 42, 'newkey'];
$got = [];

foreach ($store as $key => $_value) {
    $got[] = $key;
}

if ($got !== $expected) {
    $fail('order after append fail: expected ' . \json_encode($expected) . ' got ' . \json_encode($got));
}

$store->a = 'updated';

$got = [];

foreach ($store as $key => $_value) {
    $got[] = $key;
}

if ($got !== $expected) {
    $fail('overwrite must not reorder: expected ' . \json_encode($expected) . ' got ' . \json_encode($got));
}

if ($store->a !== 'updated') {
    $fail('overwrite value fail');
}

$store->rewind();

if (!$store->valid() || $store->key() !== 'z' || $store->current() !== 'last') {
    $fail('rewind fail');
}

$store->next();

if (!$store->valid() || $store->key() !== 'a' || $store->current() !== 'updated') {
    $fail('next fail');
}

$store->seek(2);

if (!$store->valid() || $store->key() !== 42 || $store->current() !== 'int-key') {
    $fail('seek(2) fail: key=' . \var_export($store->key(), true) . ' current=' . \var_export($store->current(), true));
}

$store->seek(0);

if ($store->key() !== 'z') {
    $fail('seek(0) fail');
}

$last = $store->count() - 1;
$store->seek($last);

if ($store->key() !== 'newkey' || $store->current() !== 'fresh') {
    $fail('seek(last) fail');
}

$seekThrew = false;

try {
    $store->seek(99);
} catch (\OutOfBoundsException) {
    $seekThrew = true;
}

if (!$seekThrew) {
    $fail('seek(99) must throw OutOfBoundsException');
}

if ($store->count() !== 4) {
    $fail('count fail: expected 4 got ' . $store->count());
}

echo 'Fast iterate ok (insertion order + SeekableIterator)' . PHP_EOL;
