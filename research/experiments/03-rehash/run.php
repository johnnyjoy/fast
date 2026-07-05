<?php declare(strict_types = 1);
/**
 * Design study — Experiment 3: rehash-under-load (§3).
 *
 * Clean-room, algorithm-level. The §3 index (bucket-fp, Experiment 2) must grow
 * 1e3 -> 1e6 (and shrink back) WITHOUT a stop-the-world stall and WITHOUT stalling
 * live readers. Two rehash strategies, identical workload:
 *
 *   stop-world      double the table on overflow and rehash ALL entries in the
 *                   triggering op. Simple; one op pays O(N) — the stall.
 *   incremental-K   Redis dict model: keep old+new tables, migrate K buckets per
 *                   subsequent op; reads/writes consult BOTH tables until the
 *                   migration drains. Bounded per-op work; transient 2x memory.
 *
 * Portable metrics (these transfer to PHP/shmop; wall-clock would just be GC noise):
 *   - max entries moved in a single op            (the stall: O(N) vs O(K*B))
 *   - p99 entries moved per op                     (tail of write-path maintenance)
 *   - total entries moved / N                      (rehash write amplification)
 *   - reader cost: avg bucket reads per lookup     (1.0 ideal; 2.0 while migrating)
 *   - peak table memory (both tables live)         (transient overshoot)
 *
 * Usage:
 *   php research/experiments/03-rehash/run.php
 *   php research/experiments/03-rehash/run.php --target=1000000 --reads=4 --k=4
 *   php research/experiments/03-rehash/run.php --drain          # also shrink 1e6 -> 1e3
 */

namespace Fast\Research;

$opt = static function (string $k, string $d) use ($argv): string {
    foreach ($argv as $a) {
        if (\str_starts_with($a, "--$k=")) {
            return \substr($a, \strlen($k) + 3);
        }
    }
    return $d;
};
$flag = static function (string $k) use ($argv): bool {
    return \in_array("--$k", $argv, true);
};

$START   = (int) $opt('start', '1000');
$TARGET  = (int) $opt('target', '1000000');
$B       = (int) $opt('bucket', '8');      // entries per bucket (§3 cache-line bucket)
$READS   = (int) $opt('reads', '4');       // live reader lookups interleaved per write op
$K       = (int) $opt('k', '4');           // buckets migrated per op (incremental)
$GROW    = (float) $opt('grow', '0.90');   // load that triggers a grow
$SHRINK  = (float) $opt('shrink', '0.20'); // load that triggers a shrink (drain phase)
$DRAIN   = $flag('drain');
$BUCKET_BYTES = 64;                        // §3 one cache line

/** @return array{avg:float,p99:float,max:int} */
function dist(array $s): array
{
    if ($s === []) {
        return ['avg' => 0.0, 'p99' => 0.0, 'max' => 0];
    }
    \sort($s, \SORT_NUMERIC);
    $n = \count($s);
    return [
        'avg' => \array_sum($s) / $n,
        'p99' => (float) $s[\min($n - 1, (int) \floor(0.99 * ($n - 1)))],
        'max' => (int) $s[$n - 1],
    ];
}

/**
 * One run. $mode = 'stop' | 'inc'. Returns metrics.
 * Models the directory as bucket COUNTS + a migration cursor — entry placement is
 * uniform, so per-op moved work and reader dual-table cost are exact; only key
 * identity (irrelevant to these metrics) is abstracted away.
 */
