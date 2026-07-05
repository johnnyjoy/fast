<?php declare(strict_types = 1);

/**
 * Benchmark harness library for Fast.
 *
 * CLI {@see Config}, case {@see CaseContext}, warning {@see WarningCollector},
 * and run {@see Runner} orchestration.
 *
 * @package Bench
 * @license https://opensource.org/licenses/MIT MIT License
 * @link    https://github.com/johnnyjoy/fast
 */
namespace Bench;

/**
 * Immutable CLI configuration for {@see Runner} and {@see Cases}.
 *
 * Parsed once from argv via {@see fromArgv()}; `--quick` / `--full` replace the
 * whole preset via {@see modeDefaults()}, then later flags patch individual fields.
 *
 * @package Bench
 * @license https://opensource.org/licenses/MIT MIT License
 * @link    https://github.com/johnnyjoy/fast
 */
readonly final class Config
{
    /**
     * @param string $mode Preset name: default, quick, or full.
     * @param int $iterations Repetitions per case for latency aggregation.
     * @param int $warmup Discarded operations before timed samples.
     * @param list<int> $sizes Key counts for matrix / insert cases.
     * @param list<int> $countSizes Key counts for count() benchmarks.
     * @param int $unsetSize Key count for unset benchmarks.
     * @param int $distributionKeys Key count for distribution analysis.
     * @param int $compoundOps Operations per compound-ops case.
     * @param int $largeValueOps Operations per large-value case.
     * @param list<array{workers: int, ops_per_worker: int}> $forkIncrConfigs Fork increment workloads.
     * @param list<array{workers: int, ops_per_worker: int}> $forkMixedConfigs Fork mixed workloads.
     * @param int $compareStripes Stripe count for {@see Cases::forkFlatVsStriped} (Flat uses 1).
     * @param int $writeKeySpace Key space for spread-write fork comparison (random keys 0..N-1).
     * @param int $segmentSize Shared segment byte size passed to Fast stores.
     * @param int $capacity Directory slot capacity passed to Fast stores.
     * @param int $seed PRNG seed for reproducible key generation.
     * @param bool $keepSegments Leave SysV segments on disk after each case.
     * @param bool $emitJson Write JSON artifact alongside the run.
     * @param bool $emitCsv Write CSV artifact alongside the run.
     * @param bool $emitReport Write markdown report alongside the run.
     * @param list<string>|null $caseFilter When set, run only these case ids.
     */
    public function __construct(
        public string $mode = 'default',
        public int $iterations = 3,
        public int $warmup = 50,
        public array $sizes = [1000, 10000, 25000],
        public array $countSizes = [1000, 10000],
        public int $unsetSize = 10000,
        public int $distributionKeys = 25000,
        public int $compoundOps = 10000,
        public int $largeValueOps = 500,
        public array $forkIncrConfigs = [['workers' => 4, 'ops_per_worker' => 1000]],
        public array $forkMixedConfigs = [['workers' => 4, 'ops_per_worker' => 2000]],
        public int $compareStripes = 8,
        public int $writeKeySpace = 200_000,
        public int $segmentSize = 134217728,
        public int $capacity = 262144,
        public int $seed = 42,
        public bool $keepSegments = false,
        public bool $emitJson = true,
        public bool $emitCsv = true,
        public bool $emitReport = true,
        public ?array $caseFilter = null,
    ) {
    }

    /**
     * @param list<string> $argv CLI argv (script name at index 0).
     */
    public static function fromArgv(array $argv): self
    {
        $state = self::modeDefaults('default');

        for ($i = 1; $i < \count($argv); $i++) {
            $arg = $argv[$i];

            if ($arg === '--quick') {
                $state = self::modeDefaults('quick');
            } elseif ($arg === '--full') {
                $state = self::modeDefaults('full');
            } elseif ($arg === '--keep-segments') {
                $state['keepSegments'] = true;
            } elseif ($arg === '--no-color') {
                // reserved
            } elseif (\str_starts_with($arg, '--iterations=')) {
                $state['iterations'] = (int) \substr($arg, 13);
            } elseif (\str_starts_with($arg, '--sizes=')) {
                $sizes = self::parseIntList(\substr($arg, 8));
                $state['sizes'] = $sizes;
                $state['countSizes'] = $sizes;
                $state['unsetSize'] = $sizes[\count($sizes) - 1];
                $state['distributionKeys'] = $sizes[\count($sizes) - 1];
            } elseif (\str_starts_with($arg, '--workers=')) {
                $workers = self::parseIntList(\substr($arg, 10));
                $state['forkIncrConfigs'] = [];
                $state['forkMixedConfigs'] = [];

                foreach ($workers as $w) {
                    $state['forkIncrConfigs'][] = [
                        'workers' => $w,
                        'ops_per_worker' => $state['mode'] === 'quick' ? 100 : 1000,
                    ];
                    $state['forkMixedConfigs'][] = [
                        'workers' => $w,
                        'ops_per_worker' => $state['mode'] === 'quick' ? 500 : 2000,
                    ];
                }
            } elseif (\str_starts_with($arg, '--segment-size=')) {
                $state['segmentSize'] = (int) \substr($arg, 15);
            } elseif (\str_starts_with($arg, '--seed=')) {
                $state['seed'] = (int) \substr($arg, 7);
            } elseif (\str_starts_with($arg, '--compare-stripes=')) {
                $state['compareStripes'] = (int) \substr($arg, 18);
            } elseif (\str_starts_with($arg, '--write-key-space=')) {
                $state['writeKeySpace'] = (int) \substr($arg, 18);
            } elseif (\str_starts_with($arg, '--cases=')) {
                $state['caseFilter'] = \array_map('trim', \explode(',', \substr($arg, 8)));
            } elseif ($arg === '--json') {
                $state['emitJson'] = true;
            } elseif ($arg === '--csv') {
                $state['emitCsv'] = true;
            } elseif ($arg === '--report') {
                $state['emitReport'] = true;
            } elseif ($arg === '--help' || $arg === '-h') {
                self::printUsage();
                exit(0);
            }
        }

        if (\in_array(100000, $state['sizes'], true) && $state['segmentSize'] < 134217728) {
            $state['segmentSize'] = 268435456;
        }

        return new self(...$state);
    }

    /**
     * Preset field bundle for a named mode before argv overrides are applied.
     *
     * @return array{
     *     mode: string,
     *     iterations: int,
     *     warmup: int,
     *     sizes: list<int>,
     *     countSizes: list<int>,
     *     unsetSize: int,
     *     distributionKeys: int,
     *     compoundOps: int,
     *     largeValueOps: int,
     *     forkIncrConfigs: list<array{workers: int, ops_per_worker: int}>,
     *     forkMixedConfigs: list<array{workers: int, ops_per_worker: int}>,
     *     compareStripes: int,
     *     writeKeySpace: int,
     *     segmentSize: int,
     *     capacity: int,
     *     seed: int,
     *     keepSegments: bool,
     *     emitJson: bool,
     *     emitCsv: bool,
     *     emitReport: bool,
     *     caseFilter: list<string>|null,
     * }
     */
    private static function modeDefaults(string $mode): array
    {
        if ($mode === 'quick') {
            return [
                'mode'               => 'quick',
                'iterations'         => 1,
                'warmup'             => 10,
                'sizes'              => [1000],
                'countSizes'         => [1000],
                'unsetSize'          => 1000,
                'distributionKeys'   => 10000,
                'compoundOps'        => 1000,
                'largeValueOps'      => 100,
                'forkIncrConfigs'    => [['workers' => 2, 'ops_per_worker' => 100]],
                'forkMixedConfigs'   => [['workers' => 2, 'ops_per_worker' => 500]],
                'compareStripes'     => 8,
                'writeKeySpace'      => 200_000,
                'segmentSize'        => 134217728,
                'capacity'           => 262144,
                'seed'               => 42,
                'keepSegments'       => false,
                'emitJson'           => true,
                'emitCsv'            => true,
                'emitReport'         => true,
                'caseFilter'         => null,
            ];
        }

        if ($mode === 'full') {
            return [
                'mode'               => 'full',
                'iterations'         => 5,
                'warmup'             => 100,
                'sizes'              => [1000, 10000, 50000, 100000],
                'countSizes'         => [1000, 10000, 50000],
                'unsetSize'          => 50000,
                'distributionKeys'   => 100000,
                'compoundOps'        => 10000,
                'largeValueOps'      => 1000,
                'forkIncrConfigs'    => [
                    ['workers' => 8, 'ops_per_worker' => 5000],
                    ['workers' => 16, 'ops_per_worker' => 5000],
                ],
                'forkMixedConfigs'   => [['workers' => 8, 'ops_per_worker' => 10000]],
                'compareStripes'     => 8,
                'writeKeySpace'      => 200_000,
                'segmentSize'        => 134217728,
                'capacity'           => 262144,
                'seed'               => 42,
                'keepSegments'       => false,
                'emitJson'           => true,
                'emitCsv'            => true,
                'emitReport'         => true,
                'caseFilter'         => null,
            ];
        }

        return [
            'mode'               => 'default',
            'iterations'         => 3,
            'warmup'             => 50,
            'sizes'              => [1000, 10000, 25000],
            'countSizes'         => [1000, 10000],
            'unsetSize'          => 10000,
            'distributionKeys'   => 25000,
            'compoundOps'        => 10000,
            'largeValueOps'      => 500,
            'forkIncrConfigs'    => [['workers' => 4, 'ops_per_worker' => 1000]],
            'forkMixedConfigs'   => [['workers' => 4, 'ops_per_worker' => 2000]],
            'compareStripes'     => 8,
            'writeKeySpace'      => 200_000,
            'segmentSize'        => 134217728,
            'capacity'           => 262144,
            'seed'               => 42,
            'keepSegments'       => false,
            'emitJson'           => true,
            'emitCsv'            => true,
            'emitReport'         => true,
            'caseFilter'         => null,
        ];
    }

    /** @return list<int> */
    public static function parseIntList(string $raw): array
    {
        $parts = \array_filter(\array_map('trim', \explode(',', $raw)), static fn (string $p): bool => $p !== '');

        return \array_map('intval', $parts);
    }

    /** Print CLI usage to stderr and exit is the caller's responsibility. */
    public static function printUsage(): void
    {
        \fwrite(STDERR, "Usage: php benchmarks/run.php [--quick|--full] [options]\n");
        \fwrite(STDERR, "Options: --iterations=N --sizes=1000,10000 --workers=2,4 --segment-size=BYTES\n");
        \fwrite(STDERR, "         --compare-stripes=N --write-key-space=N --cases=id1,id2 --seed=N\n");
        \fwrite(STDERR, "         --keep-segments --json --csv --report\n");
    }
}

