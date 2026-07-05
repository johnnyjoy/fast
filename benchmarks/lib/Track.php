<?php declare(strict_types = 1);

/**
 * Performance tracking and regression gates for Fast benchmarks.
 *
 * @package Bench
 * @license https://opensource.org/licenses/MIT MIT License
 * @link    https://github.com/johnnyjoy/fast
 */
namespace Bench;

/**
 * Committed best-known perf floor for fast matrix tracking.
 *
 * {@see baselinePath()} stores per-metric bests (lower is better), updated via
 * {@see mergeBestIntoBaseline()} / `track.php --record-best` — never regressed by a noisy run.
 * Scorecard and perf gate compare today vs this floor (+ tolerance).
 *
 * @package Bench
 */
readonly final class Track
{
    public const string BASELINE_KIND_BEST_FLOOR = 'best_floor';
    /** @var list<string> Latency p95 (µs) from hrtime() on real property access. */
    public const LATENCY_METRICS = [
        'lookup_warm_p95_us',
        'update_p95_us',
        'insert_p95_us',
    ];

    /** @var list<string> */
    public const METRICS = self::LATENCY_METRICS;

    /** Default allowed slowdown vs baseline for latency metrics (µs p95). */
    public const float DEFAULT_LATENCY_TOLERANCE_PCT = 5.0;

    public static function baselinePath(): string
    {
        return \dirname(__DIR__) . '/history/baseline.json';
    }

    public static function ledgerPath(): string
    {
        return \dirname(__DIR__) . '/history/ledger.jsonl';
    }

    /**
     * Default perf-gate config merged into a new best floor on first seed.
     *
     * @return array<string, mixed>
     */
    public static function defaultGateConfig(): array
    {
        return [
            'sizes' => [1_000],
            'tolerance_pct' => [
                'latency' => self::DEFAULT_LATENCY_TOLERANCE_PCT,
            ],
        ];
    }

    /**
     * Ensure baseline document has {@see BASELINE_KIND_BEST_FLOOR} metadata.
     *
     * @param array<string, mixed> $baseline
     *
     * @return array<string, mixed>
     */
    public static function normalizeBestFloor(array $baseline): array
    {
        $baseline['kind'] = self::BASELINE_KIND_BEST_FLOOR;

        if (!isset($baseline['gate']) || !\is_array($baseline['gate'])) {
            $baseline['gate'] = self::defaultGateConfig();
        }

        return $baseline;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function loadBaseline(): ?array
    {
        $path = self::baselinePath();

        if (!\is_readable($path)) {
            return null;
        }

        $raw = \file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $decoded = \json_decode($raw, true);

        if (!\is_array($decoded)) {
            return null;
        }

        if (($decoded['invalid'] ?? false) === true) {
            return null;
        }

        // Legacy baselines tagged with git are not comparable — discard.
        if (isset($decoded['git_head'])) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public static function saveBaseline(array $snapshot, ?string $note = null): void
    {
        $dir = \dirname(self::baselinePath());

        if (!\is_dir($dir)) {
            \mkdir($dir, 0755, true);
        }

        if ($note !== null && $note !== '') {
            $snapshot['note'] = $note;
        }

        $snapshot = self::normalizeBestFloor($snapshot);
        $snapshot['recorded_at'] = \gmdate('c');

        \file_put_contents(
            self::baselinePath(),
            \json_encode($snapshot, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n",
        );
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public static function appendLedger(array $snapshot): void
    {
        $dir = \dirname(self::ledgerPath());

        if (!\is_dir($dir)) {
            \mkdir($dir, 0755, true);
        }

        $line = \json_encode([
            'at'    => \gmdate('c'),
            'sizes' => $snapshot['sizes'] ?? [],
            'note'  => $snapshot['note'] ?? null,
        ], \JSON_UNESCAPED_SLASHES);

        if ($line !== false) {
            \file_put_contents(self::ledgerPath(), $line . "\n", \FILE_APPEND);
        }
    }

    /**
     * @param array<string, mixed> $row experiments.php size row (baseline experiment)
     *
     * @return array<string, float>
     */
    public static function metricsFromExperimentRow(array $row): array
    {
        $matrix = $row['matrix'] ?? [];

        return [
            'lookup_warm_p95_us' => (float) ($matrix['lookup_warm']['p95_us'] ?? 0),
            'update_p95_us'      => (float) ($matrix['update']['p95_us'] ?? 0),
            'insert_p95_us'      => (float) ($matrix['insert']['p95_us'] ?? 0),
        ];
    }

    /**
     * @param array<string, float|int> $aggregatedRow from aggregateMatrixByN()
     *
     * @return array<string, float>
     */
    public static function metricsFromMatrixRow(array $aggregatedRow): array
    {
        return [
            'lookup_warm_p95_us' => (float) ($aggregatedRow['lookup_warm_p95_us'] ?? 0),
            'update_p95_us'      => (float) ($aggregatedRow['update_p95_us'] ?? 0),
            'insert_p95_us'      => (float) ($aggregatedRow['insert_p95_us'] ?? 0),
        ];
    }

    /**
     * Median of numeric samples.
     *
     * @param list<float|int> $values
     */
    public static function median(array $values): float
    {
        $n = \count($values);
        if ($n === 0) {
            return 0.0;
        }

        \sort($values, \SORT_NUMERIC);
        $mid = \intdiv($n, 2);

        if (($n & 1) === 1) {
            return (float) $values[$mid];
        }

        return (((float) $values[$mid - 1]) + ((float) $values[$mid])) / 2.0;
    }

    /**
     * Aggregate several by-size snapshots using the median for each metric.
     *
     * @param list<array<int|string, array<string, float>>> $samples
     *
     * @return array<int|string, array<string, float>>
     */
    public static function medianBySize(array $samples): array
    {
        if ($samples === []) {
            return [];
        }

        $bucketed = [];

        foreach ($samples as $sample) {
            foreach ($sample as $n => $metrics) {
                foreach (self::METRICS as $metric) {
                    $bucketed[(string) $n][$metric][] = (float) ($metrics[$metric] ?? 0.0);
                }
            }
        }

        $median = [];
        foreach ($bucketed as $n => $metricsByName) {
            foreach (self::METRICS as $metric) {
                $median[$n][$metric] = self::median($metricsByName[$metric] ?? []);
            }
        }

        \ksort($median, \SORT_NUMERIC);

        return $median;
    }

    /**
     * Build a snapshot from the median of several samples.
     *
     * @param list<array<int|string, array<string, float>>> $samples
     *
     * @return array<string, mixed>
     */
    public static function buildMedianSnapshot(array $samples, ?string $note = null): array
    {
        return self::buildSnapshot(self::medianBySize($samples), $note);
    }

    /**
     * @param array<int|string, array<string, float>> $bySize
     *
     * @return array<string, mixed>
     */
    public static function buildSnapshot(array $bySize, ?string $note = null): array
    {
        $sizes = [];

        foreach ($bySize as $n => $metrics) {
            $sizes[(string) $n] = $metrics;
        }

        \ksort($sizes, \SORT_NUMERIC);

        return [
            'recorded_at' => \gmdate('c'),
            'note'        => $note,
            'sizes'       => $sizes,
        ];
    }

    /**
     * @param array<string, mixed>|null $baseline
     * @param array<int|string, array<string, float>> $currentBySize
     *
     * @return list<array<string, mixed>>
     */
    public static function compare(?array $baseline, array $currentBySize): array
    {
        $rows = [];
        $baseSizes = $baseline['sizes'] ?? [];

        foreach ($currentBySize as $n => $current) {
            $key = (string) $n;
            $prev = \is_array($baseSizes[$key] ?? null) ? $baseSizes[$key] : null;

            foreach (self::METRICS as $metric) {
                $now = (float) ($current[$metric] ?? 0);
                $was = $prev !== null ? (float) ($prev[$metric] ?? 0) : null;
                $deltaPct = null;
                $status = 'new';

                if ($was !== null && $was > 0.0) {
                    $deltaPct = (($now - $was) / $was) * 100.0;

                    if (\abs($deltaPct) < 0.5) {
                        $status = 'flat';
                    } elseif ($now < $was) {
                        $status = 'improved';
                    } else {
                        $status = 'regressed';
                    }
                } elseif ($was !== null && $was === 0.0 && $now === 0.0) {
                    $status = 'flat';
                    $deltaPct = 0.0;
                }

                $rows[] = [
                    'n'          => (int) $n,
                    'metric'     => $metric,
                    'now'        => $now,
                    'was'        => $was,
                    'delta_pct'  => $deltaPct,
                    'status'     => $status,
                ];
            }
        }

        return $rows;
    }

    /**
     * Allowed percent increase vs baseline for a metric (lower observed is always OK).
     *
     * @param array<string, mixed>|null $gateConfig baseline['gate']
     */
    public static function gateTolerancePct(string $metric, ?array $gateConfig): float
    {
        $tolerances = \is_array($gateConfig) ? ($gateConfig['tolerance_pct'] ?? []) : [];

        return (float) ($tolerances['latency'] ?? $tolerances['default'] ?? self::DEFAULT_LATENCY_TOLERANCE_PCT);
    }

    /**
     * True when observed value is within gate tolerance vs baseline (lower is better).
     *
     * @param array<string, mixed>|null $gateConfig baseline['gate']
     */
    public static function withinGateTolerance(
        string $metric,
        float $now,
        float $was,
        ?array $gateConfig,
    ): bool {
        if ($was <= 0.0 && $now <= 0.0) {
            return true;
        }

        if ($was <= 0.0) {
            return true;
        }

        $tolerance = self::gateTolerancePct($metric, $gateConfig);
        $maxAllowed = $was * (1.0 + $tolerance / 100.0);

        return $now <= $maxAllowed;
    }

    /**
     * Rows that exceed baseline gate tolerance (perf regressions).
     *
     * @param list<array<string, mixed>> $comparisonRows from {@see compare()}
     * @param list<int>|null             $sizesFilter    limit to these N values (null = all rows)
     *
     * @return list<array<string, mixed>>
     */
    public static function gateViolations(
        array $comparisonRows,
        ?array $baseline,
        ?array $sizesFilter = null,
    ): array {
        if ($baseline === null) {
            return [['reason' => 'no_baseline']];
        }

        $gate = \is_array($baseline['gate'] ?? null) ? $baseline['gate'] : [];
        $allowedSizes = $sizesFilter ?? ($gate['sizes'] ?? null);
        $violations = [];

        foreach ($comparisonRows as $row) {
            if ($row['was'] === null) {
                continue;
            }

            if ($allowedSizes !== null && !\in_array((int) $row['n'], $allowedSizes, true)) {
                continue;
            }

            $now = (float) $row['now'];
            $was = (float) $row['was'];

            if (self::withinGateTolerance((string) $row['metric'], $now, $was, $gate)) {
                continue;
            }

            $tolerance = self::gateTolerancePct((string) $row['metric'], $gate);
            $maxAllowed = $was > 0.0 ? $was * (1.0 + $tolerance / 100.0) : 0.0;

            $violations[] = $row + [
                'tolerance_pct' => $tolerance,
                'max_allowed'   => $maxAllowed,
            ];
        }

        return $violations;
    }

    /**
     * @param list<array<string, mixed>> $violations from {@see gateViolations()}
     */
    public static function formatGateFailures(array $violations): string
    {
        if ($violations === []) {
            return '';
        }

        if (isset($violations[0]['reason']) && $violations[0]['reason'] === 'no_baseline') {
            return 'perf gate: no baseline at ' . self::baselinePath()
                . ' — run: php benchmarks/track.php --record-best' . "\n";
        }

        $lines = [
            'perf gate FAIL — metrics exceeded baseline tolerance:',
            '',
            \str_pad('N', 7)
                . \str_pad('metric', 28)
                . \str_pad('now', 12)
                . \str_pad('max', 12)
                . \str_pad('was', 12)
                . 'tol',
        ];

        foreach ($violations as $row) {
            $now = self::formatMetricValue((string) $row['metric'], (float) $row['now']);
            $was = self::formatMetricValue((string) $row['metric'], (float) $row['was']);
            $max = self::formatMetricValue((string) $row['metric'], (float) $row['max_allowed']);
            $tol = \sprintf('%.1f%%', (float) $row['tolerance_pct']);

            $lines[] = \str_pad((string) $row['n'], 7)
                . \str_pad(self::metricLabel((string) $row['metric']), 28)
                . \str_pad($now, 12)
                . \str_pad($max, 12)
                . \str_pad($was, 12)
                . $tol;
        }

        $lines[] = '';
        $lines[] = 'Update floor after deliberate wins: php benchmarks/track.php --record-best';
        $lines[] = '';

        return \implode("\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public static function formatScorecard(array $rows, ?array $baseline): string
    {
        $lines = [];
        $recorded = $baseline['recorded_at'] ?? 'never';
        $note = $baseline['note'] ?? '';

        $lines[] = '';
        $lines[] = '=== Fast perf vs best floor ===';

        if ($baseline === null) {
            $lines[] = 'No best floor yet — seed with: php benchmarks/track.php --record-best';
        } else {
            $lines[] = 'Best floor: ' . $recorded . ($note !== '' ? ' — ' . $note : '');
        }

        $lines[] = \str_pad('N', 7)
            . \str_pad('metric', 28)
            . \str_pad('now', 12)
            . \str_pad('was', 12)
            . \str_pad('Δ', 10)
            . 'status';

        $improved = 0;
        $regressed = 0;
        $flat = 0;

        $lastN = null;

        foreach ($rows as $row) {
            if ($lastN !== null && $lastN !== $row['n']) {
                $lines[] = '';
            }

            $lastN = $row['n'];
            $now = self::formatMetricValue($row['metric'], (float) $row['now']);
            $was = $row['was'] === null ? '—' : self::formatMetricValue($row['metric'], (float) $row['was']);
            $delta = $row['delta_pct'] === null ? '—' : self::formatDelta((float) $row['delta_pct']);
            $status = \strtoupper((string) $row['status']);

            if ($row['status'] === 'improved') {
                $improved++;
            } elseif ($row['status'] === 'regressed') {
                $regressed++;
            } elseif ($row['status'] === 'flat') {
                $flat++;
            }

            $lines[] = \str_pad((string) $row['n'], 7)
                . \str_pad(self::metricLabel($row['metric']), 28)
                . \str_pad($now, 12)
                . \str_pad($was, 12)
                . \str_pad($delta, 10)
                . $status;
        }

        $lines[] = '';
        $lines[] = \sprintf(
            'Net: %d improved, %d regressed, %d flat (lower latency µs p95 is better)',
            $improved,
            $regressed,
            $flat,
        );

        if ($baseline !== null) {
            $lines[] = 'Update best floor after wins: php benchmarks/track.php --record-best';
        }

        $lines[] = '===';
        $lines[] = '';

        return \implode("\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public static function formatMarkdown(array $rows, ?array $baseline): string
    {
        if ($rows === []) {
            return '_No comparison data._';
        }

        $md = [];
        $md[] = '## vs best floor (`benchmarks/history/baseline.json`)';
        $md[] = '';
        $md[] = '_Per-metric latency bests (lower µs p95 is better). Updated with `--record-best` only._';
        $md[] = '';

        if ($baseline === null) {
            $md[] = '_No best floor yet. Run `php benchmarks/track.php --record-best` after a good run._';

            return \implode("\n", $md);
        }

        $md[] = 'Recorded: **' . ($baseline['recorded_at'] ?? '?') . '**'
            . (($baseline['note'] ?? '') !== '' ? ' — ' . $baseline['note'] : '');
        $md[] = '';
        $md[] = '| N | Metric | Now | Was | Δ | Status |';
        $md[] = '|---:|---|---:|---:|---:|---|';

        foreach ($rows as $row) {
            $now = self::formatMetricValue($row['metric'], (float) $row['now']);
            $was = $row['was'] === null ? '—' : self::formatMetricValue($row['metric'], (float) $row['was']);
            $delta = $row['delta_pct'] === null ? '—' : self::formatDelta((float) $row['delta_pct']);

            $md[] = \sprintf(
                '| %d | %s | %s | %s | %s | %s |',
                $row['n'],
                self::metricLabel($row['metric']),
                $now,
                $was,
                $delta,
                $row['status'],
            );
        }

        return \implode("\n", $md);
    }

    /**
     * Merge improved metrics from current run into the committed best floor.
     *
     * Never replaces a metric with a worse value.
     *
     * @param array<string, mixed> $baseline
     * @param array<int|string, array<string, float>> $currentBySize
     */
    public static function mergeBestIntoBaseline(array $baseline, array $currentBySize): bool
    {
        $changed = false;
        $baseline = self::normalizeBestFloor($baseline);
        $sizes = $baseline['sizes'] ?? [];

        foreach ($currentBySize as $n => $current) {
            $key = (string) $n;

            if (!isset($sizes[$key]) || !\is_array($sizes[$key])) {
                $sizes[$key] = $current;
                $changed = true;

                continue;
            }

            foreach (self::METRICS as $metric) {
                $now = (float) ($current[$metric] ?? 0);
                $was = (float) ($sizes[$key][$metric] ?? 0);

                if ($was <= 0.0) {
                    if ($now > 0.0) {
                        $sizes[$key][$metric] = $now;
                        $changed = true;
                    }

                    continue;
                }

                if ($now > 0.0 && $now < $was) {
                    $sizes[$key][$metric] = $now;
                    $changed = true;
                }
            }
        }

        $baseline['sizes'] = $sizes;

        if ($changed) {
            self::saveBaseline($baseline, (string) ($baseline['note'] ?? 'best floor merge'));
        }

        return $changed;
    }

    /**
     * Seed the committed best floor from a full snapshot (first run only).
     *
     * @param array<string, mixed> $snapshot from {@see buildSnapshot()}
     */
    public static function seedBestFloor(array $snapshot, ?string $note = null): void
    {
        if ($note !== null && $note !== '') {
            $snapshot['note'] = $note;
        }

        self::saveBaseline($snapshot, $note ?? 'initial best floor');
    }

    private static function metricLabel(string $metric): string
    {
        return match ($metric) {
            'lookup_warm_p95_us' => 'lookup warm p95 µs',
            'update_p95_us'      => 'update p95 µs',
            'insert_p95_us'      => 'insert p95 µs',
            default              => $metric,
        };
    }

    private static function formatMetricValue(string $metric, float $value): string
    {
        return \sprintf('%.1f', $value);
    }

    private static function formatDelta(float $pct): string
    {
        if ($pct < 0) {
            return \sprintf('▼ %.1f%%', \abs($pct));
        }

        if ($pct > 0) {
            return \sprintf('▲ %.1f%%', $pct);
        }

        return '0%';
    }
}
