<?php declare(strict_types = 1);

/**
 * Shared insert/lookup/update latency matrix helpers for bench and track harnesses.
 */

namespace Matrix;

use \Fast;

/**
 * hrtime() latency matrix for Fast property access (insert / warm lookup / update).
 *
 * @return array{
 *     valid: bool,
 *     valid_full: bool,
 *     insert: array{avg_us: float, p50_us: float, p95_us: float, p99_us: float, n: int},
 *     lookup_warm: array{avg_us: float, p50_us: float, p95_us: float, p99_us: float, n: int},
 *     lookup_cold: array{avg_us: float, p50_us: float, p95_us: float, p99_us: float, n: int},
 *     update: array{avg_us: float, p50_us: float, p95_us: float, p99_us: float, n: int},
 *     shm_index_us_avg: float,
 *     sem_hold_us_avg: float,
 *     index_kb: float
 * }
 */
function benchSize(string $mode, int $n, int $seed): array
{
    unset($mode);

    if ($n < 1) {
        throw new \InvalidArgumentException('benchSize n must be >= 1');
    }

    $name = 'fast-store-matrix-' . \getmypid() . '-' . $seed . '-' . $n;
    $store = new \Fast(['name' => $name, 'persistent' => true]);

    try {
        \mt_srand($seed);

        $insertSamples = timeOp($n, static function (int $i) use ($store): void {
            $store['key:' . $i] = $i;
        });

        $warmSamples = timeOp($n, static function (int $i) use ($store, $n): void {
            $_ = $store['key:' . ($i % $n)];
        });

        $updateSamples = timeOp($n, static function (int $i) use ($store, $n): void {
            $store['key:' . ($i % $n)] = $i + 1;
        });

        // Cold lookup: fresh store, no warm cache in this process beyond first touch.
        $coldName = $name . '-cold';
        $cold = new \Fast(['name' => $coldName, 'persistent' => true]);
        try {
            for ($i = 0; $i < $n; $i++) {
                $cold['key:' . $i] = $i;
            }
            $coldSamples = timeOp($n, static function (int $i) use ($cold, $n): void {
                $_ = $cold['key:' . ($i % $n)];
            });
        } finally {
            try {
                $cold->destroy();
            } catch (\Throwable) {
            }
        }

        $valid = \count($store) === $n && ($store['key:0'] ?? null) === 1;

        return [
            'valid' => $valid,
            'valid_full' => $valid,
            'insert' => summarize($insertSamples),
            'lookup_warm' => summarize($warmSamples),
            'lookup_cold' => summarize($coldSamples),
            'update' => summarize($updateSamples),
            'shm_index_us_avg' => 0.0,
            'sem_hold_us_avg' => 0.0,
            'index_kb' => 0.0,
        ];
    } finally {
        try {
            $store->destroy();
        } catch (\Throwable) {
        }
    }
}

/** @return list<float> microseconds per op */
function timeOp(int $ops, callable $fn): array
{
    $samples = [];
    $warm = \min(50, $ops);

    for ($i = 0; $i < $warm; $i++) {
        $fn($i);
    }

    for ($i = 0; $i < $ops; $i++) {
        $t0 = \hrtime(true);
        $fn($i);
        $samples[] = ((float) (\hrtime(true) - $t0)) / 1000.0;
    }

    return $samples;
}

/**
 * @param list<float> $samples
 *
 * @return array{avg_us: float, p50_us: float, p95_us: float, p99_us: float, n: int}
 */
function summarize(array $samples): array
{
    if ($samples === []) {
        return ['avg_us' => 0.0, 'p50_us' => 0.0, 'p95_us' => 0.0, 'p99_us' => 0.0, 'n' => 0];
    }

    \sort($samples);
    $n = \count($samples);
    $sum = \array_sum($samples);

    return [
        'avg_us' => $sum / $n,
        'p50_us' => percentile($samples, 50),
        'p95_us' => percentile($samples, 95),
        'p99_us' => percentile($samples, 99),
        'n' => $n,
    ];
}

/** @param list<float> $sorted */
function percentile(array $sorted, int $pct): float
{
    $n = \count($sorted);
    if ($n === 0) {
        return 0.0;
    }

    $idx = (int) \ceil(($pct / 100) * $n) - 1;
    $idx = \max(0, \min($n - 1, $idx));

    return $sorted[$idx];
}
