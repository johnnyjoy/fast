<?php declare(strict_types = 1);

/**
 * Contract test: Shared Allocator Bounds.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/engine_access.php';

use \Fast;

// Single-process allocator bound: repeatedly writing and deleting large values must
// recycle freed space via the size-class free list rather than appending forever.
// We prove it behaviorally — many write/delete cycles of a big value keep the store
// within a small, bounded number of segments. Without reuse the arena would grow
// past one 8 MiB segment after only a handful of cycles.

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$name = 'fast-store-alloc-bounds-' . \bin2hex(\random_bytes(8));
$size = 8388608;     // 8 MiB segment
$bigLen = 300000;    // ~300 KB per cycle
$cycles = 60;        // ~18 MB churned — must stay bounded if reused

try {
    (new \Fast(['name' => $name, 'capacity' => 1024, 'size' => $size]))->destroy();
} catch (\Throwable) {
    // ignore
}

$store = new \Fast(['name' => $name, 'capacity' => 1024, 'size' => $size]);

for ($c = 0; $c < $cycles; $c++) {
    $store['payload'] = \str_repeat('A', $bigLen);
    if ($store['payload'] !== \str_repeat('A', $bigLen)) {
        $fail('value read-back mismatch at cycle ' . $c);
    }
    unset($store['payload']); // return the block to the free list for the next cycle
    if (isset($store['payload'])) {
        $fail('deleted key still present at cycle ' . $c);
    }
}

$segments = fast_test_shared_segment_count($name);
if ($segments > 2) {
    $fail('arena grew to ' . $segments . ' segments under churn — freed blocks were not reused');
}

// The store remains usable and correct after all the churn.
$store['final'] = \str_repeat('B', 200000);
if ($store['final'] !== \str_repeat('B', 200000)) {
    $fail('store not usable after churn');
}
if (\count($store) !== 1) {
    $fail('unexpected live count after churn: ' . \count($store));
}

$store->destroy();

echo 'shared allocator bounds ok (segments=' . $segments . ')' . PHP_EOL;
