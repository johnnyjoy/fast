<?php declare(strict_types = 1);

/**
 * Contract test: Shared Compact Accepted.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/engine_access.php';

use \Fast;

/**
 * compact() is internal maintenance, not public Fast contract (it is reached here
 * only via the test-only fast_test_compact() helper). It must never fail merely
 * because the store is shared, and it must preserve every live entry. Bounded
 * incremental shrink of the shared arena is future engine work, so today shared
 * compaction is a no-op — this test pins that minimum acceptable behavior.
 */

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$name = 'fast-store-shared-compact-' . \getmypid();
try {
    $cleanup = new \Fast($name);
    $cleanup->destroy();
} catch (\Throwable) {
    // best-effort cleanup only
}

$store = new \Fast(['name' => $name]);
$store['a'] = 1;
$store['b'] = 'two';
$store[7] = ['nested' => true];
unset($store['a']); // leave a hole for compaction to consider

$before = \count($store);

// Must not throw simply because the store is shared.
try {
    fast_test_compact($store);
} catch (\Throwable $e) {
    $fail('shared compact() must not throw: ' . $e->getMessage());
}

// All live entries must remain intact and readable after compaction.
if (\count($store) !== $before) {
    $fail('compact() changed the live entry count');
}
if (isset($store['a'])) {
    $fail('deleted entry reappeared after compact()');
}
if ($store['b'] !== 'two') {
    $fail('string value not preserved by compact()');
}
if (($store[7]['nested'] ?? null) !== true) {
    $fail('nested value not preserved by compact()');
}

// compact() is idempotent and still safe on a second call.
fast_test_compact($store);
if ($store['b'] !== 'two') {
    $fail('value lost after a second compact()');
}

$store->destroy();

echo 'shared compact accepted ok' . PHP_EOL;
