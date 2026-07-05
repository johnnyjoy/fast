<?php declare(strict_types = 1);

/**
 * Benchmark latency statistics for Fast.
 *
 * @package Bench
 * @license https://opensource.org/licenses/MIT MIT License
 * @link    https://github.com/johnnyjoy/fast
 */
namespace Bench;

/**
 * Nanosecond latency statistics for benchmark samples.
 *
 * Computes percentiles, mean/stdev, and multi-run aggregates used by
 * {@see Cases} and {@see Runner}.
 *
 * @package Bench
 */
readonly final class Stats
{
    /**
     * @param list<int|float> $samples Nanoseconds per sample
     *
     * @return array<string, float|int>
     */
    public static function fromSamples(array $samples): array
    {
        if ($samples === []) {
            return self::emptyLatency();
        }

        \sort($samples, \SORT_NUMERIC);
        $n = \count($samples);
        $sum = \array_sum($samples);
        $mean = $sum / $n;
        $variance = 0.0;

        foreach ($samples as $v) {
            $variance += ($v - $mean) ** 2;
        }

        $stdev = $n > 1 ? \sqrt($variance / ($n - 1)) : 0.0;

        return [
            'min'     => (float) $samples[0],
            'mean'    => $mean,
            'median'  => self::percentileSorted($samples, 0.50),
            'p90'     => self::percentileSorted($samples, 0.90),
            'p95'     => self::percentileSorted($samples, 0.95),
            'p99'     => self::percentileSorted($samples, 0.99),
            'max'     => (float) $samples[$n - 1],
            'stdev'   => $stdev,
        ];
    }

    /**
     * Derive mean latency from batch totals when per-op samples are unavailable.
     *
     * @param int   $operations   Operation count in the batch
     * @param float $totalSeconds Wall time for the batch
     *
     * @return array<string, float|int>
     */
    public static function fromBatch(int $operations, float $totalSeconds): array
    {
        if ($operations <= 0) {
            return self::emptyLatency();
        }

        $meanNs = ($totalSeconds * 1_000_000_000) / $operations;

        return [
            'min'     => $meanNs,
            'mean'    => $meanNs,
            'median'  => $meanNs,
            'p90'     => $meanNs,
            'p95'     => $meanNs,
            'p99'     => $meanNs,
            'max'     => $meanNs,
            'stdev'   => 0.0,
        ];
    }

    /**
     * @return array<string, float|int>
     */
    public static function emptyLatency(): array
    {
        return [
            'min' => 0.0, 'mean' => 0.0, 'median' => 0.0,
            'p90' => 0.0, 'p95' => 0.0, 'p99' => 0.0,
            'max' => 0.0, 'stdev' => 0.0,
        ];
    }

    /**
     * Percentile of a pre-sorted sample array.
     *
     * @param list<int|float> $sorted Ascending samples
     * @param float           $p      Fraction in [0, 1]
     *
     * @return float Value at the requested percentile
     */
    public static function percentileSorted(array $sorted, float $p): float
    {
        $n = \count($sorted);

        if ($n === 0) {
            return 0.0;
        }

        $idx = (int) \floor(($n - 1) * $p);

        return (float) $sorted[$idx];
    }

    /**
     * Aggregate several benchmark runs into median ops/sec and p95 latency.
     *
     * @param list<array{ops_per_sec: float|int}> $runs Per-run summary rows
     *
     * @return array{median_ops_per_sec: float, ops_per_sec_range: list<float>, median_p95_ns: float, p95_ns_range: list<float>}
     */
    public static function aggregateRuns(array $runs): array
    {
        $ops = [];
        $p95 = [];

        foreach ($runs as $run) {
            if (isset($run['ops_per_sec'])) {
                $ops[] = (float) $run['ops_per_sec'];
            }

            if (isset($run['latency_ns']['p95'])) {
                $p95[] = (float) $run['latency_ns']['p95'];
            }
        }

        \sort($ops);
        \sort($p95);

        return [
            'median_ops_per_sec' => self::medianOf($ops),
            'ops_per_sec_range'  => $ops === [] ? [0.0, 0.0] : [(float) $ops[0], (float) $ops[\count($ops) - 1]],
            'median_p95_ns'      => self::medianOf($p95),
            'p95_ns_range'       => $p95 === [] ? [0.0, 0.0] : [(float) $p95[0], (float) $p95[\count($p95) - 1]],
        ];
    }

    /**
     * Median of numeric values.
     *
     * @param list<float|int> $values Unsorted samples
     *
     * @return float Median value, or 0.0 when empty
     */
    public static function medianOf(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        \sort($values, \SORT_NUMERIC);
        $n = \count($values);
        $mid = (int) \floor(($n - 1) / 2);

        if ($n % 2 === 1) {
            return (float) $values[$mid];
        }

        return ((float) $values[$mid] + (float) $values[$mid + 1]) / 2.0;
    }
}