function run(string $mode, int $start, int $target, int $B, int $reads, int $K,
    float $grow, float $shrink, bool $drain, int $bucketBytes): array
{
    $minBuckets = \max(2, (int) \ceil($start / ($B * $grow)));
    $buckets = $minBuckets;                 // active (new) table bucket count
    $entries = 0;

    // migration state (incremental)
    $mig = false;
    $oldBuckets = 0;
    $newBuckets = 0;
    $cursor = 0;                            // old buckets already drained
    $movePerBucket = 0.0;                   // entries snapshot / oldBuckets at trigger
    $moveAcc = 0.0;

    $moved = [];                            // per-op entries moved (for p99/max)
    $totalMoved = 0;
    $readOps = 0;
    $readBucketReads = 0;                   // sum of buckets touched by reads
    $peakBuckets = $buckets;                // max combined buckets live at once

    $logicalBuckets = static function () use (&$mig, &$buckets, &$oldBuckets, &$newBuckets): int {
        // memory footprint = both tables while migrating
        return $mig ? ($oldBuckets + $newBuckets) : $buckets;
    };

    // advance an in-flight incremental migration by up to $K buckets; returns moved
    $step = static function (int $K) use (&$mig, &$cursor, &$oldBuckets, &$newBuckets,
        &$buckets, &$movePerBucket, &$moveAcc): int {
        if (!$mig) {
            return 0;
        }
        $stepBuckets = \min($K, $oldBuckets - $cursor);
        $cursor += $stepBuckets;
        $moveAcc += $stepBuckets * $movePerBucket;
        $movedNow = (int) \floor($moveAcc);
        $moveAcc -= $movedNow;
        if ($cursor >= $oldBuckets) {       // migration complete
            $mig = false;
            $buckets = $newBuckets;
            $oldBuckets = 0;
            $newBuckets = 0;
            $cursor = 0;
            $moveAcc = 0.0;
        }
        return $movedNow;
    };

    // trigger a resize to $factor x current logical size
    $resize = static function (float $factor, string $mode, int $entriesNow)
        use (&$mig, &$buckets, &$oldBuckets, &$newBuckets, &$cursor, &$movePerBucket, &$moveAcc): int {
        $target = \max(2, (int) \ceil($buckets * $factor));
        if ($target === $buckets) {
            return 0;
        }
        if ($mode === 'stop') {
            $buckets = $target;
            return $entriesNow;             // O(N) — the stall
        }
        // incremental: stand up new table, migrate lazily
        $mig = true;
        $oldBuckets = $buckets;
        $newBuckets = $target;
        $cursor = 0;
        $movePerBucket = $oldBuckets > 0 ? $entriesNow / $oldBuckets : 0.0;
        $moveAcc = 0.0;
        return 0;
    };

    $doReads = static function () use (&$mig, $reads, &$readOps, &$readBucketReads): void {
        // Redis consults both tables while rehashing -> 2 bucket reads, else 1
        $perRead = $mig ? 2 : 1;
        $readOps += $reads;
        $readBucketReads += $reads * $perRead;
    };

    // ---------------- GROW: insert start -> target ----------------
    for ($entries = 0; $entries < $target; ) {
        $movedThisOp = 0;
        if ($mode === 'inc') {
            $movedThisOp += $step($K);
        }
        $entries++;                          // insert one entry
        // Redis sizes against the table being filled: the new table while rehashing.
        $effBuckets = $mig ? $newBuckets : $buckets;
        $load = $entries / ($effBuckets * $B);
        if ($load > $grow && !$mig) {
            // if a prior migration is somehow still active in 'inc', $mig guards it
            $movedThisOp += $resize(2.0, $mode, $entries);
        } elseif ($load > $grow && $mig && $mode === 'inc') {
            // growth outran the migrator: force-finish now (honest stall signal)
            $movedThisOp += $step($oldBuckets);  // drain remainder this op
            $movedThisOp += $resize(2.0, $mode, $entries);
        }
        $doReads();
        $totalMoved += $movedThisOp;
        $moved[] = $movedThisOp;
        $cur = $mig ? ($oldBuckets + $newBuckets) : $buckets;
        if ($cur > $peakBuckets) {
            $peakBuckets = $cur;
        }
    }

    // finish any trailing migration so the grow phase ends in a clean state
    while ($mig) {
        $m = $step($K);
        $totalMoved += $m;
        $moved[] = $m;
    }

    $growBuckets = $buckets;

    // ---------------- DRAIN (optional): delete target -> start, shrink ----------------
    if ($drain) {
        for (; $entries > $start; ) {
            $movedThisOp = 0;
            if ($mode === 'inc') {
                $movedThisOp += $step($K);
            }
            $entries--;                      // delete one entry
            $effBuckets = $mig ? $newBuckets : $buckets;
            $load = $entries / ($effBuckets * $B);
            if ($load < $shrink && $buckets > $minBuckets && !$mig) {
                $movedThisOp += $resize(0.5, $mode, $entries);
            } elseif ($load < $shrink && $buckets > $minBuckets && $mig && $mode === 'inc') {
                $movedThisOp += $step($oldBuckets);
                $movedThisOp += $resize(0.5, $mode, $entries);
            }
            $doReads();
            $totalMoved += $movedThisOp;
            $moved[] = $movedThisOp;
        }
        while ($mig) {
            $m = $step($K);
            $totalMoved += $m;
            $moved[] = $m;
        }
    }

    $d = dist($moved);
    return [
        'mode'          => $mode === 'stop' ? 'stop-world' : "incremental-K{$K}",
        'maxMoved'      => $d['max'],
        'p99Moved'      => $d['p99'],
        'avgMoved'      => $d['avg'],
        'totalMoved'    => $totalMoved,
        'rehashAmp'     => $target > 0 ? $totalMoved / $target : 0.0,
        'readsPerLookup' => $readOps > 0 ? $readBucketReads / $readOps : 0.0,
        'peakBuckets'   => $peakBuckets,
        'peakTableMiB'  => $peakBuckets * $bucketBytes / (1024 * 1024),
        'growBuckets'   => $growBuckets,
    ];
}

