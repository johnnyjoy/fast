<?php declare(strict_types = 1);

/**
 * Head-to-head Flat vs Striped measurements for the same workloads.
 *
 * Used by benchmarks/compare-engines.php (visitor-facing) and
 * Cases::forkFlatVsStriped (harness integration).
 *
 * @package Bench
 */
namespace Bench;

/**
 * Runs identical tasks on Flat (stripes=1) and Striped (stripes=N) and returns
 * structured numbers for tables and docs.
 */
final class EngineCompare
{
    public const int DEFAULT_CAPACITY = 262144;
    public const int DEFAULT_SIZE = 134217728;
    public const int DEFAULT_KEY_SPACE = 200_000;

    /**
     * @return array{
     *     elapsed: float,
     *     ops_per_sec: float,
     *     workers_ok: bool,
     *     engine: string,
     *     stripes: int,
     *     scenario: string,
     *     workers: int,
     *     operations: int,
     * }
     */
    public static function multiWriterSpread(
        int $workers,
        int $opsPerWorker,
        int $stripes,
        int $keySpace = self::DEFAULT_KEY_SPACE,
        int $seed = 42,
        int $capacity = self::DEFAULT_CAPACITY,
        int $size = self::DEFAULT_SIZE,
    ): array {
        return self::runFork(
            'multi_writer_spread',
            $workers,
            $opsPerWorker,
            $stripes,
            $capacity,
            $size,
            $seed,
            static function (\Fast $store, int $w, int $ops, int $keySpace, int $seed): void {
                \mt_srand($seed + $w);

                for ($m = 0; $m < $ops; $m++) {
                    $store['k:' . \mt_rand(0, \max(0, $keySpace - 1))] = $m;
                }
            },
            $keySpace,
        );
    }

    /**
     * @return array{
     *     elapsed: float,
     *     ops_per_sec: float,
     *     workers_ok: bool,
     *     engine: string,
     *     stripes: int,
     *     scenario: string,
     *     workers: int,
     *     operations: int,
     *     counter: int|null,
     * }
     */
    public static function multiWriterHotKey(
        int $workers,
        int $opsPerWorker,
        int $stripes,
        int $seed = 42,
        int $capacity = self::DEFAULT_CAPACITY,
        int $size = self::DEFAULT_SIZE,
    ): array {
        $result = self::runFork(
            'multi_writer_hot_key',
            $workers,
            $opsPerWorker,
            $stripes,
            $capacity,
            $size,
            $seed,
            static function (\Fast $store, int $w, int $ops, int $_keySpace, int $_seed): void {
                for ($m = 0; $m < $ops; $m++) {
                    $store['counter']++;
                }
            },
            1,
            true,
        );
        $result['counter'] = $result['counter'] ?? null;

        return $result;
    }

    /**
     * @return array{
     *     elapsed: float,
     *     ops_per_sec: float,
     *     engine: string,
     *     stripes: int,
     *     scenario: string,
     *     workers: int,
     *     operations: int,
     * }
     */
    public static function singleWriterSpread(
        int $totalOps,
        int $stripes,
        int $keySpace = self::DEFAULT_KEY_SPACE,
        int $seed = 42,
        int $capacity = self::DEFAULT_CAPACITY,
        int $size = self::DEFAULT_SIZE,
    ): array {
        $name = 'fast-compare-sws-' . $stripes . 's-' . \getmypid();
        $cfg = self::storeConfig($name, $stripes, $capacity, $size);
        $store = new \Fast($cfg);
        \mt_srand($seed);
        $t0 = \hrtime(true);

        for ($m = 0; $m < $totalOps; $m++) {
            $store['k:' . \mt_rand(0, \max(0, $keySpace - 1))] = $m;
        }

        $elapsed = (\hrtime(true) - $t0) / 1_000_000_000;

        try {
            $store->destroy();
        } catch (\Throwable) {
            try {
                $store->close();
            } catch (\Throwable) {
            }
        }

        return [
            'elapsed'     => $elapsed,
            'ops_per_sec' => $elapsed > 0 ? $totalOps / $elapsed : 0.0,
            'engine'      => $stripes > 1 ? 'striped' : 'flat',
            'stripes'     => $stripes,
            'scenario'    => 'single_writer_spread',
            'workers'     => 1,
            'operations'  => $totalOps,
        ];
    }

