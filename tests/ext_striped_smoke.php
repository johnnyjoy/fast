<?php declare(strict_types = 1);

/**
 * Phase 4 gate: native striped store smoke (ext-fast only).
 *
 * Exit 0 on success, 1 on failure.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

if (!\extension_loaded('fast')) {
    \fwrite(STDERR, "ext_striped_smoke requires ext-fast\n");
    exit(2);
}

$fail = static function (string $message): never {
    \fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$name = 'ext-striped-smoke-' . \getmypid() . '-' . \time();

try {
    (new \Fast(['name' => $name, 'capacity' => 512, 'size' => 4 * 1024 * 1024, 'stripes' => 4]))->destroy();
} catch (\Throwable) {
}

$store = new \Fast(['name' => $name, 'capacity' => 512, 'size' => 4 * 1024 * 1024, 'stripes' => 4]);

$expectOrder = [];
for ($i = 0; $i < 200; $i++) {
    $k = ($i % 2 === 0) ? "k:$i" : $i;
    $store[$k] = $i * 3;
    $expectOrder[] = $k;
}

if ($store->count() !== 200) {
    $fail('count expected 200 got ' . $store->count());
}

$keys = [];
foreach ($store as $k => $_v) {
    $keys[] = $k;
}
if ($keys !== $expectOrder) {
    $fail('iteration order fail: ' . \json_encode($keys));
}

unset($store['k:0']);
if ($store->count() !== 199) {
    $fail('count after delete expected 199');
}

$store->destroy();

echo "ext striped smoke ok\n";
