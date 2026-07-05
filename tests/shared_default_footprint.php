<?php declare(strict_types = 1);

/**
 * Contract test: Shared Default Footprint.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/engine_access.php';

use \Fast;
use Fast\Engine\Flat;

// The default footprint must stay modest: the fixed directory/order region is
// sized to the directory slot count, so a default store must not preallocate
// millions of slots (which would cost tens of MB before a single key is stored).
// The directory is fixed at construction — it never auto-grows — so adding small
// keys must not change the slot count, and overflowing it must fail clearly.

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

if (Flat::DEFAULT_SLOTS > 16384) {
    $fail('default directory slots ' . Flat::DEFAULT_SLOTS . ' is too large for an empty store');
}

// --- Empty default store reports a sane, bounded directory ------------------------
$name = 'fast-store-foot-' . \bin2hex(\random_bytes(8));
try {
    (new \Fast(['name' => $name]))->destroy();
} catch (\Throwable) {
    // ignore
}

$store = new \Fast(['name' => $name]);
$stats = fast_test_stats($store);

if ($stats['directory_slots'] !== Flat::DEFAULT_SLOTS) {
    $fail('empty store directory_slots ' . $stats['directory_slots'] . ' != default ' . Flat::DEFAULT_SLOTS);
}
if ($stats['count'] !== 0) {
    $fail('empty store should have zero live keys');
}

// A handful of small keys must not change the fixed directory size.
$store['a'] = 1;
$store['b'] = 'two';
$store['c'] = ['x' => 3];
$small = fast_test_stats($store);
if ($small['directory_slots'] !== Flat::DEFAULT_SLOTS) {
    $fail('small store unexpectedly changed the directory slot count');
}
if ($store['a'] !== 1 || $store['b'] !== 'two' || $store['c']['x'] !== 3) {
    $fail('small store values not readable');
}
$store->destroy();

// --- Exceeding the directory capacity fails clearly ------------------------------
$tiny = 'fast-store-foot-tiny-' . \bin2hex(\random_bytes(8));
try {
    (new \Fast(['name' => $tiny, 'capacity' => 8, 'size' => 1048576]))->destroy();
} catch (\Throwable) {
    // ignore
}

$tinyStore = new \Fast(['name' => $tiny, 'capacity' => 8, 'size' => 1048576]);
$threw = false;
$message = '';
try {
    for ($i = 0; $i < 64; $i++) {
        $tinyStore['key-' . $i] = $i;
    }
} catch (\InvalidArgumentException $e) {
    $threw = true;
    $message = $e->getMessage();
}
$tinyStore->destroy();

if (!$threw) {
    $fail('exceeding the directory capacity should throw a clear error');
}
if (\stripos($message, 'directory is full') === false) {
    $fail('directory-full error message is not clear: ' . $message);
}

echo 'shared default footprint ok (slots=' . Flat::DEFAULT_SLOTS . ')' . PHP_EOL;
