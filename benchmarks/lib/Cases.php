<?php declare(strict_types = 1);

/**
 * Registered benchmark cases for Fast.
 *
 * @package Bench
 * @license https://opensource.org/licenses/MIT MIT License
 * @link    https://github.com/johnnyjoy/fast
 */
namespace Bench;

/**
 * Registered benchmark cases and their runners.
 *
 * Each entry in {@see registry()} names a callable that receives a
 * {@see CaseContext} and returns a structured result payload for {@see Runner}.
 *
 * @package Bench
 */
final class Cases
{
    /**
     * @return array<string, array{id: string, name: string, category: string, perf_primary: bool, fork: bool}>
     */
    public static function registry(): array
    {
        return [
            'fast_op_matrix'           => ['id' => 'fast_op_matrix', 'name' => 'Operation matrix', 'category' => 'fast', 'perf_primary' => true, 'fork' => false],
            'baseline_direct_shm'       => ['id' => 'baseline_direct_shm', 'name' => 'Direct SysV shm slots (reference)', 'category' => 'reference', 'perf_primary' => false, 'fork' => false],
            'fast_insert_string'       => ['id' => 'fast_insert_string', 'name' => 'New string-key inserts', 'category' => 'fast', 'perf_primary' => true, 'fork' => false],
            'fast_update_string'       => ['id' => 'fast_update_string', 'name' => 'Existing string-key updates', 'category' => 'fast', 'perf_primary' => true, 'fork' => false],
            'fast_read_string'         => ['id' => 'fast_read_string', 'name' => 'String-key reads', 'category' => 'fast', 'perf_primary' => true, 'fork' => false],
            'fast_integer_keys'        => ['id' => 'fast_integer_keys', 'name' => 'Integer keys', 'category' => 'fast', 'perf_primary' => true, 'fork' => false],
            'fast_compound_ops'        => ['id' => 'fast_compound_ops', 'name' => 'Compound operations', 'category' => 'fast', 'perf_primary' => true, 'fork' => false],
            'fast_missing_keys'        => ['id' => 'fast_missing_keys', 'name' => 'Missing key behavior', 'category' => 'correctness', 'perf_primary' => false, 'fork' => false],
            'fast_reserved_keys'       => ['id' => 'fast_reserved_keys', 'name' => 'Reserved keys', 'category' => 'correctness', 'perf_primary' => false, 'fork' => false],
            'fast_count'               => ['id' => 'fast_count', 'name' => 'count() O(1)', 'category' => 'fast', 'perf_primary' => true, 'fork' => false],
            'fast_unset'               => ['id' => 'fast_unset', 'name' => 'Unset keys', 'category' => 'fast', 'perf_primary' => true, 'fork' => false],
            'fast_stale_cache_unset'   => ['id' => 'fast_stale_cache_unset', 'name' => 'Fork stale cache unset', 'category' => 'fork', 'perf_primary' => false, 'fork' => true],
            'fork_new_key_visibility'   => ['id' => 'fork_new_key_visibility', 'name' => 'Cross-process new key', 'category' => 'fork', 'perf_primary' => false, 'fork' => true],
            'fork_concurrent_incr'      => ['id' => 'fork_concurrent_incr', 'name' => 'Concurrent increments', 'category' => 'fork', 'perf_primary' => true, 'fork' => true],
            'fork_flat_vs_striped'      => ['id' => 'fork_flat_vs_striped', 'name' => 'Flat vs Striped (same fork workload)', 'category' => 'fork', 'perf_primary' => true, 'fork' => true],
            'fork_mixed_workload'       => ['id' => 'fork_mixed_workload', 'name' => 'Mixed fork workload', 'category' => 'fork', 'perf_primary' => true, 'fork' => true],
            'fast_distribution'        => ['id' => 'fast_distribution', 'name' => 'Shard/submap distribution', 'category' => 'analysis', 'perf_primary' => false, 'fork' => false],
            'fast_large_values'        => ['id' => 'fast_large_values', 'name' => 'Large payload sizes', 'category' => 'fast', 'perf_primary' => true, 'fork' => false],
        ];
    }