/**
 * Scoped PHP error handler for bench cases that expect warnings or notices.
 *
 * Install before a case, restore after; {@see validate()} checks observed vs
 * expected patterns without failing the whole run on unrelated noise.
 *
 * @package Bench
 * @license https://opensource.org/licenses/MIT MIT License
 * @link    https://github.com/johnnyjoy/fast
 */
final class WarningCollector
{
    /** @var list<array{errno: int, message: string, file: string, line: int}> */
    private array $observed = [];
    /** @var list<string> */
    private array $expectedPatterns = [];
    private int $expectedMin = 0;
    private int $expectedMax = 0;
    private mixed $prevHandler = null;

    /**
     * Declare warning/notice patterns expected during the upcoming case body.
     *
     * @param list<string> $patterns Substrings or `/regex/` patterns.
     */
    public function expect(array $patterns, int $min = 0, int $max = 0): void
    {
        $this->expectedPatterns = $patterns;
        $this->expectedMin = $min;
        $this->expectedMax = $max > 0 ? $max : $min;
    }

    /** Replace the process error handler and begin collecting warnings. */
    public function install(): void
    {
        $this->observed = [];
        $this->prevHandler = \set_error_handler($this->handle(...));
    }

    /** Restore the previous error handler installed by {@see install()}. */
    public function restore(): void
    {
        if ($this->prevHandler !== null) {
            \restore_error_handler();
            $this->prevHandler = null;
        }
    }

