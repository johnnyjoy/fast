<?php declare(strict_types = 1);

/**
 * Contract test: order log compaction under delete+reinsert churn.
 *
 * The order log is fixed at capacity entries; stale tomb/reuse entries must be
 * compacted before append. Without compaction, repeated reinserts corrupt the arena.
 *
 * Exit 0 on success, 1 on failure.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$name = 'fast-order-log-compact-' . \getmypid();

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
}

if (\count($store) !== 8) {
    $fail('live count after churn: expected 8, got ' . \count($store));
}

$seen = 0;
foreach ($store as $key => $value) {
    if (!\is_string($key) || !\str_starts_with($key, 'k')) {
        $fail('unexpected key: ' . \var_export($key, true));
    }
    $seen++;
}
if ($seen !== 8) {
    $fail('foreach yielded ' . $seen . ' entries, expected 8');
}

$store->destroy();

echo 'order log compact ok' . PHP_EOL;