    /**
     * Execute a registered benchmark case by id.
     *
     * @param string               $id   Case id from {@see registry()}
     * @param array<string, mixed> $meta Registry metadata for the case
     * @param CaseContext          $ctx  Shared runner context
     *
     * @return array<string, mixed> Structured result payload for {@see Runner}
     */
    public static function run(string $id, array $meta, CaseContext $ctx): array
    {
        if (($meta['fork'] ?? false) && !CaseContext::hasPcntl()) {
            return self::skipped($meta, 'pcntl_unavailable');
        }

        $ctx->warnings->expect([], 0, 0);
        $ctx->warnings->install();

        try {
            $result = match ($id) {
                'fast_op_matrix'         => self::fastOpMatrix($meta, $ctx),
                'baseline_direct_shm'     => self::baselineDirectShm($meta, $ctx),
                'fast_insert_string'     => self::fastInsertString($meta, $ctx),
                'fast_update_string'     => self::fastUpdateString($meta, $ctx),
                'fast_read_string'       => self::fastReadString($meta, $ctx),
                'fast_integer_keys'      => self::fastIntegerKeys($meta, $ctx),
                'fast_compound_ops'      => self::fastCompoundOps($meta, $ctx),
                'fast_missing_keys'      => self::fastMissingKeys($meta, $ctx),
                'fast_reserved_keys'     => self::fastReservedKeys($meta, $ctx),
                'fast_count'             => self::fastCount($meta, $ctx),
                'fast_unset'             => self::fastUnset($meta, $ctx),
                'fast_stale_cache_unset' => self::fastStaleCacheUnset($meta, $ctx),
                'fork_new_key_visibility' => self::forkNewKeyVisibility($meta, $ctx),
                'fork_concurrent_incr'    => self::forkConcurrentIncr($meta, $ctx),
                'fork_flat_vs_striped'    => self::forkFlatVsStriped($meta, $ctx),
                'fork_mixed_workload'     => self::forkMixedWorkload($meta, $ctx),
                'fast_distribution'      => self::fastDistribution($meta, $ctx),
                'fast_large_values'      => self::fastLargeValues($meta, $ctx),
                default                   => self::fail($meta, 'unknown case'),
            };
        } catch (\Throwable $e) {
            $result = self::shell($meta);
            $result['result'] = 'fail';
            $result['correctness'] = [
                'passed' => false,
                'checks'   => [['id' => 'exception', 'passed' => false, 'detail' => $e->getMessage()]],
                'detail'   => $e->getMessage(),
            ];
        } finally {
            $ctx->warnings->restore();
        }

        $warn = $ctx->warnings->validate();
        $result['warnings'] = [
            'expected_patterns'   => [],
            'expected_count_min'  => $result['warnings']['expected_count_min'] ?? 0,
            'expected_count_max'  => $result['warnings']['expected_count_max'] ?? 0,
            'observed'            => $warn['observed'],
            'unexpected_count'    => $warn['unexpected_count'],
        ];

        if (!$warn['passed'] && !($result['skipped'] ?? false)) {
            $result['result'] = 'fail';
            $result['correctness']['passed'] = false;
        }

        if (($result['runs'] ?? []) !== [] && ($result['aggregate'] ?? []) === []) {
            $result['aggregate'] = Stats::aggregateRuns($result['runs']);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function shell(array $meta): array
    {
        return [
            'id'            => $meta['id'],
            'name'          => $meta['name'],
            'category'      => $meta['category'],
            'perf_primary'  => $meta['perf_primary'],
            'skipped'       => false,
            'skipped_reason'=> null,
            'result'        => 'pass',
            'correctness'   => ['passed' => true, 'checks' => [], 'detail' => ''],
            'warnings'      => ['expected_count_min' => 0, 'expected_count_max' => 0],
            'runs'          => [],
            'aggregate'     => [],
        ];
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function skipped(array $meta, string $reason): array
    {
        $r = self::shell($meta);
        $r['skipped'] = true;
        $r['skipped_reason'] = $reason;
        $r['result'] = 'skip';

        return $r;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function fail(array $meta, string $detail): array
    {
        $r = self::shell($meta);
        $r['result'] = 'fail';
        $r['correctness'] = ['passed' => false, 'checks' => [], 'detail' => $detail];

        return $r;
    }

    /**
     * Fast op matrix — same harness as tests/index_matrix_lib.php (Matrix\benchSize).
     *
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function fastOpMatrix(array $meta, CaseContext $ctx): array
    {
        $r = self::shell($meta);
        $r['matrix_runs'] = [];
        $checks = [];

        if (!\function_exists('Matrix\benchSize')) {
            return self::fail($meta, 'index_matrix_lib not loaded');
        }

        for ($run = 0; $run < $ctx->config->iterations; $run++) {
            foreach ($ctx->config->sizes as $n) {
                $block = \Matrix\benchSize('shard', $n, $run * 100_000 + $n);
                $block['run_index'] = $run;
                $r['matrix_runs'][] = $block;

                foreach (['insert', 'lookup_warm', 'update'] as $op) {
                    /** @var array{avg_us: float, p50_us: float, p95_us: float, p99_us: float, n: int} $stats */
                    $stats = $block[$op];
                    $r['runs'][] = [
                        'run_index'      => $run,
                        'n'              => $n,
                        'operation'      => $op,
                        'latency_unit'   => 'per_op_us',
                        'latency_method' => 'per_op_samples',
                        'operations'     => $stats['n'],
                        'total_seconds'  => 0.0,
                        'ops_per_sec'    => $stats['p95_us'] > 0 ? (int) (1_000_000 / $stats['p95_us']) : 0,
                        'latency_ns'     => [
                            'min'    => (int) \round($stats['avg_us'] * 1_000),
                            'mean'   => (int) \round($stats['avg_us'] * 1_000),
                            'median' => (int) \round($stats['p50_us'] * 1_000),
                            'p90'    => (int) \round($stats['p95_us'] * 1_000),
                            'p95'    => (int) \round($stats['p95_us'] * 1_000),
                            'p99'    => (int) \round($stats['p99_us'] * 1_000),
                            'max'    => (int) \round($stats['p99_us'] * 1_000),
                            'stdev'  => 0,
                        ],
                        'memory_bytes'       => ['before' => 0, 'after' => 0, 'peak' => 0],
                        'shm_index_us_avg'   => $block['shm_index_us_avg'],
                        'sem_hold_us_avg'    => $block['sem_hold_us_avg'],
                        'index_kb'           => $block['index_kb'],
                    ];
                }

                $checks[] = [
                    'id'     => "matrix_n{$n}_r{$run}",
                    'passed' => ($block['valid'] ?? false) && ($block['valid_full'] ?? false),
                    'detail' => \sprintf(
                        'insert_p95=%.1fus update_p95=%.1fus',
                        (float) ($block['insert']['p95_us'] ?? 0),
                        (float) ($block['update']['p95_us'] ?? 0),
                    ),
                ];
            }
        }

        $r['correctness'] = ['passed' => !\in_array(false, \array_column($checks, 'passed'), true), 'checks' => $checks, 'detail' => ''];
        $r['result'] = $r['correctness']['passed'] ? 'pass' : 'fail';

        return $r;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function baselineDirectShm(array $meta, CaseContext $ctx): array
    {
        $r = self::shell($meta);
        $checks = [];
        $name = 'fast-bench-direct-' . \getmypid() . '-' . \time();
        $key = \crc32('fast-bench-direct:' . $name) & 0x7fffffff;
        $shm = @\shm_attach($key, $ctx->config->segmentSize, 0666);

        if ($shm === false) {
            return self::fail($meta, 'shm_attach failed for direct baseline');
        }

        try {
            for ($run = 0; $run < $ctx->config->iterations; $run++) {
                foreach ($ctx->config->sizes as $n) {
                    $write = $ctx->timeOperation('direct_shm_put', $n, static function (int $i) use ($shm): void {
                        \shm_put_var($shm, $i + 1, $i);
                    });
                    $write['run_index'] = $run;
                    $r['runs'][] = $write;

                    $read = $ctx->timeOperation('direct_shm_get', $n, static function (int $i) use ($shm, $n): void {
                        $_ = \shm_get_var($shm, ($i % $n) + 1);
                    });
                    $read['run_index'] = $run;
                    $r['runs'][] = $read;

                    $ok = true;

                    foreach (CaseContext::sampleIndices($n, 8) as $idx) {
                        if (\shm_get_var($shm, $idx + 1) !== $idx) {
                            $ok = false;
                            break;
                        }
                    }

                    $checks[] = ['id' => "direct_n{$n}_r{$run}", 'passed' => $ok, 'detail' => 'slot round-trip'];
                }
            }
        } finally {
            if (!$ctx->config->keepSegments) {
                @\shm_remove($shm);
            }
        }

        $r['correctness'] = ['passed' => !\in_array(false, \array_column($checks, 'passed'), true), 'checks' => $checks, 'detail' => 'raw shm slots'];
        $r['result'] = $r['correctness']['passed'] ? 'pass' : 'fail';

        return $r;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function fastInsertString(array $meta, CaseContext $ctx): array
    {
        $r = self::shell($meta);
        $checks = [];

        for ($run = 0; $run < $ctx->config->iterations; $run++) {
                foreach ($ctx->config->sizes as $n) {
                    $s = $ctx->makeStore('insert_r' . $run . '_n' . $n, 'i');

                    try {
                        $timed = $ctx->timeOperation('fast_insert', $n, static function (int $i) use ($s): void {
                            $s['key:' . $i] = $i;
                        });
                        $timed['run_index'] = $run;
                        $r['runs'][] = $timed;

                        $countOk = \count($s) === $n;
                        $sampleOk = true;

                        foreach (CaseContext::sampleIndices($n) as $idx) {
                            if ($s['key:' . $idx] !== $idx) {
                                $sampleOk = false;
                                break;
                            }
                        }

                        $checks[] = ['id' => "count_n{$n}", 'passed' => $countOk, 'detail' => 'count=' . \count($s)];
                        $checks[] = ['id' => "sample_n{$n}", 'passed' => $sampleOk, 'detail' => 'sample read'];
                    } finally {
                        $ctx->cleanupStore($s);
                    }
                }
        }

        $r['correctness'] = ['passed' => !\in_array(false, \array_column($checks, 'passed'), true), 'checks' => $checks, 'detail' => ''];
        $r['result'] = $r['correctness']['passed'] ? 'pass' : 'fail';

        return $r;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function fastUpdateString(array $meta, CaseContext $ctx): array
    {
        $r = self::shell($meta);
        $checks = [];

        for ($run = 0; $run < $ctx->config->iterations; $run++) {
            foreach ($ctx->config->sizes as $n) {
                $s = $ctx->makeStore('update_r' . $run . '_n' . $n, 'u');

                try {
                    for ($i = 0; $i < $n; $i++) {
                        $s['key:' . $i] = $i;
                    }

                    $before = \count($s);
                    $timed = $ctx->timeOperation('fast_update', $n, static function (int $i) use ($s, $n): void {
                        $s['key:' . ($i % $n)] = $i + 1;
                    });
                    $timed['run_index'] = $run;
                    $r['runs'][] = $timed;

                    $sampleOk = $s['key:0'] === 1;
                    $checks[] = ['id' => "update_n{$n}", 'passed' => $sampleOk && \count($s) === $before, 'detail' => 'count unchanged'];
                } finally {
                    $ctx->cleanupStore($s);
                }
            }
        }

        $r['correctness'] = ['passed' => !\in_array(false, \array_column($checks, 'passed'), true), 'checks' => $checks, 'detail' => ''];
        $r['result'] = $r['correctness']['passed'] ? 'pass' : 'fail';

        return $r;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function fastReadString(array $meta, CaseContext $ctx): array
    {
        $r = self::shell($meta);
        $checks = [];

        for ($run = 0; $run < $ctx->config->iterations; $run++) {
            foreach ($ctx->config->sizes as $n) {
                $s = $ctx->makeStore('read_r' . $run . '_n' . $n, 'r');

                try {
                    for ($i = 0; $i < $n; $i++) {
                        $s['key:' . $i] = $i;
                    }

                    $before = \count($s);
                    $timed = $ctx->timeOperation('fast_read', $n, static function (int $i) use ($s, $n): void {
                        $_ = $s['key:' . ($i % $n)];
                    });
                    $timed['run_index'] = $run;
                    $r['runs'][] = $timed;

                    $checks[] = ['id' => "read_count_n{$n}", 'passed' => \count($s) === $before, 'detail' => 'count unchanged after reads'];
                } finally {
                    $ctx->cleanupStore($s);
                }
            }
        }

        $r['correctness'] = ['passed' => !\in_array(false, \array_column($checks, 'passed'), true), 'checks' => $checks, 'detail' => ''];
        $r['result'] = $r['correctness']['passed'] ? 'pass' : 'fail';

        return $r;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function fastIntegerKeys(array $meta, CaseContext $ctx): array
    {
        $r = self::shell($meta);
        $s = $ctx->makeStore('intkeys');
        $checks = [];

        try {
            $s[-1] = 'minus one';
            $s[-2147483648] = 'min';
            $s[2147483647] = 'max';
            $checks[] = ['id' => 'int_edges', 'passed' => $s[-1] === 'minus one', 'detail' => 'edge ints'];

            $n = $ctx->config->sizes[0];
            $timed = $ctx->timeOperation('fast_int_insert', $n, static function (int $i) use ($s): void {
                $s[$i] = $i;
            });
            $r['runs'][] = $timed;

            $checks[] = ['id' => 'int_count', 'passed' => \count($s) >= $n + 3, 'detail' => 'count includes edges'];
        } finally {
            $ctx->cleanupStore($s);
        }

        $r['correctness'] = ['passed' => !\in_array(false, \array_column($checks, 'passed'), true), 'checks' => $checks, 'detail' => ''];
        $r['result'] = $r['correctness']['passed'] ? 'pass' : 'fail';

        return $r;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function fastCompoundOps(array $meta, CaseContext $ctx): array
    {
        $r = self::shell($meta);
        $s = $ctx->makeStore('compound');
        $n = $ctx->config->compoundOps;

        try {
            $s['counter'] = 0;
            $s['float'] = 1.25;
            $s['text'] = 'a';

            $timed = $ctx->timeOperation('fast_compound', $n, static function (int $i) use ($s): void {
                $s['counter']++;
                $s['float'] += 0.25;

                if (\strlen((string) $s['text']) < 256) {
                    $s['text'] .= 'b';
                }
            }, false);
            $r['runs'][] = $timed;

            $counterOk = $s['counter'] === $n;
            $textLen = \strlen((string) $s['text']);
            $checks = [
                ['id' => 'counter', 'passed' => $counterOk, 'detail' => 'counter=' . $s['counter']],
                ['id' => 'text_bounded', 'passed' => $textLen <= 257, 'detail' => 'len=' . $textLen],
            ];
        } finally {
            $ctx->cleanupStore($s);
        }

        $r['correctness'] = ['passed' => !\in_array(false, \array_column($checks, 'passed'), true), 'checks' => $checks, 'detail' => ''];
        $r['result'] = $r['correctness']['passed'] ? 'pass' : 'fail';

        return $r;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function fastMissingKeys(array $meta, CaseContext $ctx): array
    {
        $r = self::shell($meta);
        $r['warnings']['expected_count_min'] = 2;
        $r['warnings']['expected_count_max'] = 2;
        $ctx->warnings->expect(['Undefined array key "missing"'], 2, 2);

        $s = $ctx->makeStore('missing');
        $checks = [];

        try {
            $bare = $s['missing'];
            $checks[] = ['id' => 'bare_null', 'passed' => $bare === null, 'detail' => 'bare read null'];

            $coalesce = ($s['missing'] ?? 'Hello');
            $checks[] = ['id' => 'coalesce', 'passed' => $coalesce === 'Hello', 'detail' => '?? default'];

            $s['missing']++;
            $checks[] = ['id' => 'missing_incr', 'passed' => $s['missing'] === 1, 'detail' => '++ creates key'];
        } finally {
            $ctx->cleanupStore($s);
        }

        $r['correctness'] = ['passed' => !\in_array(false, \array_column($checks, 'passed'), true), 'checks' => $checks, 'detail' => 'expected 2 warnings'];
        $r['result'] = $r['correctness']['passed'] ? 'pass' : 'fail';

        return $r;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function fastReservedKeys(array $meta, CaseContext $ctx): array
    {
        return self::skipped($meta, 'legacy_fast_reserved_keys');
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function fastCount(array $meta, CaseContext $ctx): array
    {
        $r = self::shell($meta);
        $checks = [];

        foreach ($ctx->config->countSizes as $n) {
            $s = $ctx->makeStore('count_n' . $n, 'c');

            try {
                $checks[] = ['id' => 'empty_' . $n, 'passed' => \count($s) === 0, 'detail' => 'empty=0'];

                for ($i = 0; $i < $n; $i++) {
                    $s['key:' . $i] = $i;
                }

                $timed = $ctx->timeOperation('fast_count', 1000, static function () use ($s): void {
                    $_ = \count($s);
                });
                $timed['n'] = $n;
                $r['runs'][] = $timed;
                $checks[] = ['id' => "count_{$n}", 'passed' => \count($s) === $n, 'detail' => "count={$n}"];
            } finally {
                $ctx->cleanupStore($s);
            }
        }

        $r['correctness'] = ['passed' => !\in_array(false, \array_column($checks, 'passed'), true), 'checks' => $checks, 'detail' => 'O(1) maintained count'];
        $r['result'] = $r['correctness']['passed'] ? 'pass' : 'fail';

        return $r;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function fastUnset(array $meta, CaseContext $ctx): array
    {
        $r = self::shell($meta);
        $n = $ctx->config->unsetSize;
        $s = $ctx->makeStore('unset');

        try {
            for ($i = 0; $i < $n; $i++) {
                $s['key:' . $i] = $i;
            }

            $timed = $ctx->timeOperation('fast_unset', $n, static function (int $i) use ($s): void {
                unset($s['key:' . $i]);
            });
            $r['runs'][] = $timed;

            $missingOk = !isset($s['key:0']);
            $countOk = \count($s) === 0;
            $checks = [
                ['id' => 'all_unset', 'passed' => $missingOk && $countOk, 'detail' => 'count after unset'],
            ];
        } finally {
            $ctx->cleanupStore($s);
        }

        $r['correctness'] = ['passed' => !\in_array(false, \array_column($checks, 'passed'), true), 'checks' => $checks, 'detail' => ''];
        $r['result'] = $r['correctness']['passed'] ? 'pass' : 'fail';

        return $r;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function fastStaleCacheUnset(array $meta, CaseContext $ctx): array
    {
        $r = self::shell($meta);
        $name = 'fast-bench-stale-' . \getmypid() . '-' . \time();
        $parent = new \Fast([
            'name'       => $name,
            'persistent' => true,
            'capacity'   => $ctx->config->capacity,
            'size'       => $ctx->config->segmentSize,
        ]);
        $ctx->segmentsCreated++;
        $checks = [];

        try {
            $parent['x'] = 1;
            $_ = $parent['x'];

            $pid = \pcntl_fork();

            if ($pid === -1) {
                return self::fail($meta, 'fork failed');
            }

            if ($pid > 0) {
                \pcntl_waitpid($pid, $status);

                if (!\pcntl_wifexited($status) || \pcntl_wexitstatus($status) !== 0) {
                    return self::fail($meta, 'child exit non-zero');
                }

                $ok = !isset($parent['x']);
                $checks[] = ['id' => 'stale_unset', 'passed' => $ok, 'detail' => 'no stale cache'];
            } else {
                CaseContext::forkChildRun(static function () use ($name): void {
                    $child = new \Fast($name);
                    unset($child['x']);
                });
            }
        } finally {
            $ctx->cleanupStore($parent);
        }

        $r['correctness'] = ['passed' => !\in_array(false, \array_column($checks, 'passed'), true), 'checks' => $checks, 'detail' => ''];
        $r['result'] = $r['correctness']['passed'] ? 'pass' : 'fail';

        return $r;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function forkNewKeyVisibility(array $meta, CaseContext $ctx): array
    {
        $r = self::shell($meta);
        $name = 'fast-bench-forkvis-' . \getmypid() . '-' . \time();
        $parent = new \Fast([
            'name'       => $name,
            'persistent' => true,
            'capacity'   => $ctx->config->capacity,
            'size'       => $ctx->config->segmentSize,
        ]);
        $ctx->segmentsCreated++;
        $checks = [];

        try {
            $pid = \pcntl_fork();

            if ($pid === -1) {
                return self::fail($meta, 'fork failed');
            }

            if ($pid > 0) {
                \pcntl_waitpid($pid, $status);

                if (!\pcntl_wifexited($status) || \pcntl_wexitstatus($status) !== 0) {
                    return self::fail($meta, 'child failed');
                }

                $ok = ($parent['a'] ?? null) === 1;
                $checks[] = ['id' => 'visibility', 'passed' => $ok, 'detail' => 'parent reads child key'];
            } else {
                CaseContext::forkChildRun(static function () use ($name): void {
                    $child = new \Fast($name);
                    $child['a'] = 1;
                });
            }
        } finally {
            $ctx->cleanupStore($parent);
        }

        $r['correctness'] = ['passed' => !\in_array(false, \array_column($checks, 'passed'), true), 'checks' => $checks, 'detail' => ''];
        $r['result'] = $r['correctness']['passed'] ? 'pass' : 'fail';

        return $r;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function forkConcurrentIncr(array $meta, CaseContext $ctx): array
    {
        $r = self::shell($meta);
        $checks = [];

        foreach ($ctx->config->forkIncrConfigs as $cfg) {
            $workers = (int) $cfg['workers'];
            $ops = (int) $cfg['ops_per_worker'];
            $name = 'fast-bench-incr-' . \getmypid() . '-' . $workers;
            $parent = new \Fast([
                'name'       => $name,
                'persistent' => true,
                'capacity'   => $ctx->config->capacity,
                'size'       => $ctx->config->segmentSize,
            ]);
            $parent['counter'] = 0;
            $ctx->segmentsCreated++;
            [$readEnds, $writeEnds] = CaseContext::forkPipeCreate($workers);
            $t0 = \hrtime(true);
            $pids = [];

            try {
                for ($w = 0; $w < $workers; $w++) {
                    $pid = \pcntl_fork();

                    if ($pid === -1) {
                        return self::fail($meta, 'fork failed');
                    }

                    if ($pid === 0) {
                        CaseContext::forkChildRun(static function () use ($writeEnds, $readEnds, $w, $name, $ops): void {
                            CaseContext::closeStreams($writeEnds);
                            $childRead = $readEnds[$w];
                            CaseContext::closeStreams(\array_values(\array_filter(
                                $readEnds,
                                static fn ($idx) => $idx !== $w,
                                ARRAY_FILTER_USE_KEY,
                            )));

                            if (!CaseContext::forkPipeWait($childRead)) {
                                throw new \RuntimeException('fork pipe barrier timeout');
                            }

                            \fclose($childRead);
                            $child = new \Fast($name);

                            for ($m = 0; $m < $ops; $m++) {
                                $child['counter']++;
                            }
                        });
                    }

                    $pids[] = $pid;
                    \fclose($readEnds[$w]);
                }

                CaseContext::forkPipeGo($writeEnds);
                CaseContext::closeStreams($readEnds);

                foreach ($pids as $pid) {
                    \pcntl_waitpid($pid, $status);

                    if (!\pcntl_wifexited($status) || \pcntl_wexitstatus($status) !== 0) {
                        $checks[] = ['id' => "worker_exit_{$workers}", 'passed' => false, 'detail' => 'child failed'];
                    }
                }

                $expected = $workers * $ops;
                $actual = (int) $parent['counter'];
                $elapsed = (\hrtime(true) - $t0) / 1_000_000_000;
                $totalOps = $expected;
                $r['runs'][] = [
                    'run_index'      => 0,
                    'n'              => $totalOps,
                    'operation'      => 'fork_concurrent_incr',
                    'latency_unit'   => 'per_op_ns',
                    'latency_method' => 'case_total',
                    'operations'     => $totalOps,
                    'total_seconds'  => $elapsed,
                    'ops_per_sec'    => $elapsed > 0 ? (int) ($totalOps / $elapsed) : 0,
                    'latency_ns'     => Stats::fromBatch($totalOps, $elapsed),
                    'memory_bytes'   => ['before' => 0, 'after' => 0, 'peak' => 0],
                    'workers'        => $workers,
                    'ops_per_worker' => $ops,
                ];

                $checks[] = [
                    'id'      => "counter_{$workers}x{$ops}",
                    'passed'  => $actual === $expected,
                    'detail'  => "expected={$expected} actual={$actual}",
                ];
            } finally {
                CaseContext::closeStreams($writeEnds);
                CaseContext::closeStreams($readEnds);
                $ctx->cleanupStore($parent);
            }
        }

        $r['correctness'] = ['passed' => !\in_array(false, \array_column($checks, 'passed'), true), 'checks' => $checks, 'detail' => ''];
        $r['result'] = $r['correctness']['passed'] ? 'pass' : 'fail';

        return $r;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function forkMixedWorkload(array $meta, CaseContext $ctx): array
    {
        $r = self::shell($meta);
        $checks = [];

        foreach ($ctx->config->forkMixedConfigs as $cfg) {
            $workers = (int) $cfg['workers'];
            $ops = (int) $cfg['ops_per_worker'];
            $name = 'fast-bench-mixed-' . \getmypid() . '-' . $workers;
            $parent = new \Fast([
                'name'       => $name,
                'persistent' => true,
                'capacity'   => $ctx->config->capacity,
                'size'       => $ctx->config->segmentSize,
            ]);
            $ctx->segmentsCreated++;

            for ($i = 0; $i < 100; $i++) {
                $parent['seed:' . $i] = $i;
            }

            $seed = $ctx->config->seed;
            $t0 = \hrtime(true);

            try {
                foreach (self::forkMixedOpCounts($ops) as $round) {
                    self::forkMixedRunRound(
                        $name,
                        $workers,
                        $seed,
                        (string) $round['mode'],
                        (int) $round['ops'],
                        $checks,
                    );
                }

                $elapsed = (\hrtime(true) - $t0) / 1_000_000_000;
                $totalOps = $workers * $ops;
                $r['runs'][] = [
                    'run_index'      => 0,
                    'n'              => $totalOps,
                    'operation'      => 'fork_mixed',
                    'latency_unit'   => 'per_op_ns',
                    'latency_method' => 'case_total',
                    'operations'     => $totalOps,
                    'total_seconds'  => $elapsed,
                    'ops_per_sec'    => $elapsed > 0 ? (int) ($totalOps / $elapsed) : 0,
                    'latency_ns'     => Stats::fromBatch($totalOps, $elapsed),
                    'memory_bytes'   => ['before' => 0, 'after' => 0, 'peak' => 0],
                ];

                $checks[] = ['id' => 'mixed_decode', 'passed' => \count($parent) > 0, 'detail' => 'count=' . \count($parent)];
            } catch (\RuntimeException $e) {
                return self::fail($meta, $e->getMessage());
            } finally {
                $ctx->cleanupStore($parent);
            }
        }

        $r['correctness'] = ['passed' => !\in_array(false, \array_column($checks, 'passed'), true), 'checks' => $checks, 'detail' => ''];
        $r['result'] = $r['correctness']['passed'] ? 'pass' : 'fail';

        return $r;
    }

    /**
     * Run the same multi-process write workload on Flat (stripes=1) and Striped,
     * reporting ops/sec side by side. Two workloads:
     *
     *   spread_write — random keys across a large keyspace (where Striped should win)
     *   hot_key_incr — one shared counter (where Striped should not win)
     *
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function forkFlatVsStriped(array $meta, CaseContext $ctx): array
    {
        $r = self::shell($meta);
        $r['stripes'] = $ctx->config->compareStripes;
        $r['comparison'] = [];
        $checks = [];
        $stripes = $ctx->config->compareStripes;

        if ($stripes < 2 || ($stripes & ($stripes - 1)) !== 0) {
            return self::fail($meta, 'compareStripes must be a power of two >= 2');
        }

        $workerCounts = \array_map(
            static fn (array $c): int => (int) $c['workers'],
            $ctx->config->forkIncrConfigs,
        );
        $ops = (int) ($ctx->config->forkIncrConfigs[0]['ops_per_worker'] ?? 1000);
        $matrix = EngineCompare::runMatrix(
            $workerCounts,
            $stripes,
            $ops,
            $ctx->config->writeKeySpace,
            $ctx->config->seed,
        );

        foreach ($matrix['scenarios'] as $row) {
            $r['comparison'][] = [
                'workload'            => (string) $row['id'],
                'title'               => (string) $row['title'],
                'workers'             => (int) $row['workers'],
                'ops_per_worker'      => $ops,
                'stripes'             => $stripes,
                'flat_ops_per_sec'    => (float) $row['flat'],
                'striped_ops_per_sec' => (float) $row['striped'],
                'speedup'             => (float) $row['ratio'],
            ];

            if ($row['id'] !== 'single_writer_spread') {
                $w = (int) $row['workers'];
                $checks[] = ['id' => "{$row['id']}_{$w}_ran", 'passed' => true, 'detail' => 'matrix row'];
            }
        }

        foreach ($matrix['scenarios'] as $row) {
            $totalOps = (int) $row['workers'] * $ops;
            if ($row['id'] === 'single_writer_spread') {
                $totalOps = (int) (($workerCounts[0] ?? 4) * $ops);
            }

            foreach (['flat' => 1, 'striped' => $stripes] as $engine => $engineStripes) {
                $r['runs'][] = [
                    'run_index'      => 0,
                    'n'              => $totalOps,
                    'operation'      => 'fork_flat_vs_striped_' . $row['id'],
                    'engine'         => $engine,
                    'stripes'        => $engineStripes,
                    'workload'       => $row['id'],
                    'latency_unit'   => 'per_op_ns',
                    'latency_method' => 'case_total',
                    'operations'     => $totalOps,
                    'total_seconds'  => 0.0,
                    'ops_per_sec'    => (int) ($row[$engine] ?? 0),
                    'latency_ns'     => Stats::emptyLatency(),
                    'memory_bytes'   => ['before' => 0, 'after' => 0, 'peak' => 0],
                    'workers'        => (int) $row['workers'],
                    'ops_per_worker' => $ops,
                ];
            }
        }

        $r['correctness'] = ['passed' => !\in_array(false, \array_column($checks, 'passed'), true), 'checks' => $checks, 'detail' => ''];
        $r['result'] = $r['correctness']['passed'] ? 'pass' : 'fail';

        return $r;
    }

    /**
     * Op-type counts for 60/25/10/5 mix (per worker, one op type per fork round).
     *
     * @return list<array{mode: string, ops: int}>
     */
    private static function forkMixedOpCounts(int $opsPerWorker): array
    {
        $readOps = (int) \round($opsPerWorker * 0.60);
        $updateOps = (int) \round($opsPerWorker * 0.25);
        $newOps = (int) \round($opsPerWorker * 0.10);
        $unsetOps = $opsPerWorker - $readOps - $updateOps - $newOps;

        return [
            ['mode' => 'read', 'ops' => $readOps],
            ['mode' => 'update', 'ops' => $updateOps],
            ['mode' => 'new', 'ops' => $newOps],
            ['mode' => 'unset', 'ops' => $unsetOps],
        ];
    }

    /**
     * One synchronized fork round: all workers run the same op type concurrently.
     *
     * @param list<array{id: string, passed: bool, detail: string}> $checks
     */
    private static function forkMixedRunRound(
        string $storeName,
        int $workers,
        int $seed,
        string $mode,
        int $roundOps,
        array &$checks,
    ): void {
        if ($roundOps <= 0) {
            return;
        }

        [$readEnds, $writeEnds] = CaseContext::forkPipeCreate($workers);
        $pids = [];

        try {
            for ($w = 0; $w < $workers; $w++) {
                $pid = \pcntl_fork();

                if ($pid === -1) {
                    throw new \RuntimeException('fork failed');
                }

                if ($pid === 0) {
                    CaseContext::forkChildRun(static function () use ($writeEnds, $readEnds, $w, $storeName, $seed, $mode, $roundOps): void {
                        CaseContext::closeStreams($writeEnds);
                        $childRead = $readEnds[$w];
                        CaseContext::closeStreams(\array_values(\array_filter(
                            $readEnds,
                            static fn ($idx) => $idx !== $w,
                            ARRAY_FILTER_USE_KEY,
                        )));

                        if (!CaseContext::forkPipeWait($childRead)) {
                            throw new \RuntimeException('fork pipe barrier timeout');
                        }

                        \fclose($childRead);
                        $child = new \Fast($storeName);
                        \mt_srand($seed + $w + \crc32($mode));

                        for ($m = 0; $m < $roundOps; $m++) {
                            $idx = \mt_rand(0, 99);

                            if ($mode === 'read') {
                                $_ = $child['seed:' . $idx];
                            } elseif ($mode === 'update') {
                                $child['seed:' . $idx] = $m;
                            } elseif ($mode === 'new') {
                                $child['new:' . $w . ':' . $m] = $m;
                            } else {
                                unset($child['seed:' . $idx]);
                            }
                        }
                    });
                }

                $pids[] = $pid;
                \fclose($readEnds[$w]);
            }

            CaseContext::forkPipeGo($writeEnds);
            CaseContext::closeStreams($readEnds);

            foreach ($pids as $pid) {
                \pcntl_waitpid($pid, $status);

                if (!\pcntl_wifexited($status) || \pcntl_wexitstatus($status) !== 0) {
                    $checks[] = [
                        'id'     => 'mixed_child_' . $mode,
                        'passed' => false,
                        'detail' => "child failed in {$mode} round",
                    ];
                }
            }
        } finally {
            CaseContext::closeStreams($writeEnds);
            CaseContext::closeStreams($readEnds);
        }
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function fastDistribution(array $meta, CaseContext $ctx): array
    {
        return self::skipped($meta, 'legacy_fast_shard_distribution');
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function fastLargeValues(array $meta, CaseContext $ctx): array
    {
        $r = self::shell($meta);
        $s = $ctx->makeStore('large');
        $payloads = [
            'int'          => 42,
            'short_string' => 'hello world',
            'small_array'  => ['a' => 1, 'b' => 2, 'c' => 3],
            'string_1kb'   => \str_repeat('x', 1024),
            'string_16kb'  => \str_repeat('y', 16 * 1024),
        ];

        if ($ctx->config->segmentSize >= 64 * 1024 * 1024) {
            $payloads['string_64kb'] = \str_repeat('z', 64 * 1024);
        }

        $ops = $ctx->config->largeValueOps;
        $checks = [];

        try {
            foreach ($payloads as $label => $value) {
                $key = 'payload:' . $label;
                $timed = $ctx->timeOperation('large_set_get', $ops, static function () use ($s, $key, $value): void {
                    $s[$key] = $value;
                    $_ = $s[$key];
                });
                $timed['payload'] = $label;
                $r['runs'][] = $timed;

                $roundOk = $s[$key] == $value;
                $checks[] = ['id' => $label, 'passed' => $roundOk, 'detail' => 'round-trip'];
            }
        } finally {
            $ctx->cleanupStore($s);
        }

        $r['correctness'] = ['passed' => !\in_array(false, \array_column($checks, 'passed'), true), 'checks' => $checks, 'detail' => 'PHP serialization via SysV'];
        $r['result'] = $r['correctness']['passed'] ? 'pass' : 'fail';

        return $r;
    }
}