    /**
     * Bench error handler — records warnings/notices and suppresses their default output.
     */
    public function handle(int $errno, string $message, string $file = '', int $line = 0): bool
    {
        if (!\in_array($errno, [E_WARNING, E_NOTICE, E_USER_WARNING, E_USER_NOTICE], true)) {
            return false;
        }

        $this->observed[] = [
            'errno'   => $errno,
            'message' => $message,
            'file'    => $file,
            'line'    => $line,
        ];

        return true;
    }

    /**
     * @return array{passed: bool, observed: list<array<string, mixed>>, unexpected_count: int, detail: string}
     */
    public function validate(): array
    {
        $matchedExpected = 0;
        $unexpected = [];

        foreach ($this->observed as $w) {
            if ($this->matchesExpected($w['message'])) {
                $matchedExpected++;
            } else {
                $unexpected[] = $w;
            }
        }

        $passed = $unexpected === []
            && $matchedExpected >= $this->expectedMin
            && $matchedExpected <= ($this->expectedMax > 0 ? $this->expectedMax : $this->expectedMin);

        $detail = 'observed=' . \count($this->observed)
            . ' expected_match=' . $matchedExpected
            . ' unexpected=' . \count($unexpected);

        return [
            'passed'           => $passed,
            'observed'         => $this->observed,
            'unexpected_count' => \count($unexpected),
            'detail'           => $detail,
        ];
    }

