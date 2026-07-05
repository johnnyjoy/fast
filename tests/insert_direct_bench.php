<?php declare(strict_types = 1);

/**
 * Contract test: Insert Direct Bench.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$name = 'fast-store-insert-bench-' . getmypid();
try {
    $cleanup = new \Fast($name);
    $cleanup->destroy();
} catch (\Throwable) {
}

$store = new \Fast($name);
$keys = (int) ($argv[1] ?? 100000);
$reinsert = (int) ($argv[2] ?? 50000);
$traceEvery = (int) (getenv('FAST_BENCH_TRACE_EVERY') ?: 0);

$traceStart = \hrtime(true);
$trace = static function (string $message) use ($traceEvery, &$traceStart): void {
    if ($traceEvery > 0) {
        $elapsed = (\hrtime(true) - $traceStart) / 1_000_000_000;
        fwrite(STDERR, $message . ' @ ' . \number_format($elapsed, 3) . "s" . PHP_EOL);
    }
};

$start = \hrtime(true);
for ($i = 0; $i < $keys; $i++) {
    $store['k' . $i] = $i;
    if ($traceEvery > 0 && (($i + 1) % $traceEvery) === 0) {
        $trace('insert progress: ' . ($i + 1));
    }
}
$insertElapsed = \hrtime(true) - $start;

$start = \hrtime(true);
for ($i = 0; $i < $reinsert; $i++) {
    $store['k' . $i] = $i + 1;
    if ($traceEvery > 0 && (($i + 1) % $traceEvery) === 0) {
        $trace('reinsert progress: ' . ($i + 1));
    }
}
$reinsertElapsed = \hrtime(true) - $start;

$count = $store->count();
if ($count !== $keys) {
    $fail('benchmark count mismatch');
}

echo 'insert bench' . PHP_EOL;
echo 'insert tx/sec:   ' . \number_format($keys / ($insertElapsed / 1_000_000_000), 0) . PHP_EOL;
echo 'reinsert tx/sec: ' . \number_format($reinsert / ($reinsertElapsed / 1_000_000_000), 0) . PHP_EOL;
echo 'count: ' . $count . PHP_EOL;
$store->destroy();
