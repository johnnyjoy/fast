<?php declare(strict_types = 1);

/**
 * Phase 3 gate: ext-fast compat (LAYOUT_PHP) shared store smoke.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

if (!\extension_loaded('fast')) {
    \fwrite(STDERR, "ext_compat_smoke requires ext-fast\n");
    exit(2);
}

$fail = static function (string $message): never {
    \fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$name = 'ext-compat-smoke-' . \getmypid() . '-' . \time();
$store = new \Fast(['name' => $name, 'compat' => true, 'size' => 8 * 1024 * 1024, 'capacity' => 4096]);

$store['alpha'] = 1;
$store['beta'] = [1, 2, 3];

if ($store['alpha'] !== 1) {
    $fail('get alpha failed');
}

if ($store->count() !== 2) {
    $fail('count expected 2 got ' . $store->count());
}

$store->destroy();

echo "ext compat smoke ok\n";