    private function matchesExpected(string $message): bool
    {
        foreach ($this->expectedPatterns as $pattern) {
            if ($pattern === '') {
                continue;
            }

            if (\str_starts_with($pattern, '/')) {
                if (\preg_match($pattern, $message) === 1) {
                    return true;
                }
            } elseif (\str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    public function observed(): array
    {
        return $this->observed;
    }
}

/**
 * Per-case bench context: immutable config and warning scope, mutable segment
 * counters, and shared timing/fork helpers for {@see Cases}.
 *
 * @package Bench
 * @license https://opensource.org/licenses/MIT MIT License
 * @link    https://github.com/johnnyjoy/fast
 */
final class CaseContext
{
    public int $segmentsCreated = 0;
    public int $segmentsDestroyed = 0;
    /** @var list<string> */
    public array $cleanupFailures = [];

    /**
     * @param Config $config Immutable run configuration shared across cases.
     * @param WarningCollector $warnings Scoped collector for this case only.
     */
    public function __construct(
        public readonly Config $config,
        public readonly WarningCollector $warnings,
    ) {
    }

    /** Create a persistent Fast store sized from {@see Config}. */
    public function makeStore(string $caseId): \Fast
    {
        $name = 'fast-bench-' . \getmypid() . '-' . \time() . '-' . $caseId;
        $store = new \Fast([
            'name'       => $name,
            'persistent' => true,
            'capacity'   => $this->config->capacity,
            'size'       => $this->config->segmentSize,
        ]);
        $this->segmentsCreated++;

        return $store;
    }

    /** Close or destroy a case store according to {@see Config::$keepSegments}. */
    public function cleanupStore(\Fast $store): void
    {
        if ($this->config->keepSegments) {
            $store->close();

            return;
        }

        try {
            $store->destroy();
            $this->segmentsDestroyed++;
        } catch (\Throwable $e) {
            $this->cleanupFailures[] = $e->getMessage();
        }
    }

    /** Whether fork-based bench cases can run on this PHP build. */
    public static function hasPcntl(): bool
    {
        return \function_exists('pcntl_fork');
    }

    /** @return list<int> */
    public static function sampleIndices(int $n, int $maxSamples = 32): array
    {
        if ($n <= 0) {
            return [];
        }

        $indices = [0];

        if ($n > 1) {
            $indices[] = $n - 1;
            $indices[] = (int) ($n / 4);
            $indices[] = (int) ($n / 2);
            $indices[] = (int) ((3 * $n) / 4);
        }

        $indices = \array_values(\array_unique($indices));
        $need = \min($maxSamples, $n) - \count($indices);

        for ($i = 0; $i < $need; $i++) {
            $indices[] = ($i * 9973) % $n;
        }

        $indices = \array_values(\array_unique($indices));
        \sort($indices);

        return \array_slice($indices, 0, $maxSamples);
    }

    /** Run discarded warmup iterations before timed sampling. */
    public function warmup(int $ops, callable $fn): void
    {
        for ($i = 0; $i < $ops; $i++) {
            $fn($i);
        }
    }

    /**
     * Time {@see $n} invocations of {@see $fn}, returning latency stats and throughput.
     *
     * @return array<string, mixed>
     */
    public function timeOperation(string $operation, int $n, callable $fn, bool $withWarmup = true): array
    {
        $memBefore = \memory_get_usage(true);
        $peakBefore = \memory_get_peak_usage(true);

        if ($n <= 10_000) {
            $samples = [];

            if ($withWarmup) {
                $this->warmup($this->config->warmup, $fn);
            }

            for ($i = 0; $i < $n; $i++) {
                $t0 = \hrtime(true);
                $fn($i);
                $samples[] = \hrtime(true) - $t0;
            }

            $total = \array_sum($samples) / 1_000_000_000;
            $latency = Stats::fromSamples($samples);
            $method = 'per_op_samples';
        } else {
            if ($withWarmup) {
                $this->warmup($this->config->warmup, $fn);
            }
            $t0 = \hrtime(true);

            for ($i = 0; $i < $n; $i++) {
                $fn($i);
            }

            $total = (\hrtime(true) - $t0) / 1_000_000_000;
            $latency = Stats::fromBatch($n, $total);
            $method = 'batch_derived';
        }

        $memAfter = \memory_get_usage(true);
        $peakAfter = \memory_get_peak_usage(true);

        return [
            'operation'      => $operation,
            'n'              => $n,
            'latency_unit'   => 'per_op_ns',
            'latency_method' => $method,
            'operations'     => $n,
            'total_seconds'  => $total,
            'ops_per_sec'    => $total > 0 ? (int) ($n / $total) : 0,
            'latency_ns'     => $latency,
            'memory_bytes'   => [
                'before' => $memBefore,
                'after'  => $memAfter,
                'peak'   => \max($peakBefore, $peakAfter),
            ],
        ];
    }

    /**
     * File-based fork barrier: each worker creates a ready marker, then spins until all exist.
     */
    public static function forkBarrierWait(string $dir, int $workers, int $workerId, int $timeoutSec = 30): bool
    {
        $ready = $dir . '/' . $workerId . '.ready';
        \file_put_contents($ready, '1');
        $deadline = \time() + $timeoutSec;

        while (\time() < $deadline) {
            $all = true;

            for ($w = 0; $w < $workers; $w++) {
                if (!\is_file($dir . '/' . $w . '.ready')) {
                    $all = false;
                    break;
                }
            }

            if ($all) {
                return true;
            }

            \usleep(1000);
        }

        return false;
    }

    /**
     * Block child until parent releases all workers (pipe barrier).
     *
     * @param list<resource> $readEnds One read stream per worker (child holds its own)
     */
    public static function forkPipeWait($readEnd): bool
    {
        if (!\is_resource($readEnd)) {
            return false;
        }

        $buf = '';

        return \fread($readEnd, 1) === 'G';
    }

    /**
     * @return array{0: list<resource>, 1: list<resource>} read ends per worker, write ends per worker
     */
    public static function forkPipeCreate(int $workers): array
    {
        $reads = [];
        $writes = [];

        for ($w = 0; $w < $workers; $w++) {
            $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);

            if ($pair === false) {
                throw new \RuntimeException('stream_socket_pair failed');
            }

            $reads[] = $pair[0];
            $writes[] = $pair[1];
        }

        return [$reads, $writes];
    }

    /**
     * @param list<resource> $writeEnds
     */
    public static function forkPipeGo(array $writeEnds): void
    {
        foreach ($writeEnds as $w) {
            if (\is_resource($w)) {
                \fwrite($w, 'G');
                \fclose($w);
            }
        }
    }

    /**
     * @param list<resource> $streams
     */
    public static function closeStreams(array $streams): void
    {
        foreach ($streams as $s) {
            if (\is_resource($s)) {
                @\fclose($s);
            }
        }
    }

    /**
     * Run fork child workload; always terminates the process (never returns to parent runner).
     */
    public static function forkChildRun(callable $fn): never
    {
        try {
            $fn();
            exit(0);
        } catch (\Throwable $e) {
            \fwrite(\STDERR, 'bench fork child: ' . $e->getMessage() . "\n");
            exit(1);
        }
    }

    /** Allocate a per-case temp directory for {@see forkBarrierWait()}. */
    public static function forkBarrierDir(string $caseId): string
    {
        $dir = \sys_get_temp_dir() . '/fast-bench-barrier-' . \getmypid() . '-' . $caseId;

        if (\is_dir($dir)) {
            foreach (\glob($dir . '/*.ready') ?: [] as $f) {
                @\unlink($f);
            }
        } else {
            @\mkdir($dir, 0700, true);
        }

        return $dir;
    }

    /** Remove a fork barrier directory created by {@see forkBarrierDir()}. */
    public static function removeBarrierDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        foreach (\glob($dir . '/*') ?: [] as $f) {
            @\unlink($f);
        }

        @\rmdir($dir);
    }
}

/**
 * Benchmark orchestrator: parse CLI config, run {@see Cases}, write
 * JSON/CSV/report artifacts, and print the perf scorecard vs {@see Track}.
 *
 * @package Bench
 * @license https://opensource.org/licenses/MIT MIT License
 * @link    https://github.com/johnnyjoy/fast
 */
final class Runner
{
    /** @var list<array<string, mixed>> */
    private array $cases = [];
    private float $startTime = 0.0;
    private string $timestampSlug = '';
    private string $command = '';

    /**
     * @param list<string> $argv CLI argv including script name at index 0.
     */
    public function __construct(private readonly array $argv)
    {
        $this->command = \implode(' ', $this->argv);
        $this->timestampSlug = \gmdate('Ymd-His');
    }

