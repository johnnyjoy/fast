<?php declare(strict_types = 1);

/**
 * Phase 7 gate: order log stays within directory capacity (H_ORDER <= slot_count).
 *
 * Exit 0 on success, 1 on failure.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/engine_access.php';

use \Fast;

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$name = 'fast-order-log-bound-' . \getmypid();

try {
    (new \Fast(['name' => $name, 'capacity' => 64, 'size' => 1048576]))->destroy();
} catch (\Throwable) {
}

$store = new \Fast(['name' => $name, 'capacity' => 64, 'size' => 1048576, 'persistent' => true]);

for ($i = 0; $i < 8; $i++) {
    $store['k' . $i] = $i;
}

for ($round = 0; $round < 500; $round++) {
    $i = $round % 8;
    unset($store['k' . $i]);
    $store['k' . $i] = $round;
    if (($round & 31) === 0) {
        try {
            fast_test_assert_order_log_bounded($store, $name);
        } catch (\RuntimeException $e) {
            $fail($e->getMessage());
        }
    }
}

try {
    fast_test_assert_order_log_bounded($store, $name);
} catch (\RuntimeException $e) {
    $fail($e->getMessage());
}

if (\count($store) !== 8) {
    $fail('live count after churn: expected 8, got ' . \count($store));
}

$store->destroy();

echo 'order log bound ok' . PHP_EOL;
