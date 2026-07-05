<?php declare(strict_types = 1);

/**
 * Contract test: Shared Offset Get Bench.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

// Warm (no peer-write) shared read/write throughput via the public ArrayAccess /
// magic-property API. This is the steady-state hot path (cache-current fast path).

$keys = (int) ($argv[1] ?? 5000);
$iters = (int) ($argv[2] ?? 200000);

$name = 'fast-store-offget-bench-' . \bin2hex(\random_bytes(6));
try {
    (new \Fast(['name' => $name, 'capacity' => 1 << 14, 'size' => 33554432]))->destroy();
} catch (\Throwable) {
}

$f = new \Fast(['name' => $name, 'capacity' => 1 << 14, 'size' => 33554432]);
for ($i = 0; $i < $keys; $i++) {
    $f['k' . $i] = 'value-number-' . $i;
}

$bench = static function (string $label, int $iters, callable $op): void {
    $t = \hrtime(true);
    $op($iters);
    $sec = (\hrtime(true) - $t) / 1e9;
    $rate = $sec > 0 ? $iters / $sec : 0.0;
    \printf("%-26s %12s ops/sec  %8.3f us/op\n", $label, \number_format((int) $rate), $sec / $iters * 1e6);
};

echo "shared offsetGet/magic/isset/write bench (keys={$keys}, iters={$iters})\n\n";

$bench('offsetGet read', $iters, static function (int $n) use ($f, $keys): void {
    $sink = null;
    for ($i = 0; $i < $n; $i++) {
        $sink = $f['k' . ($i % $keys)];
    }
});

$bench('magic property read', $iters, static function (int $n) use ($f, $keys): void {
    $sink = null;
    for ($i = 0; $i < $n; $i++) {
        $sink = $f->{'k' . ($i % $keys)};
    }
});

$bench('isset', $iters, static function (int $n) use ($f, $keys): void {
    $sink = false;
    for ($i = 0; $i < $n; $i++) {
        $sink = isset($f['k' . ($i % $keys)]);
    }
});

$bench('write (same-size update)', $iters, static function (int $n) use ($f, $keys): void {
    for ($i = 0; $i < $n; $i++) {
        $f['k' . ($i % $keys)] = 'value-number-' . ($i % $keys);
    }
});

$bench('write (larger update)', $keys, static function (int $n) use ($f): void {
    for ($i = 0; $i < $n; $i++) {
        $f['k' . $i] = \str_repeat('L', 64 + ($i % 64));
    }
});

$f->destroy();
