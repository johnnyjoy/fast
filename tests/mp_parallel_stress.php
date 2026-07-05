<?php declare(strict_types = 1);

/**
 * Contract test: Mp Parallel Stress.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

require __DIR__ . '/bootstrap.php';

use \Fast;

if (!function_exists('pcntl_fork')) {
    fwrite(STDERR, "pcntl required\n");
    exit(77);
}

$seconds = (int) (getenv('FAST_MP_STRESS_SECONDS') ?: 180);
$workers = (int) (getenv('FAST_MP_STRESS_WORKERS') ?: 4);
// Opt-in striping (default 1 = strict-order monolith; this harness is unchanged
// unless FAST_MP_STRIPES is set). With stripes > 1 it exercises the concurrent
// write path of the Striped engine for CORRECTNESS under real multi-process load.
$stripes = (int) (getenv('FAST_MP_STRIPES') ?: 1);
// The workers touch up to ~8192 base keys plus four never-deleted suffix variants
// each (~40k live keys), so the store is created with explicit capacity well above
// that working set. Attaching processes adopt the creator's slot count and size
// from the shared header.
$directorySlots = 65536;
$sharedSize = 134217728;
$name = 'fast-store-mp-' . getmypid();

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$cfg = static function (string $sharedName, int $slots, int $size, int $stripes): array {
    $c = ['name' => $sharedName, 'capacity' => $slots, 'size' => $size];
    if ($stripes > 1) { $c['stripes'] = $stripes; }
    return $c;
};

$cleanup = static function (string $sharedName, int $slots, int $size, int $stripes) use ($cfg): void {
    try {
        $tmp = new \Fast($cfg($sharedName, $slots, $size, $stripes));
        $tmp->destroy();
    } catch (\Throwable) {
        // best-effort cleanup only
    }
};

$cleanup($name, $directorySlots, $sharedSize, $stripes);

$store = new \Fast($cfg($name, $directorySlots, $sharedSize, $stripes));
$seed = 1;
$store['boot'] = 1;
$store['phase'] = 0;

$pids = [];
$pipes = [];

for ($w = 0; $w < $workers; $w++) {
    $s = $name;
    $pid = pcntl_fork();
    if ($pid === -1) {
        $fail('fork failed');
    }

    if ($pid === 0) {
        $child = new \Fast($cfg($s, $directorySlots, $sharedSize, $stripes));
        $base = 100000 + ($w * 10000);
        $rng = $seed + $w;
        $end = microtime(true) + $seconds;
        $ops = 0;
        $writes = 0;
        $reads = 0;
        $deletes = 0;
        $batches = 0;
        $iterationChecks = 0;
        $errors = 0;
        $errorSamples = [];

        while (microtime(true) < $end) {
            $pick = $rng % 100;
            $k = 'k:' . (($rng * 1103515245 + 12345) & 0x1fff);
            try {
                if ($pick < 45) {
                    $tmp = $child[$k];
                    $reads++;
                } elseif ($pick < 70) {
                    $child[$k] = ['n' => $rng, 'w' => $w];
                    $writes++;
                } elseif ($pick < 82) {
                    unset($child[$k]);
                    $deletes++;
                } elseif ($pick < 90) {
                    $child[$k . ':a'] = $rng;
                    $child[$k . ':b'] = $rng + 1;
                    $writes += 2;
                    $batches++;
                } elseif ($pick < 96) {
                    test_mp_batch($child, $k, $rng);
                    $batches++;
                    $writes += 2;
                } else {
                    $count = 0;
                    foreach ($child as $key => $value) {
                        $count++;
                        if ($count >= 5) {
                            break;
                        }
                    }
                    $iterationChecks++;
                }

                if (($ops % 97) === 0) {
                    $exists = isset($child[$k]);
                    if ($exists) {
                        $tmp = $child[$k];
                    }
                }
            } catch (Throwable $e) {
                $errors++;
                if (\count($errorSamples) < 5) {
                    $trace = [];
                    foreach (\array_slice($e->getTrace(), 0, 4) as $frame) {
                        $trace[] = (
                            ($frame['class'] ?? '') !== '' ? $frame['class'] . ($frame['type'] ?? '') : ''
                        ) . ($frame['function'] ?? 'unknown') . ' @ ' . ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? 0);
                    }
                    $errorSamples[] = \get_class($e) . ': ' . $e->getMessage()
                        . ' | ' . \implode(' <= ', $trace);
                }
            }

            $ops++;
            $rng = ($rng * 1664525 + 1013904223) & 0x7fffffff;
        }

        $summary = [
            'worker' => $w,
            'ops' => $ops,
            'reads' => $reads,
            'writes' => $writes,
            'deletes' => $deletes,
            'batches' => $batches,
            'iters' => $iterationChecks,
            'errors' => $errors,
            'count' => $child->count(),
            'error_samples' => $errorSamples,
        ];

        echo json_encode($summary, JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit($errors === 0 ? 0 : 1);
    }

    $pids[] = $pid;
}

function test_mp_batch(Fast $store, string $key, int $seed): void
{
    $store[$key . ':x'] = $seed;
    $store[$key . ':y'] = $seed + 1;
}

$start = microtime(true);
$summaries = [];
foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
    if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
        $fail('worker ' . $pid . ' failed');
    }
}

$elapsed = microtime(true) - $start;
$store['final'] = 1;
$finalCount = $store->count();

$store->destroy();

echo 'mp parallel stress ok' . PHP_EOL;
echo 'workers=' . $workers
    . ' seconds=' . $seconds
    . ' elapsed=' . number_format($elapsed, 3)
    . ' count=' . $finalCount
    . PHP_EOL;
