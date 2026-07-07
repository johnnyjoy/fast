<?php declare(strict_types = 1);

/**
 * Phase 1 gate: native shared store smoke (ext-fast only).
 *
 * Exit 0 on success, 1 on failure.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

if (!\extension_loaded('fast')) {
    \fwrite(STDERR, "ext_native_smoke requires ext-fast\n");
    exit(2);
}

$fail = static function (string $message): never {
    \fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$name = 'ext-native-smoke-' . \getmypid() . '-' . \time();
$store = new \Fast(['name' => $name, 'size' => 8 * 1024 * 1024, 'capacity' => 4096]);

$store['alpha'] = 1;
$store['beta'] = [1, 2, 3];

if ($store['alpha'] !== 1) {
    $fail('get alpha failed');
}

if (!isset($store['beta'])) {
    $fail('isset beta failed');
}

if ($store->count() !== 2) {
    $fail('count expected 2 got ' . $store->count());
}

$keys = [];
foreach ($store as $k => $_v) {
    $keys[] = $k;
}
if ($keys !== ['alpha', 'beta']) {
    $fail('order fail: ' . \json_encode($keys));
}

unset($store['alpha']);

if ($store->count() !== 1) {
    $fail('count after unset expected 1');
}

$store->destroy();

echo "ext native smoke ok\n";
