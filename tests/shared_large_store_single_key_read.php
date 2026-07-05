<?php declare(strict_types = 1);

/**
 * Contract test: Shared Large Store Single Key Read.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

// A single-key read after a peer mutation must NOT scale with total key count.
// Flat reads every key with a narrow, seqlock-validated directory probe (never a
// whole-store refresh), so the per-read time in a 10k-key store must stay close to
// a tiny store. We assert (1) correctness — a stale reader always observes the
// peer's latest value — and (2) a loose O(1) sanity bound on per-read wall time.

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

/**
 * Build a store of $count keys, then measure the average time to read ONE key
 * after a peer mutation, repeated $reads times, each preceded by a peer write so
 * every read observes a freshly published value. Returns seconds_per_read.
 */
$measure = static function (int $count, int $reads) use ($fail): float {
    $cap = 1;
    while ($cap <= $count) {
        $cap <<= 1;
    }
    $cap <<= 1; // headroom so the directory never fills

    $name = 'fast-store-bigread-' . $count . '-' . \bin2hex(\random_bytes(6));
    try {
        (new \Fast(['name' => $name, 'capacity' => $cap, 'size' => 67108864]))->destroy();
    } catch (\Throwable) {
    }

    $writer = new \Fast(['name' => $name, 'capacity' => $cap, 'size' => 67108864]);
    for ($i = 0; $i < $count; $i++) {
        $writer['k' . $i] = 'v' . $i;
    }

    $reader = new \Fast($name);
    $reader['k0']; // attach/warm
    $target = 'k' . \intdiv($count, 2);

    $start = \hrtime(true);
    for ($r = 0; $r < $reads; $r++) {
        $writer[$target] = 'u' . $r; // peer mutation -> reader must observe it
        $got = $reader[$target];
        if ($got !== 'u' . $r) {
            $fail('large-store cross-process read mismatch for ' . $count . ' keys');
        }
    }
    $elapsed = (\hrtime(true) - $start) / 1e9;

    $writer->destroy();

    return $elapsed / $reads;
};

$smallPer = $measure(100, 500);
$bigPer   = $measure(10000, 500);

// Loose upper bound: a 100x larger store must not make a single key read anywhere
// near 100x slower. A narrow probe is ~O(1); allow generous slack for noise. If
// reads were O(n) this ratio would explode.
$ratio = $bigPer / max($smallPer, 1e-9);
if ($ratio > 20.0) {
    $fail(\sprintf(
        'single-key read scales with store size: 100 keys=%.2fus, 10000 keys=%.2fus (%.1fx)',
        $smallPer * 1e6,
        $bigPer * 1e6,
        $ratio
    ));
}

echo \sprintf(
    'shared large-store single-key read ok (100=%.2fus, 10000=%.2fus, %.1fx)' . PHP_EOL,
    $smallPer * 1e6,
    $bigPer * 1e6,
    $ratio
);