    /**
     * Execute the full benchmark suite and write artifacts.
     *
     * @return int Process exit code (0 success, 1 bench failure, 2 missing extensions).
     */
    public function run(): int
    {
        if (!\function_exists('shm_attach') || !\function_exists('sem_get')) {
            \fwrite(STDERR, "sysvshm and sysvsem extensions required\n");

            return 2;
        }

        $config = Config::fromArgv($this->argv);
        $this->startTime = \microtime(true);
        $registry = Cases::registry();
        $ctx = new CaseContext($config, new WarningCollector());

        foreach ($registry as $id => $meta) {
            if ($config->caseFilter !== null && !\in_array($id, $config->caseFilter, true)) {
                continue;
            }

            $warnings = new WarningCollector();
            $caseCtx = new CaseContext($config, $warnings);
            $result = Cases::run($id, $meta, $caseCtx);

            $this->cases[] = $result;
            $ctx->segmentsCreated += $caseCtx->segmentsCreated;
            $ctx->segmentsDestroyed += $caseCtx->segmentsDestroyed;
            $ctx->cleanupFailures = \array_merge($ctx->cleanupFailures, $caseCtx->cleanupFailures);
        }

        $summary = $this->buildSummary($config);
        $payload = $this->buildPayload($config, $ctx, $summary);
        $paths = $this->writeArtifacts($config, $payload);

        if ($config->emitReport) {
            $this->writeReport($config, $payload, $paths);
        }

        $this->printTrackScorecard($payload);
        $this->printFlatStripedComparison($payload);
        $this->printSummary($summary, $paths);

        return (int) ($summary['exit_code'] ?? 1);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function printTrackScorecard(array $payload): void
    {
        $case = $this->matrixCase($payload);

        if ($case === null || ($case['matrix_runs'] ?? []) === []) {
            return;
        }

        $aggregated = $this->aggregateMatrixByN($case['matrix_runs']);
        $currentBySize = [];

        foreach ($aggregated as $row) {
            $currentBySize[(int) $row['n']] = Track::metricsFromMatrixRow($row);
        }

        $baseline = Track::loadBaseline();
        $comparison = Track::compare($baseline, $currentBySize);
        \fwrite(\STDERR, Track::formatScorecard($comparison, $baseline));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSummary(Config $config): array
    {
        $passed = 0;
        $failed = 0;
        $skipped = 0;
        $warningsTotal = 0;
        $warningsExpected = 0;
        $warningsUnexpected = 0;

        foreach ($this->cases as $case) {
            if ($case['skipped'] ?? false) {
                $skipped++;
                continue;
            }

            if (($case['result'] ?? '') === 'pass') {
                $passed++;
            } else {
                $failed++;
            }

            $warningsTotal += \count($case['warnings']['observed'] ?? []);
            $warningsExpected += (int) ($case['warnings']['expected_count_min'] ?? 0);
            $warningsUnexpected += (int) ($case['warnings']['unexpected_count'] ?? 0);
        }

        return [
            'cases_total'          => \count($this->cases),
            'cases_passed'         => $passed,
            'cases_failed'         => $failed,
            'cases_skipped'        => $skipped,
            'warnings_total'       => $warningsTotal,
            'warnings_expected'    => $warningsExpected,
            'warnings_unexpected'  => $warningsUnexpected,
            'duration_seconds'     => \round(\microtime(true) - $this->startTime, 3),
            'exit_code'            => $failed > 0 ? 1 : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(Config $config, CaseContext $ctx, array $summary): array
    {
        $base = \dirname(__DIR__);

        return [
            'schema_version' => 1,
            'meta'             => $this->environmentMeta($config),
            'summary'          => $summary,
            'cases'            => $this->cases,
            'segments'         => [
                'created'           => $ctx->segmentsCreated,
                'destroyed'         => $ctx->segmentsDestroyed,
                'kept'              => $config->keepSegments ? $ctx->segmentsCreated : 0,
                'cleanup_failures'  => $ctx->cleanupFailures,
            ],
            'report_paths'     => [
                'markdown'        => $base . '/reports/' . $this->timestampSlug . '-report.md',
                'results_json'    => $base . '/results/' . $this->timestampSlug . '-results.json',
                'perf_csv'        => $base . '/results/' . $this->timestampSlug . '-perf.csv',
                'matrix_csv'      => $base . '/results/' . $this->timestampSlug . '-matrix.csv',
                'correctness_csv' => $base . '/results/' . $this->timestampSlug . '-correctness.csv',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function environmentMeta(Config $config): array
    {
        $cpu = 'unknown';
        $cpuinfo = @\file_get_contents('/proc/cpuinfo');

        if ($cpuinfo !== false && \preg_match('/model name\s*:\s*(.+)/', $cpuinfo, $m)) {
            $cpu = \trim($m[1]);
        }

        return [
            'task_id'              => 'fast-bench-suite-v1',
            'timestamp_utc'        => \gmdate('c'),
            'timestamp_slug'       => $this->timestampSlug,
            'mode'                 => $config->mode,
            'command'              => $this->command,
            'php_version'          => \PHP_VERSION,
            'php_sapi'             => \PHP_SAPI,
            'os'                   => \php_uname('s') . ' ' . \php_uname('r'),
            'kernel'               => \php_uname('r'),
            'cpu_model'            => $cpu,
            'memory_limit'         => \ini_get('memory_limit') ?: 'unknown',
            'segment_size_bytes'   => $config->segmentSize,
            'seed'                 => $config->seed,
            'iterations_per_case'  => $config->iterations,
            'warmup_ops'           => $config->warmup,
            'sizes'                => $config->sizes,
            'fork_configs'         => $config->forkIncrConfigs,
            'extensions'           => [
                'sysvshm' => \extension_loaded('sysvshm'),
                'sysvsem' => \extension_loaded('sysvsem'),
                'pcntl'   => \extension_loaded('pcntl'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, string>
     */
    private function writeArtifacts(Config $config, array $payload): array
    {
        $base = \dirname(__DIR__);
        $jsonPath = $base . '/results/' . $this->timestampSlug . '-results.json';
        $perfPath = $base . '/results/' . $this->timestampSlug . '-perf.csv';
        $matrixPath = $base . '/results/' . $this->timestampSlug . '-matrix.csv';
        $corrPath = $base . '/results/' . $this->timestampSlug . '-correctness.csv';

        if ($config->emitJson) {
            \file_put_contents(
                $jsonPath,
                \json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            );
        }

        if ($config->emitCsv) {
            \file_put_contents($perfPath, $this->buildPerfCsv($payload));
            \file_put_contents($matrixPath, $this->buildMatrixCsv($payload));
            \file_put_contents($corrPath, $this->buildCorrectnessCsv($payload));
        }

        return [
            'json'        => $jsonPath,
            'perf_csv'    => $perfPath,
            'matrix_csv'  => $matrixPath,
            'correctness' => $corrPath,
            'report'      => $base . '/reports/' . $this->timestampSlug . '-report.md',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildPerfCsv(array $payload): string
    {
        $fields = [
            'case_id', 'case_name', 'category', 'run_index', 'n', 'operation',
            'latency_unit', 'latency_method', 'operations', 'total_seconds', 'ops_per_sec',
            'min_ns', 'mean_ns', 'median_ns', 'p90_ns', 'p95_ns', 'p99_ns', 'max_ns', 'stdev_ns',
            'memory_before', 'memory_after', 'memory_peak', 'result',
        ];
        $rows = [];

        foreach ($payload['cases'] as $case) {
            if ($case['skipped'] ?? false) {
                continue;
            }

            foreach ($case['runs'] ?? [] as $run) {
                $lat = $run['latency_ns'] ?? [];
                $mem = $run['memory_bytes'] ?? [];
                $rows[] = [
                    'case_id'         => $case['id'],
                    'case_name'       => $case['name'],
                    'category'        => $case['category'],
                    'run_index'       => $run['run_index'] ?? 0,
                    'n'               => $run['n'] ?? 0,
                    'operation'       => $run['operation'] ?? '',
                    'latency_unit'    => $run['latency_unit'] ?? '',
                    'latency_method'  => $run['latency_method'] ?? '',
                    'operations'      => $run['operations'] ?? 0,
                    'total_seconds'   => $run['total_seconds'] ?? 0,
                    'ops_per_sec'     => $run['ops_per_sec'] ?? 0,
                    'min_ns'          => $lat['min'] ?? '',
                    'mean_ns'         => $lat['mean'] ?? '',
                    'median_ns'       => $lat['median'] ?? '',
                    'p90_ns'          => ($run['latency_method'] ?? '') === 'batch_derived' ? '' : ($lat['p90'] ?? ''),
                    'p95_ns'          => ($run['latency_method'] ?? '') === 'batch_derived' ? '' : ($lat['p95'] ?? ''),
                    'p99_ns'          => ($run['latency_method'] ?? '') === 'batch_derived' ? '' : ($lat['p99'] ?? ''),
                    'max_ns'          => $lat['max'] ?? '',
                    'stdev_ns'        => $lat['stdev'] ?? '',
                    'memory_before'   => $mem['before'] ?? 0,
                    'memory_after'    => $mem['after'] ?? 0,
                    'memory_peak'     => $mem['peak'] ?? 0,
                    'result'          => $case['result'] ?? '',
                ];
            }
        }

        return Table::csvLines($fields, $rows);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildCorrectnessCsv(array $payload): string
    {
        $fields = [
            'case_id', 'case_name', 'category', 'result',
            'checks_passed', 'checks_failed', 'checks_total',
            'warnings_expected', 'warnings_observed', 'warnings_unexpected',
            'skipped', 'skipped_reason', 'detail',
        ];
        $rows = [];

        foreach ($payload['cases'] as $case) {
            $checks = $case['correctness']['checks'] ?? [];
            $passed = 0;
            $failed = 0;

            foreach ($checks as $c) {
                if ($c['passed'] ?? false) {
                    $passed++;
                } else {
                    $failed++;
                }
            }

            $rows[] = [
                'case_id'             => $case['id'],
                'case_name'           => $case['name'],
                'category'            => $case['category'],
                'result'              => $case['result'] ?? '',
                'checks_passed'       => $passed,
                'checks_failed'       => $failed,
                'checks_total'        => \count($checks),
                'warnings_expected'   => $case['warnings']['expected_count_min'] ?? 0,
                'warnings_observed'   => \count($case['warnings']['observed'] ?? []),
                'warnings_unexpected' => $case['warnings']['unexpected_count'] ?? 0,
                'skipped'             => ($case['skipped'] ?? false) ? 1 : 0,
                'skipped_reason'      => $case['skipped_reason'] ?? '',
                'detail'              => $case['correctness']['detail'] ?? '',
            ];
        }

        return Table::csvLines($fields, $rows);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildMatrixCsv(array $payload): string
    {
        $fields = [
            'run_index', 'n', 'mode',
            'insert_p50_us', 'insert_p95_us', 'insert_p99_us',
            'lookup_warm_p50_us', 'lookup_warm_p95_us', 'lookup_warm_p99_us',
            'update_p50_us', 'update_p95_us', 'update_p99_us',
            'lookup_cold_p95_us', 'index_kb', 'shm_index_us_avg', 'sem_hold_us_avg',
            'valid', 'valid_full',
        ];
        $rows = [];
        $case = $this->matrixCase($payload);

        if ($case === null) {
            return Table::csvLines($fields, []);
        }

        foreach ($case['matrix_runs'] ?? [] as $run) {
            $rows[] = [
                'run_index'           => $run['run_index'] ?? 0,
                'n'                   => $run['n'],
                'mode'                => $run['mode'] ?? 'shard',
                'insert_p50_us'       => $run['insert']['p50_us'] ?? 0,
                'insert_p95_us'       => $run['insert']['p95_us'] ?? 0,
                'insert_p99_us'       => $run['insert']['p99_us'] ?? 0,
                'lookup_warm_p50_us'  => $run['lookup_warm']['p50_us'] ?? 0,
                'lookup_warm_p95_us'  => $run['lookup_warm']['p95_us'] ?? 0,
                'lookup_warm_p99_us'  => $run['lookup_warm']['p99_us'] ?? 0,
                'update_p50_us'       => $run['update']['p50_us'] ?? 0,
                'update_p95_us'       => $run['update']['p95_us'] ?? 0,
                'update_p99_us'       => $run['update']['p99_us'] ?? 0,
                'lookup_cold_p95_us'  => $run['lookup_cold']['p95_us'] ?? 0,
                'index_kb'            => $run['index_kb'] ?? 0,
                'shm_index_us_avg'    => $run['shm_index_us_avg'] ?? 0,
                'sem_hold_us_avg'     => $run['sem_hold_us_avg'] ?? 0,
                'valid'               => ($run['valid'] ?? false) ? 1 : 0,
                'valid_full'          => ($run['valid_full'] ?? false) ? 1 : 0,
            ];
        }

        return Table::csvLines($fields, $rows);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildMatrixMarkdown(array $payload): string
    {
        $case = $this->matrixCase($payload);

        if ($case === null || ($case['matrix_runs'] ?? []) === []) {
            return '_No matrix data (fast_op_matrix missing or failed)._';
        }

        $aggregated = $this->aggregateMatrixByN($case['matrix_runs']);
        $rows = [];

        foreach ($aggregated as $row) {
            $rows[] = [
                Table::formatNumber((float) $row['n']),
                Table::formatNumber((float) $row['insert_p95_us'], 1),
                Table::formatNumber((float) $row['lookup_warm_p95_us'], 1),
                Table::formatNumber((float) $row['update_p95_us'], 1),
                Table::formatNumber((float) $row['shm_index_us_avg'], 1),
                Table::formatNumber((float) $row['sem_hold_us_avg'], 1),
                Table::formatNumber((float) $row['index_kb'], 1),
            ];
        }

        $ref = $this->loadReferenceMatrix();
        $note = '';

        if ($ref !== []) {
            $note = "\n\n_Legacy reference only (2026-06-19 matrix.json — not the improvement target):_\n";
            $refRows = [];

            foreach ($aggregated as $row) {
                $n = (int) $row['n'];
                $refRow = $ref[$n] ?? null;

                if ($refRow === null) {
                    continue;
                }

                $refRows[] = [
                    (string) $n,
                    Table::formatNumber((float) $refRow['insert_p95_us'], 1),
                    Table::formatNumber((float) $refRow['lookup_warm_p95_us'], 1),
                    Table::formatNumber((float) $refRow['update_p95_us'], 1),
                ];
            }

            if ($refRows !== []) {
                $note .= Table::markdown(
                    ['N', 'Ref insert p95 µs', 'Ref lookup warm p95 µs', 'Ref update p95 µs'],
                    $refRows,
                );
            }
        }

        $baseline = Track::loadBaseline();
        $currentBySize = [];

        foreach ($aggregated as $row) {
            $currentBySize[(int) $row['n']] = Track::metricsFromMatrixRow($row);
        }

        $comparison = Track::compare($baseline, $currentBySize);
        $deltaSection = Track::formatMarkdown($comparison, $baseline);

        return Table::markdown(
            ['N', 'Insert p95 µs', 'Lookup warm p95 µs', 'Update p95 µs', 'Index µs/op', 'Sem hold µs/op', 'Index KB'],
            $rows,
        ) . "\n\n" . $deltaSection . $note;
    }

    /**
     * @param list<array<string, mixed>> $matrixRuns
     *
     * @return list<array<string, float|int>>
     */
    private function aggregateMatrixByN(array $matrixRuns): array
    {
        /** @var array<int, list<array<string, mixed>>> $byN */
        $byN = [];

        foreach ($matrixRuns as $run) {
            $byN[(int) $run['n']][] = $run;
        }

        \ksort($byN);
        $out = [];

        foreach ($byN as $n => $runs) {
            $pick = static function (string $op, string $field) use ($runs): float {
                $vals = [];

                foreach ($runs as $run) {
                    $vals[] = (float) ($run[$op][$field] ?? 0);
                }

                return Stats::medianOf($vals);
            };

            $out[] = [
                'n'                  => $n,
                'insert_p95_us'      => $pick('insert', 'p95_us'),
                'lookup_warm_p95_us' => $pick('lookup_warm', 'p95_us'),
                'update_p95_us'      => $pick('update', 'p95_us'),
                'shm_index_us_avg'   => Stats::medianOf(\array_map(
                    static fn (array $r): float => (float) ($r['shm_index_us_avg'] ?? 0),
                    $runs,
                )),
                'sem_hold_us_avg'    => Stats::medianOf(\array_map(
                    static fn (array $r): float => (float) ($r['sem_hold_us_avg'] ?? 0),
                    $runs,
                )),
                'index_kb'           => Stats::medianOf(\array_map(
                    static fn (array $r): float => (float) ($r['index_kb'] ?? 0),
                    $runs,
                )),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function matrixCase(array $payload): ?array
    {
        foreach ($payload['cases'] as $case) {
            if (($case['id'] ?? '') === 'fast_op_matrix') {
                return $case;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{insert_p95_us: float, lookup_warm_p95_us: float, update_p95_us: float}>
     */
    private function loadReferenceMatrix(): array
    {
        $path = \dirname(__DIR__, 2) . '/tests/results/matrix.json';

        if (!\is_readable($path)) {
            return [];
        }

        /** @var array<string, mixed>|null $data */
        $data = \json_decode((string) \file_get_contents($path), true);
        $out = [];

        if (!\is_array($data)) {
            return [];
        }

        foreach ($data['runs'] ?? [] as $run) {
            if (($run['mode'] ?? '') !== 'shard') {
                continue;
            }

            $n = (int) ($run['n'] ?? 0);

            if ($n <= 0) {
                continue;
            }

            $out[$n] = [
                'insert_p95_us'      => (float) ($run['insert']['p95_us'] ?? 0),
                'lookup_warm_p95_us' => (float) ($run['lookup_warm']['p95_us'] ?? 0),
                'update_p95_us'      => (float) ($run['update']['p95_us'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $paths
     */
    private function writeReport(Config $config, array $payload, array $paths): void
    {
        $md = [];
        $md[] = '# Fast Benchmark Report';
        $md[] = '';
        $md[] = '## Executive Summary';
        $md[] = '';
        $s = $payload['summary'];
        $md[] = \sprintf(
            'Mode **%s**: %d/%d cases passed, %d skipped, %d failed in %.1fs.',
            $config->mode,
            $s['cases_passed'],
            $s['cases_total'],
            $s['cases_skipped'],
            $s['cases_failed'],
            $s['duration_seconds'],
        );
        $md[] = '';
        $md[] = 'Primary metrics: **per-operation latency in microseconds (p95)** on production `\\Fast` (32-shard index).';
        $md[] = 'Improvement target: **`benchmarks/history/baseline.json`** — run `php benchmarks/track.php` daily.';
        $md[] = '';
        $md[] = '## Share Operation Matrix (primary)';
        $md[] = '';
        $md[] = $this->buildMatrixMarkdown($payload);
        $md[] = '';
        $md[] = '## Correctness Summary';
        $md[] = '';
        $corrRows = [];

        foreach ($payload['cases'] as $case) {
            $corrRows[] = [
                $case['id'],
                $case['result'] ?? '',
                ($case['skipped'] ?? false) ? ($case['skipped_reason'] ?? 'skipped') : '',
            ];
        }

        $md[] = Table::markdown(['Area', 'Result', 'Notes'], $corrRows);
        $md[] = '';
        $md[] = '## Environment';
        $md[] = '';
        $m = $payload['meta'];
        $md[] = Table::markdown(
            ['Key', 'Value'],
            [
                ['PHP', (string) $m['php_version']],
                ['OS', (string) $m['os']],
                ['CPU', (string) $m['cpu_model']],
                ['Segment size', (string) $m['segment_size_bytes']],
                ['Command', (string) $m['command']],
            ],
        );
        $md[] = '';
        $md[] = '## Supplementary Cases (fork, compound, correctness)';
        $md[] = '';
        $perfRows = [];

        foreach ($payload['cases'] as $case) {
            if (($case['skipped'] ?? false) || ($case['id'] ?? '') === 'fast_op_matrix') {
                continue;
            }

            if (!($case['perf_primary'] ?? false) && ($case['category'] ?? '') !== 'fork') {
                continue;
            }

            $agg = $case['aggregate'] ?? [];
            $p95Us = ((float) ($agg['median_p95_ns'] ?? 0)) / 1_000;
            $perfRows[] = [
                $case['id'],
                Table::formatNumber($p95Us, 1) . ' µs',
                Table::formatNumber((float) ($agg['median_ops_per_sec'] ?? 0)),
                $case['result'] ?? '',
            ];
        }

        $md[] = Table::markdown(
            ['Case', 'Median p95', 'Median ops/sec', 'Result'],
            $perfRows,
        );
        $md[] = '';
        $md[] = '## Interpretation';
        $md[] = '';
        $md[] = '- **Insert p95** — new key + index registration (dominant cost at scale).';
        $md[] = '- **Lookup warm p95** — cached shard decode; cold path in `*-matrix.csv`.';
        $md[] = '- **Update p95** — existing key overwrite (should stay flat as N grows).';
        $md[] = '- **shm_index_us_avg** — estimated index overhead per insert (insert avg − update avg).';
        $md[] = '- **sem_hold_us_avg** — lock hold time per op (insert + lookups + updates pass).';
        $md[] = '- Direct SysV slots (`baseline_direct_shm`) are optional reference only — no name index.';
        $md[] = '- `count()` is **O(1)** via maintained COUNT_KEY slot.';
        $md[] = '- Fork benchmarks require `pcntl`; skipped explicitly when unavailable.';
        $md[] = '- Correctness failures invalidate performance numbers for that case.';
        $md[] = '';
        $md[] = '## Reproducibility';
        $md[] = '';
        $md[] = '```bash';
        $md[] = $this->command;
        $md[] = '```';
        $md[] = '';
        $md[] = '## Raw Result Files';
        $md[] = '';
        $md[] = '- JSON: `' . $paths['json'] . '`';
        $md[] = '- **Matrix CSV:** `' . $paths['matrix_csv'] . '`';
        $md[] = '- Perf CSV: `' . $paths['perf_csv'] . '`';
        $md[] = '- Correctness CSV: `' . $paths['correctness'] . '`';

        \file_put_contents($paths['report'], \implode("\n", $md) . "\n");
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function printFlatStripedComparison(array $payload): void
    {
        foreach ($payload['cases'] ?? [] as $case) {
            if (($case['id'] ?? '') !== 'fork_flat_vs_striped' || ($case['comparison'] ?? []) === []) {
                continue;
            }

            \fwrite(STDERR, "\nFlat vs Striped — same workloads (see: php benchmarks/compare-engines.php)\n");
            \fwrite(STDERR, \sprintf("%-32s %7s %14s %14s %10s\n", 'scenario', 'workers', 'flat writes/s', 'striped writes/s', 'ratio'));

            foreach ($case['comparison'] as $row) {
                $label = (string) ($row['workload'] ?? '');
                \fwrite(STDERR, \sprintf(
                    "%-32s %7d %14s %14s %9.2fx\n",
                    $label,
                    (int) ($row['workers'] ?? 0),
                    \number_format((float) ($row['flat_ops_per_sec'] ?? 0)),
                    \number_format((float) ($row['striped_ops_per_sec'] ?? 0)),
                    (float) ($row['speedup'] ?? 0),
                ));
            }
        }
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, string> $paths
     */
    private function printSummary(array $summary, array $paths): void
    {
        \fwrite(
            STDERR,
            \sprintf(
                "Fast bench: passed=%d failed=%d skipped=%d exit=%d\nJSON: %s\nMatrix CSV: %s\nPerf CSV: %s\nCorrectness CSV: %s\nReport: %s\nTrack baseline: %s\n",
                $summary['cases_passed'],
                $summary['cases_failed'],
                $summary['cases_skipped'],
                $summary['exit_code'],
                $paths['json'],
                $paths['matrix_csv'],
                $paths['perf_csv'],
                $paths['correctness'],
                $paths['report'],
                Track::baselinePath(),
            ),
        );
    }
}