echo "Experiment 3 — rehash-under-load (PHP " . \PHP_VERSION . ")\n";
echo "grow " . \number_format($START) . " -> " . \number_format($TARGET)
    . ($DRAIN ? "  then drain -> " . \number_format($START) : "")
    . "  | bucket=$B reads/op=$READS K=$K grow@{$GROW} shrink@{$SHRINK}\n\n";

$rows = [];
$rows[] = run('stop', $START, $TARGET, $B, $READS, $K, $GROW, $SHRINK, $DRAIN, $BUCKET_BYTES);
foreach ([1, 4, 16] as $k) {
    $rows[] = run('inc', $START, $TARGET, $B, $READS, $k, $GROW, $SHRINK, $DRAIN, $BUCKET_BYTES);
}

$nf = static fn (int|float $v): string => \number_format((float) $v);
$f2 = static fn (float $v): string => \number_format($v, 2);
\printf("%-15s | %13s | %11s | %12s | %10s | %14s | %13s\n",
    'strategy', 'max moved/op', 'p99 moved', 'total moved', 'rehash amp', 'reads/lookup', 'peak table');
echo \str_repeat('-', 105) . "\n";
foreach ($rows as $r) {
    \printf("%-15s | %13s | %11s | %12s | %9sx | %14s | %10s MiB\n",
        $r['mode'], $nf($r['maxMoved']), $nf($r['p99Moved']), $nf($r['totalMoved']),
        $f2($r['rehashAmp']), $f2($r['readsPerLookup']), $f2($r['peakTableMiB']));
}
echo \str_repeat('-', 105) . "\n";
echo "max moved/op = the stall (entries rehashed in one op). reads/lookup: 1.0 = never blocked, "
    . "2.0 = always dual-table.\n";

$dir = __DIR__ . '/baselines';
if (!\is_dir($dir)) {
    @\mkdir($dir, 0755, true);
}
$path = $dir . '/exp3-rehash-' . \gmdate('Ymd-His') . '.json';
\file_put_contents($path, \json_encode([
    'schema'   => 'fast-exp3/1',
    'captured' => \gmdate('c'),
    'params'   => \compact('START', 'TARGET', 'B', 'READS', 'K', 'GROW', 'SHRINK', 'DRAIN'),
    'results'  => $rows,
], \JSON_PRETTY_PRINT) . "\n");
echo "\nArtifact: $path\n";
echo "php peak memory: " . \number_format(\memory_get_peak_usage(true)) . " bytes\n";
