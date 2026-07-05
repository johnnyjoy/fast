<?php declare(strict_types = 1);

/**
 * Regression: local-mode lookup/insert must not scan every configured directory
 * slot. This guards the private hash-map index added to the local Journal path.
 *
 * Two parts:
 *   1. Correctness — every public array behavior the index must preserve
 *      (insertion order, isset(null) false, missing read returns null without
 *      creating, foreach order, count, delete, replacement, re-insert).
 *   2. Scaling — per-operation cost must stay ~flat as the live entry count
 *      grows. The old O(n) per-op scan made aggregate work O(n^2): per-op cost
 *      grew linearly with N. We compare per-op cost at N and 16*N and require
 *      the ratio to stay well below linear. Bounds are deliberately generous so
 *      this is a regression trip-wire, not a brittle timing assertion.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

$fail = static function (string $message): never {
    \fwrite(\STDERR, $message . \PHP_EOL);
    exit(1);
};

/* ---------------------------------------------------------------------------
 * 1. Correctness: a large directory capacity with only a few live keys. If a
 *    lookup scanned all configured slots, results would still have to be
 *    correct, so these assertions pin behavior the index must never change.
 * ------------------------------------------------------------------------- */
$store = new \Fast(['capacity' => 65536]);

$store['alpha'] = 1;
$store['beta']  = 2;
$store['gamma'] = 3;

// replacement updates value, preserves position
$store['beta'] = 22;

// stored null is present-but-null: isset() is false, key still iterates
$store['nul'] = null;

// falsy-but-present keys: isset() must be true (PHP array parity)
$store['zero'] = 0;
$store['empty'] = '';
$store['false'] = false;

if (isset($store['nul'])) {
    $fail('isset() must be false for a stored null');
}
if (!\array_key_exists('nul', \iterator_to_array($store))) {
    $fail('a stored null key must still be present/iterable');
}
foreach (['zero', 'empty', 'false'] as $k) {
    if (!isset($store[$k])) {
        $fail("isset() must be true for stored falsy value at '$k'");
    }
}

// missing read returns null and must NOT create a phantom entry
$countBefore = \count($store);
$miss = $store['does-not-exist'];
if ($miss !== null) {
    $fail('missing read must return null');
}
if (isset($store['does-not-exist'])) {
    $fail('missing read must not create the key');
}
if (\count($store) !== $countBefore) {
    $fail('missing read changed live count');
}

// delete removes from lookup
unset($store['gamma']);
if (isset($store['gamma'])) {
    $fail('deleted key must not be present');
}

// re-insert after delete appends at end of insertion order
$store['gamma'] = 333;

$expectedKeys = ['alpha', 'beta', 'nul', 'zero', 'empty', 'false', 'gamma'];
$expectedVals = [1, 22, null, 0, '', false, 333];

$seenKeys = [];
$seenVals = [];
foreach ($store as $k => $v) {
    $seenKeys[] = $k;
    $seenVals[] = $v;
}

if ($seenKeys !== $expectedKeys) {
    $fail('insertion order mismatch: ' . \json_encode($seenKeys));
}
if ($seenVals !== $expectedVals) {
    $fail('iteration values mismatch: ' . \json_encode($seenVals));
}
if (\count($store) !== \count($expectedKeys)) {
    $fail('live count mismatch: ' . \count($store));
}

// serialization (sleep/wake) must rebuild the index and preserve everything
$revived = \unserialize(\serialize($store));
$revivedKeys = [];
foreach ($revived as $k => $v) {
    $revivedKeys[] = $k;
}
if ($revivedKeys !== $expectedKeys) {
    $fail('serialization lost insertion order: ' . \json_encode($revivedKeys));
}
if ($revived['gamma'] !== 333 || isset($revived['nul']) || $revived['beta'] !== 22) {
    $fail('serialization lost values/null semantics');
}
// index is live after wake: new lookups/writes resolve correctly
if ($revived['alpha'] !== 1) {
    $fail('post-wake lookup failed');
}
$revived['delta'] = 4;
unset($revived['alpha']);
if (isset($revived['alpha']) || $revived['delta'] !== 4) {
    $fail('post-wake mutation failed');
}

/* ---------------------------------------------------------------------------
 * 2. Scaling: per-op insert+lookup cost must stay ~flat as N grows.
 *    Old behavior: O(n) per op => per-op cost at 16N ~ 16x cost at N.
 *    With the index: ~constant. Require ratio < 6 (wide margin both ways).
 * ------------------------------------------------------------------------- */
$measure = static function (int $n): float {
    // capacity at ~0.6 load factor, same as the research harness
    $cap = 1024;
    while ($cap < (int) \ceil($n / 0.6)) {
        $cap <<= 1;
    }
    $store = new \Fast(['capacity' => $cap]);

    $t0 = \hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $store["k:$i"] = $i;
    }
    // interleave lookups + isset so the lookup path is exercised, not just insert
    for ($i = 0; $i < $n; $i++) {
        if ($store["k:$i"] !== $i) {
            \fwrite(\STDERR, 'scaling lookup mismatch' . \PHP_EOL);
            exit(1);
        }
        $present = isset($store["k:$i"]);
        if (!$present) {
            \fwrite(\STDERR, 'scaling isset mismatch' . \PHP_EOL);
            exit(1);
        }
    }
    $elapsed = \hrtime(true) - $t0;

    if (\count($store) !== $n) {
        \fwrite(\STDERR, 'scaling count mismatch: ' . \count($store) . PHP_EOL);
        exit(1);
    }

    // per-op nanoseconds: 2 ops per element (insert + read), plus isset
    return $elapsed / ($n * 3);
};

$n1 = 1000;
$n2 = 16000;

// warm up to stabilize opcode/JIT and allocator state
$measure(256);

$perOpSmall = $measure($n1);
$perOpLarge = $measure($n2);

$ratio = $perOpSmall > 0.0 ? $perOpLarge / $perOpSmall : 0.0;

\printf(
    "local index scaling: per-op %s=%.1fns %s=%.1fns ratio=%.2f (size x%.0f)\n",
    \number_format($n1),
    $perOpSmall,
    \number_format($n2),
    $perOpLarge,
    $ratio,
    $n2 / $n1
);

if ($ratio >= 6.0) {
    $fail(\sprintf(
        'per-op cost scales with N (ratio %.2f for %dx more entries) — local lookup/insert appears to scan slots again',
        $ratio,
        $n2 / $n1
    ));
}

echo 'local index scaling ok' . \PHP_EOL;
