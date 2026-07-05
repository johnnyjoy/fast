<?php declare(strict_types = 1);
/**
 * Audit Experiment 9 — SC-2: global writer semaphore vs striped locks (MP).
 *
 * Proof-of-problem AND proof-of-solution for the single global writer lock. Every
 * Flat write serializes the WHOLE store: sem_acquire -> critical section -> release.
 * Under W concurrent writers only one is ever inside the critical section, so write
 * throughput should plateau (or degrade on contention) as W grows, no matter how
 * many cores are free.
 *
 * Remedy modelled: STRIPED locks. The store is partitioned into S independent
 * sub-stores, each with its own semaphore + seqlock + arena region. A write to key
 * k touches only stripe (hash(k) % S) under stripe[k]'s lock, so up to S writers
 * proceed in parallel. This is the SOUND form under the FFI ban (no atomic frontier):
 * each stripe owns its allocator, so there is no shared serialization point. The
 * design cost (out of scope to measure here, noted): count()/foreach must merge S
 * order logs, and capacity is partitioned S ways.
 *
 * The critical section replicates a real Flat::set's shared-memory traffic: open
 * the seqlock, ~2 directory-slot reads (probe), a record write, a slot write, a few
 * header bumps (frontier/live/order), close the seqlock — ~10 shmop ops on a small
 * cache-hot per-stripe region, so we measure LOCK + syscall serialization, not
 * memory bandwidth.
 *
 * Usage: php research/experiments/09-lock-striping/run.php [secs=2] [workers=1,2,4,8] [stripes=8]
 */

namespace Fast\Research;

if (!\function_exists('pcntl_fork')) { \fwrite(\STDERR, "pcntl required\n"); exit(2); }

$secs    = (float) ($argv[1] ?? 2.0);
$workersList = \array_map('intval', \explode(',', (string) ($argv[2] ?? '1,2,4,8')));
$stripes = (int) ($argv[3] ?? 8);

$ncpu = (int) (@\shell_exec('nproc') ?: 0);

$segSize = 64 * 1024 * 1024;
$segKey  = \mt_rand(1, 0x6ffffff0);
$seg = @\shmop_open($segKey, 'c', 0600, $segSize);
if ($seg === false) { \fwrite(\STDERR, "shmop_open failed\n"); exit(1); }

// per-stripe region base (cache-hot small footprint each), results region at the top
$STRIPE_SPAN = 4096;                       // each stripe's working region
$RESULTS = $segSize - 4096;                // 64 * 8-byte counters

// representative critical section: ~10 shmop ops within one stripe's region.
$crit = static function ($seg, int $base): void {
    $s = \unpack('V', \shmop_read($seg, $base, 4))[1];
    \shmop_write($seg, \pack('V', $s + 1), $base);          // seq odd (open)
    \shmop_read($seg, $base + 64, 24);                      // probe slot 1
    \shmop_read($seg, $base + 88, 24);                      // probe slot 2
    \shmop_write($seg, \str_repeat('R', 40), $base + 512);  // record write
    \shmop_write($seg, \str_repeat('S', 24), $base + 128);  // slot write
    \shmop_write($seg, \pack('P', $s), $base + 200);        // frontier bump
    \shmop_write($seg, \pack('V', $s), $base + 208);        // live bump
    \shmop_write($seg, \pack('V', $s), $base + 212);        // order bump
    \shmop_write($seg, \pack('V', $s + 2), $base);          // seq even (close)
};

$runCell = static function (int $W, int $S, float $secs) use ($seg, $segKey, $segSize, $crit, $STRIPE_SPAN, $RESULTS, $stripes): float {
    // create S semaphores (S=1 => global lock)
    $sems = [];
    $semKeys = [];
    for ($i = 0; $i < $S; $i++) {
        $sk = \mt_rand(1, 0x5ffffff0);
        $semKeys[$i] = $sk;
        $sems[$i] = \sem_get($sk, 1, 0600, true);
    }
    // zero results
    \shmop_write($seg, \str_repeat("\0", 8 * $W), $RESULTS);

    $pids = [];
    for ($w = 0; $w < $W; $w++) {
        $pid = \pcntl_fork();
        if ($pid === 0) {
            \mt_srand($w * 7919 + 1);
            $deadline = \hrtime(true) + (int) ($secs * 1e9);
            $ops = 0;
            while (\hrtime(true) < $deadline) {
                for ($b = 0; $b < 256; $b++) {                 // batch to amortize the clock read
                    $stripe = \mt_rand(0, $S - 1);
                    $base = 4096 + $stripe * $STRIPE_SPAN;
                    \sem_acquire($sems[$stripe]);
                    $crit($seg, $base);
                    \sem_release($sems[$stripe]);
                    $ops++;
                }
            }
            \shmop_write($seg, \pack('P', $ops), $RESULTS + $w * 8);
            exit(0);
        }
        $pids[$w] = $pid;
    }
    foreach ($pids as $pid) { \pcntl_waitpid($pid, $st); }

    $total = 0;
    for ($w = 0; $w < $W; $w++) {
        $total += \unpack('P', \shmop_read($seg, $RESULTS + $w * 8, 8))[1];
    }
    foreach ($sems as $sm) { @\sem_remove($sm); }
    return $total / $secs;
};

\printf("exp9 lock striping — PHP %s  nproc=%d  secs=%.1f  stripes(striped)=%d\n\n",
    \PHP_VERSION, $ncpu, $secs, $stripes);
\printf("%-9s %18s %18s %12s\n", 'workers', 'global (ops/s)', 'striped (ops/s)', 'speedup');
\printf("%'-60s\n", '');

$g1 = null;
foreach ($workersList as $W) {
    $g = $runCell($W, 1, $secs);            // global: single lock
    $s = $runCell($W, $stripes, $secs);     // striped: S independent locks
    if ($g1 === null && $W === 1) { $g1 = $g; }
    \printf("%-9d %18s %18s %11.2fx\n", $W, \number_format($g), \number_format($s), $g > 0 ? $s / $g : 0);
}

\shmop_delete($seg);
echo "\nglobal should plateau as workers rise (one writer in the section at a time);\n";
echo "striped should scale with workers up to min(stripes, cores). Speedup = striped/global.\n";
