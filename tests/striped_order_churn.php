<?php declare(strict_types = 1);

/**
 * Phase 7 gate: tagged striped store survives delete+reinsert churn with bounded order logs.
 *
 * Exit 0 on success, 1 on failure. Requires ext-fast (native striped).
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/engine_access.php';

use \Fast;

if (!\extension_loaded('fast')) {
    fwrite(STDERR, "striped_order_churn requires ext-fast\n");
    exit(2);
}

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$name = 'fast-striped-order-' . \getmypid();
$stripes = 4;
$cfg = ['name' => $name, 'capacity' => 128, 'size' => 4 * 1048576, 'stripes' => $stripes];

try {
    (new \Fast($cfg))->destroy();
} catch (\Throwable) {
}

$store = new \Fast($cfg + ['persistent' => true]);

for ($i = 0; $i < 32; $i++) {
    $store['key' . $i] = ['n' => $i, 'tag' => 'v' . $i];
}

for ($round = 0; $round < 300; $round++) {
    $i = $round % 32;
    unset($store['key' . $i]);
    $store['key' . $i] = ['n' => $round, 'tag' => 'v' . $round];
}

if ($store->count() !== 32) {
    $fail('count expected 32, got ' . $store->count());
}

$seen = 0;
foreach ($store as $key => $value) {
    if (!\is_array($value) || !isset($value['n'])) {
        $fail('foreach value corrupt at ' . \var_export($key, true));
    }
    $seen++;
}
if ($seen !== 32) {
    $fail('foreach yielded ' . $seen . ' entries, expected 32');
}

for ($s = 0; $s < $stripes; $s++) {
    $sub = $name . '#' . $s;
    try {
        fast_test_assert_order_log_bounded($store, $sub);
    } catch (\RuntimeException $e) {
        $fail('stripe ' . $s . ': ' . $e->getMessage());
    }
}

$store->destroy();

echo 'striped order churn ok' . PHP_EOL;