    /**
     * @param callable(\Fast, int, int, int, int): void $workerBody
     *
     * @return array{
     *     elapsed: float,
     *     ops_per_sec: float,
     *     workers_ok: bool,
     *     engine: string,
     *     stripes: int,
     *     scenario: string,
     *     workers: int,
     *     operations: int,
     *     counter?: int,
     * }
     */
    private static function runFork(
        string $scenario,
        int $workers,
        int $opsPerWorker,
        int $stripes,
        int $capacity,
        int $size,
        int $seed,
        callable $workerBody,
        int $keySpace,
        bool $initCounter = false,
    ): array {
        if (!\function_exists('pcntl_fork')) {
            throw new \RuntimeException('pcntl required for multi-writer scenarios');
        }

        if ($stripes > 1 && ($stripes & ($stripes - 1)) !== 0) {
            throw new \InvalidArgumentException('stripes must be a power of two');
        }

        $name = 'fast-compare-' . $scenario . '-' . $stripes . 's-' . \getmypid() . '-' . $workers;
        $cfg = self::storeConfig($name, $stripes, $capacity, $size);
        $parent = new \Fast($cfg);

        if ($initCounter) {
            $parent['counter'] = 0;
        }

        [$readEnds, $writeEnds] = CaseContext::forkPipeCreate($workers);
        $t0 = \hrtime(true);
        $pids = [];
        $workersOk = true;

        try {
            for ($w = 0; $w < $workers; $w++) {
                $pid = \pcntl_fork();

                if ($pid === -1) {
                    throw new \RuntimeException('fork failed');
                }

                if ($pid === 0) {
                    CaseContext::forkChildRun(static function () use (
                        $writeEnds,
                        $readEnds,
                        $w,
                        $cfg,
                        $workerBody,
                        $opsPerWorker,
                        $keySpace,
                        $seed,
                    ): void {
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
                        $child = new \Fast($cfg);
                        $workerBody($child, $w, $opsPerWorker, $keySpace, $seed);
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
                    $workersOk = false;
                }
            }
        } finally {
            CaseContext::closeStreams($writeEnds);
            CaseContext::closeStreams($readEnds);
        }

        $elapsed = (\hrtime(true) - $t0) / 1_000_000_000;
        $totalOps = $workers * $opsPerWorker;
        $out = [
            'elapsed'     => $elapsed,
            'ops_per_sec' => $elapsed > 0 ? $totalOps / $elapsed : 0.0,
            'workers_ok'  => $workersOk,
            'engine'      => $stripes > 1 ? 'striped' : 'flat',
            'stripes'     => $stripes,
            'scenario'    => $scenario,
            'workers'     => $workers,
            'operations'  => $totalOps,
        ];

        if ($initCounter) {
            $out['counter'] = (int) $parent['counter'];
        }

        try {
            $parent->destroy();
        } catch (\Throwable) {
            try {
                $parent->close();
            } catch (\Throwable) {
            }
        }

        return $out;
    }

    /**
     * @return array{name: string, persistent: bool, capacity: int, size: int, stripes?: int}
     */
    private static function storeConfig(string $name, int $stripes, int $capacity, int $size): array
    {
        $cfg = [
            'name'       => $name,
            'persistent' => true,
            'capacity'   => $capacity,
            'size'       => $size,
        ];

        if ($stripes > 1) {
            $cfg['stripes'] = $stripes;
        }

        return $cfg;
    }

    /**
     * Run the full comparison matrix and return rows for printing or docs.
     *
     * @param list<int> $workerCounts
     *
     * @return array{
     *     meta: array<string, string|int>,
     *     scenarios: list<array<string, mixed>>,
     * }
     */
    public static function runMatrix(
        array $workerCounts,
        int $stripes,
        int $opsPerWorker,
        int $keySpace = self::DEFAULT_KEY_SPACE,
        int $seed = 42,
    ): array {
        $scenarios = [];

        foreach ($workerCounts as $workers) {
            $flat = self::multiWriterSpread($workers, $opsPerWorker, 1, $keySpace, $seed);
            $strp = self::multiWriterSpread($workers, $opsPerWorker, $stripes, $keySpace, $seed);
            $scenarios[] = [
                'id'          => 'multi_writer_spread',
                'title'       => 'Many writers, keys spread across the map',
                'intent'      => 'striped',
                'workers'     => $workers,
                'flat'        => $flat['ops_per_sec'],
                'striped'     => $strp['ops_per_sec'],
                'ratio'       => $flat['ops_per_sec'] > 0 ? $strp['ops_per_sec'] / $flat['ops_per_sec'] : 0.0,
                'note'        => 'Striped is built for this shape of load.',
            ];

            $flatHot = self::multiWriterHotKey($workers, $opsPerWorker, 1, $seed);
            $strpHot = self::multiWriterHotKey($workers, $opsPerWorker, $stripes, $seed);
            $scenarios[] = [
                'id'          => 'multi_writer_hot_key',
                'title'       => 'Many writers, one shared counter',
                'intent'      => 'flat',
                'workers'     => $workers,
                'flat'        => $flatHot['ops_per_sec'],
                'striped'     => $strpHot['ops_per_sec'],
                'ratio'       => $flatHot['ops_per_sec'] > 0 ? $strpHot['ops_per_sec'] / $flatHot['ops_per_sec'] : 0.0,
                'note'        => 'Both engines serialize on one key. Striped adds routing cost with no parallelism.',
                'flat_counter'=> $flatHot['counter'] ?? null,
                'striped_counter' => $strpHot['counter'] ?? null,
            ];
        }

        $totalOps = ($workerCounts[0] ?? 4) * $opsPerWorker;
        $flatSingle = self::singleWriterSpread($totalOps, 1, $keySpace, $seed);
        $strpSingle = self::singleWriterSpread($totalOps, $stripes, $keySpace, $seed);
        $scenarios[] = [
            'id'      => 'single_writer_spread',
            'title'   => 'One writer, keys spread (same total work as one worker row above)',
            'intent'  => 'flat',
            'workers' => 1,
            'flat'    => $flatSingle['ops_per_sec'],
            'striped' => $strpSingle['ops_per_sec'],
            'ratio'   => $flatSingle['ops_per_sec'] > 0 ? $strpSingle['ops_per_sec'] / $flatSingle['ops_per_sec'] : 0.0,
            'note'    => 'Default Flat path. Striped pays hash routing with nothing to gain.',
        ];

        return [
            'meta' => [
                'php'           => \PHP_VERSION,
                'os'            => \php_uname('s') . ' ' . \php_uname('r'),
                'cpu'           => (string) (@\trim((string) @\file_get_contents('/proc/cpuinfo')) !== ''
                    ? (string) @\shell_exec("grep -m1 'model name' /proc/cpuinfo | cut -d: -f2")
                    : 'unknown'),
                'stripes'       => $stripes,
                'ops_per_worker'=> $opsPerWorker,
                'key_space'     => $keySpace,
                'seed'          => $seed,
            ],
            'scenarios' => $scenarios,
        ];
    }

    /**
     * @param array{meta: array<string, mixed>, scenarios: list<array<string, mixed>>} $matrix
     */
    public static function formatHuman(array $matrix): string
    {
        $m = $matrix['meta'];
        $lines = [];
        $lines[] = 'Fast: Flat vs Striped on the same workloads';
        $lines[] = \str_repeat('=', 60);
        $lines[] = \sprintf('PHP %s | %s | stripes=%d', $m['php'], $m['os'], $m['stripes']);
        $lines[] = '';
        $lines[] = 'Ratio = striped ÷ flat (above 1.0 means Striped was faster on that task).';
        $lines[] = '';
        $lines[] = \sprintf('%-28s %7s %14s %14s %8s', 'Scenario', 'workers', 'Flat writes/s', 'Striped writes/s', 'ratio');
        $lines[] = \str_repeat('-', 75);

        foreach ($matrix['scenarios'] as $row) {
            $label = $row['id'];

            if ($row['id'] === 'multi_writer_spread') {
                $label = 'MP spread keys (Striped job)';
            } elseif ($row['id'] === 'multi_writer_hot_key') {
                $label = 'MP hot counter (Flat job)';
            } elseif ($row['id'] === 'single_writer_spread') {
                $label = 'Single writer spread';
            }

            $lines[] = \sprintf(
                '%-28s %7d %14s %14s %7.2fx',
                $label,
                (int) $row['workers'],
                \number_format((float) $row['flat'], 0, '.', ','),
                \number_format((float) $row['striped'], 0, '.', ','),
                (float) $row['ratio'],
            );
        }

        $lines[] = '';
        $lines[] = 'How to read this';
        $lines[] = '';
        $lines[] = '  • MP spread keys: many processes writing different keys. If Striped wins here,';
        $lines[] = '    your workload looks like the case Striped was added for.';
        $lines[] = '  • MP hot counter: every writer hits the same key. Striped should not beat Flat;';
        $lines[] = '    often it is slightly slower. Do not enable stripes for this pattern.';
        $lines[] = '  • Single writer: Flat is the default. Striped is extra machinery you do not need.';
        $lines[] = '';
        $lines[] = 'Reproduce: php benchmarks/compare-engines.php';
        $lines[] = '';

        return \implode("\n", $lines);
    }
}
