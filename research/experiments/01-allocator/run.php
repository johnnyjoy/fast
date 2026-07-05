<?php declare(strict_types = 1);
/**
 * Design study — Experiment 1: allocator / shrink bake-off (§4).
 *
 * Clean-room, algorithm-level simulation. No shmop: we model the arena as integer
 * bookkeeping because the questions are (a) steady-state UTILIZATION, (b) whether
 * the design can SHRINK after a drain, and (c) per-op cost incl. maintenance
 * spikes (writer tail latency) and bytes copied. Those are portable properties of
 * the algorithm, not of PHP's memory.
 *
 * Three contenders, identical workload:
 *   slab         free-list per size-class; opportunistic trailing release only
 *                (mirrors current Fast: cheap ops, calcifies, barely shrinks)
 *   slab+compact study "design A": free-list + bounded foreground compaction when
 *                utilization falls below a watermark
 *   log          study "design B": append-only segments + incremental cleaning of
 *                the most-dead segment + trailing segment release (real shrink)
 *
 * Workload: ramp live set to TARGET, churn (replace/delete+insert) holding live
 * roughly constant, then DRAIN to a small live set — the shrink test the current
 * code fails.
 *
 * Usage:
 *   php research/experiments/01-allocator/run.php
 *   php research/experiments/01-allocator/run.php --full
 *   php research/experiments/01-allocator/run.php --target=200000 --churn=1000000 --drain=20000
 */

namespace Fast\Research;

/* --------------------------- args --------------------------- */
$argvOpt = static function (string $k, string $d) use ($argv): string {
    foreach ($argv as $a) {
        if (\str_starts_with($a, "--$k=")) {
            return \substr($a, \strlen($k) + 3);
        }
    }
    return $d;
};
$full   = \in_array('--full', $argv, true);
$target = (int) $argvOpt('target', $full ? '1000000' : '50000');
$churn  = (int) $argvOpt('churn',  $full ? '4000000' : '300000');
$drain  = (int) $argvOpt('drain',  $full ? '100000'  : '5000');
$seed   = (int) $argvOpt('seed', '1');
$segBytes = (int) $argvOpt('segment', (string) (8 << 20));   // log segment size
$watermark = (float) $argvOpt('watermark', '0.6');           // slab+compact trigger

/* --------------------------- size distribution --------------------------- */
function drawSize(): int
{
    $r = \mt_rand(1, 100);
    if ($r <= 60) {
        return \mt_rand(8, 64);
    }
    if ($r <= 90) {
        return \mt_rand(65, 512);
    }
    if ($r <= 99) {
        return \mt_rand(513, 2048);
    }
    return \mt_rand(4096, 8192);
}

/* --------------------------- allocator interface --------------------------- */
interface Allocator
{
    public function name(): string;
    public function alloc(int $id, int $size): void;
    public function free(int $id): void;
    public function liveBytes(): int;
    public function arenaBytes(): int;   // footprint the OS still holds
    public function shrink(): void;      // explicit reclaim (end of drain)
    public function copiedBytes(): int;  // total bytes relocated by maintenance
}

/* --------------------------- size-class ladder --------------------------- */
function classLadder(): array
{
    $ladder = [];
    foreach ([8, 16, 24, 32, 48, 64, 80, 96, 112, 128] as $s) {
        $ladder[] = $s;
    }
    $s = 128;
    while ($s < (16 << 10)) {
        $s = (int) \ceil($s * 1.25);
        $ladder[] = $s;
    }
    return $ladder;
}
function classFor(array $ladder, int $size): int
{
    foreach ($ladder as $c) {
        if ($c >= $size) {
            return $c;
        }
    }
    return $size; // huge: exact
}

/* --------------------------- A: slab (free-list) ---------------------------
 * mode = 'none'  free-list only, opportunistic trailing release (current Fast)
 *        'full'  free-list + ONE full compaction pass when util < watermark
 *        'inc'   free-list + BOUNDED incremental compaction (budget blocks per
 *                trigger), spread across ops so no single op pays the full pass
 */
class Slab implements Allocator
{
    protected array $ladder;
    protected array $free = [];         // class => list<offset>
    protected array $live = [];         // id => [offset, class, size]
    protected int $frontier = 0;
    protected int $liveBytesSum = 0;
    protected int $copied = 0;

    // incremental compaction job state
    protected bool $jobActive = false;
    protected array $jobIds = [];
    protected int $jobPos = 0;
    protected int $jobTotal = 0;
    protected float $slackPerBlock = 0.0;
    protected float $slackAcc = 0.0;

    public function __construct(protected string $mode, protected float $watermark, protected int $budget = 256)
    {
        $this->ladder = classLadder();
    }

    public function name(): string
    {
        return match ($this->mode) {
            'full' => 'slab+compact',
            'inc'  => 'slab+inc',
            default => 'slab',
        };
    }

    public function alloc(int $id, int $size): void
    {
        $class = classFor($this->ladder, $size);
        if (!empty($this->free[$class])) {
            $offset = \array_pop($this->free[$class]);
        } else {
            $offset = $this->frontier;
            $this->frontier += $class;
        }
        $this->live[$id] = [$offset, $class, $size];
        $this->liveBytesSum += $size;

        if ($this->mode === 'inc' && $this->jobActive) {
            $this->compactStep();   // keep an in-flight job progressing
        }
    }

    public function free(int $id): void
    {
        if (!isset($this->live[$id])) {
            return;
        }
        [$offset, $class, $size] = $this->live[$id];
        unset($this->live[$id]);
        $this->liveBytesSum -= $size;
        $this->free[$class][] = $offset;

        if ($this->mode === 'full'
            && $this->frontier > (1 << 20)
            && $this->utilization() < $this->watermark) {
            $this->compactFull();
        } elseif ($this->mode === 'inc') {
            if (!$this->jobActive
                && $this->frontier > (1 << 20)
                && $this->utilization() < $this->watermark) {
                $this->startCompaction();
            }
            if ($this->jobActive) {
                $this->compactStep();
            }
        }
    }

    protected function utilization(): float
    {
        return $this->frontier > 0 ? $this->liveBytesSum / $this->frontier : 1.0;
    }

    protected function compactFull(): void
    {
        // Relocate every live block densely (by class). Cost = all live bytes,
        // paid by THIS op (the spike the incremental mode eliminates).
        $frontier = 0;
        foreach ($this->live as $id => [$offset, $class, $size]) {
            $this->live[$id] = [$frontier, $class, $size];
            $frontier += $class;
            $this->copied += $size;
        }
        $this->frontier = $frontier;
        $this->free = [];
    }

    protected function startCompaction(): void
    {
        $packed = 0;
        foreach ($this->live as [$o, $class, $size]) {
            $packed += $class;
        }
        $this->jobIds = \array_keys($this->live);
        $this->jobTotal = \count($this->jobIds);
        $this->jobPos = 0;
        $this->slackPerBlock = $this->jobTotal > 0
            ? \max(0, $this->frontier - $packed) / $this->jobTotal
            : 0.0;
        $this->slackAcc = 0.0;
        $this->jobActive = $this->jobTotal > 0;
    }

    protected function compactStep(): void
    {
        $start = $this->jobPos;
        $end = \min($this->jobTotal, $this->jobPos + $this->budget);
        for (; $this->jobPos < $end; $this->jobPos++) {
            $id = $this->jobIds[$this->jobPos];
            if (isset($this->live[$id])) {
                $this->copied += $this->live[$id][2];   // bytes relocated
            }
        }
        // lower the frontier by the slack attributable to the blocks just moved
        $processed = $end - $start;
        $this->slackAcc += $this->slackPerBlock * $processed;
        $whole = (int) $this->slackAcc;
        if ($whole > 0) {
            $this->frontier = \max(0, $this->frontier - $whole);
            $this->slackAcc -= $whole;
        }
        if ($this->jobPos >= $this->jobTotal) {
            // finish: snap to the exact packed size of whatever is live now
            $packed = 0;
            foreach ($this->live as [$o, $class, $size]) {
                $packed += $class;
            }
            $this->frontier = $packed;
            $this->free = [];
            $this->jobActive = false;
            $this->jobIds = [];
        }
    }

    public function shrink(): void
    {
        if ($this->mode === 'full') {
            $this->compactFull();
            return;
        }
        if ($this->mode === 'inc') {
            if (!$this->jobActive && $this->utilization() < $this->watermark) {
                $this->startCompaction();
            }
            while ($this->jobActive) {
                $this->compactStep();
            }
            return;
        }
        // 'none': opportunistic trailing free-block release only
        $byOffset = [];
        foreach ($this->free as $class => $offsets) {
            foreach ($offsets as $o) {
                $byOffset[$o] = $class;
            }
        }
        \krsort($byOffset);
        foreach ($byOffset as $o => $class) {
            if ($o + $class === $this->frontier) {
                $this->frontier -= $class;
                $idx = \array_search($o, $this->free[$class], true);
                if ($idx !== false) {
                    unset($this->free[$class][$idx]);
                }
            } else {
                break;
            }
        }
    }

    public function liveBytes(): int
    {
        return $this->liveBytesSum;
    }
    public function arenaBytes(): int
    {
        return $this->frontier;
    }
    public function copiedBytes(): int
    {
        return $this->copied;
    }
}

/* --------------------------- B: log-structured --------------------------- */
class LogStructured implements Allocator
{
    /** @var array<int,array{live:int,dead:int}> seg index => bytes */
    protected array $segs = [];
    /** @var array<int,array<int,int>> seg index => (id => size) */
    protected array $segLive = [];
    /** @var array<int,array{seg:int,size:int}> id => location */
    protected array $live = [];
    protected int $headSeg = 0;
    protected int $headUsed = 0;
    protected int $liveBytesSum = 0;
    protected int $copied = 0;

    public function __construct(protected int $segBytes, protected float $deadThreshold = 0.30)
    {
        $this->segs[0] = ['live' => 0, 'dead' => 0];
        $this->segLive[0] = [];
    }

    public function name(): string
    {
        return 'log@' . \number_format($this->deadThreshold, 2);
    }

    public function alloc(int $id, int $size): void
    {
        $this->append($id, $size, false);
        $this->maybeClean();
    }

    protected function append(int $id, int $size, bool $isRelocation): void
    {
        if ($this->headUsed + $size > $this->segBytes && $this->headUsed > 0) {
            $this->headSeg++;
            $this->headUsed = 0;
            $this->segs[$this->headSeg] = ['live' => 0, 'dead' => 0];
            $this->segLive[$this->headSeg] = [];
        }
        $this->segs[$this->headSeg]['live'] += $size;
        $this->segLive[$this->headSeg][$id] = $size;
        $this->headUsed += $size;
        $this->live[$id] = ['seg' => $this->headSeg, 'size' => $size];
        if (!$isRelocation) {
            // relocation moves already-live bytes; only a new alloc adds live bytes
            $this->liveBytesSum += $size;
        }
    }

    public function free(int $id): void
    {
        if (!isset($this->live[$id])) {
            return;
        }
        ['seg' => $seg, 'size' => $size] = $this->live[$id];
        unset($this->live[$id], $this->segLive[$seg][$id]);
        $this->segs[$seg]['live'] -= $size;
        $this->segs[$seg]['dead'] += $size;
        $this->liveBytesSum -= $size;
        $this->maybeClean();
    }

    protected function totalDead(): int
    {
        $d = 0;
        foreach ($this->segs as $s) {
            $d += $s['dead'];
        }
        return $d;
    }

    protected function maybeClean(): void
    {
        $arena = (\count($this->segs)) * $this->segBytes;
        if ($arena < (4 * $this->segBytes)) {
            return;
        }
        if ($this->totalDead() / \max(1, $arena) < $this->deadThreshold) {
            return;
        }
        // bounded incremental cleaning: clean a few segments per trigger until
        // back under threshold (foreground, no background thread available)
        $budget = 3;
        while ($budget-- > 0
            && $this->totalDead() / \max(1, \count($this->segs) * $this->segBytes) >= $this->deadThreshold) {
            if (!$this->cleanOnce()) {
                break;
            }
        }
    }

    protected function cleanOnce(): bool
    {
        // cost-benefit victim selection (RAMCloud-style): maximize reclaimed dead
        // per byte copied => dead / (1 + live). Skews toward mostly-dead segments.
        $victim = -1;
        $bestScore = -1.0;
        foreach ($this->segs as $i => $s) {
            if ($i === $this->headSeg) {
                continue; // never clean the open head
            }
            if ($s['dead'] <= 0) {
                continue;
            }
            $score = $s['dead'] / (1 + $s['live']);
            if ($score > $bestScore) {
                $bestScore = $score;
                $victim = $i;
            }
        }
        if ($victim < 0) {
            return false;
        }
        // relocate survivors of the victim to the head
        foreach ($this->segLive[$victim] as $id => $size) {
            $this->append($id, $size, true);   // relocation: live bytes unchanged
            $this->copied += $size;
        }
        // victim is now empty: reclaim it
        unset($this->segs[$victim], $this->segLive[$victim]);
        $this->releaseTrailing();
        return true;
    }

    protected function releaseTrailing(): void
    {
        // drop empty trailing segments below the head to actually shrink
        \ksort($this->segs);
    }

    public function shrink(): void
    {
        // aggressive end-of-drain compaction: clean until dead is negligible
        $guard = 0;
        while ($this->totalDead() > 0 && $guard++ < 1000000) {
            if (!$this->cleanOnce()) {
                break;
            }
        }
    }

    public function liveBytes(): int
    {
        return $this->liveBytesSum;
    }

    public function arenaBytes(): int
    {
        // footprint = number of live segments * segment size (trailing released)
        return \count($this->segs) * $this->segBytes;
    }

    public function copiedBytes(): int
    {
        return $this->copied;
    }
}

/* --------------------------- workload driver --------------------------- */
/**
 * @return array<string,mixed>
 */
function drive(Allocator $a, int $target, int $churn, int $drain, int $seed): array
{
    \mt_srand($seed); // identical workload per contender

    $liveIds = [];        // list of live ids
    $pos = [];            // id => index in $liveIds (swap-remove)
    $nextId = 1;
    $samples = [];
    $maxNs = 0;
    $peakArena = 0;

    $insert = static function () use ($a, &$liveIds, &$pos, &$nextId): void {
        $id = $nextId++;
        $a->alloc($id, drawSize());
        $pos[$id] = \count($liveIds);
        $liveIds[] = $id;
    };
    $removeAt = static function (int $idx) use ($a, &$liveIds, &$pos): void {
        $id = $liveIds[$idx];
        $last = \array_pop($liveIds);
        if ($last !== $id) {
            $liveIds[$idx] = $last;
            $pos[$last] = $idx;
        }
        unset($pos[$id]);
        $a->free($id);
    };

    $time = static function (callable $fn) use (&$samples, &$maxNs): void {
        $t = \hrtime(true);
        $fn();
        $d = \hrtime(true) - $t;
        $samples[] = $d;
        if ($d > $maxNs) {
            $maxNs = $d;
        }
    };

    // ramp
    while (\count($liveIds) < $target) {
        $time($insert);
    }

    // churn — hold live ~ target
    for ($i = 0; $i < $churn; $i++) {
        $live = \count($liveIds);
        $r = \mt_rand(1, 100);
        if ($live < (int) ($target * 0.97)) {
            $time($insert);
        } elseif ($live > (int) ($target * 1.03)) {
            $time(static fn () => $removeAt(\mt_rand(0, $live - 1)));
        } elseif ($r <= 55) {
            // replace: free + alloc same logical key (new size)
            $idx = \mt_rand(0, $live - 1);
            $time(static function () use ($a, $removeAt, $insert, $idx): void {
                $removeAt($idx);
                $insert();
            });
        } elseif ($r <= 80) {
            $time(static fn () => $removeAt(\mt_rand(0, $live - 1)));
        } else {
            $time($insert);
        }
        $peakArena = \max($peakArena, $a->arenaBytes());
    }

    $steadyUtil = $a->arenaBytes() > 0 ? $a->liveBytes() / $a->arenaBytes() : 0.0;
    $steadyArena = $a->arenaBytes();

    // drain to small live set
    while (\count($liveIds) > $drain) {
        $time(static fn () => $removeAt(\mt_rand(0, \count($liveIds) - 1)));
    }
    $a->shrink();

    $postArena = $a->arenaBytes();
    $postUtil = $postArena > 0 ? $a->liveBytes() / $postArena : 0.0;

    \sort($samples, \SORT_NUMERIC);
    $n = \count($samples);
    $at = static fn (float $q): float => $n ? (float) $samples[\min($n - 1, (int) \floor($q * ($n - 1)))] : 0.0;

    return [
        'name'          => $a->name(),
        'ops'           => $n,
        'steady_util'   => \round($steadyUtil, 4),
        'steady_arena'  => $steadyArena,
        'peak_arena'    => $peakArena,
        'post_arena'    => $postArena,
        'post_util'     => \round($postUtil, 4),
        'live_bytes_end'=> $a->liveBytes(),
        'p50_ns'        => $at(0.50),
        'p95_ns'        => $at(0.95),
        'p99_ns'        => $at(0.99),
        'max_ns'        => $maxNs,
        'copied_bytes'  => $a->copiedBytes(),
    ];
}

/* --------------------------- run --------------------------- */
\fprintf(\STDERR, "exp1 allocator bake-off: target=%s churn=%s drain=%s seg=%s watermark=%.2f\n",
    \number_format($target), \number_format($churn), \number_format($drain),
    \number_format($segBytes), $watermark);

$contenders = [
    new Slab('none', $watermark),
    new Slab('full', $watermark),
    new Slab('inc', $watermark, 256),
    new LogStructured($segBytes, 0.30),
    new LogStructured($segBytes, 0.15),
    new LogStructured($segBytes, 0.08),
];

$results = [];
foreach ($contenders as $c) {
    \fprintf(\STDERR, "  running %s ...\n", $c->name());
    $results[] = drive($c, $target, $churn, $drain, $seed);
}

$mib = static fn (int $b): string => \number_format($b / 1048576, 1);
$us  = static fn (float $ns): string => \number_format($ns / 1000, 2);

echo "\nExperiment 1 — allocator / shrink bake-off (PHP " . \PHP_VERSION . ")\n";
echo "target=" . \number_format($target) . " churn=" . \number_format($churn)
    . " drain=" . \number_format($drain) . " seed=$seed\n";
echo \str_repeat('=', 120) . "\n";
\printf("%-13s | %11s | %11s %11s | %11s %11s | %8s %8s %8s %9s | %10s\n",
    'allocator', 'steadyUtil', 'steadyMiB', 'peakMiB', 'postMiB', 'postUtil',
    'p50µs', 'p95µs', 'p99µs', 'maxµs', 'copiedMiB');
echo \str_repeat('-', 120) . "\n";
foreach ($results as $r) {
    \printf("%-13s | %11s | %11s %11s | %11s %11s | %8s %8s %8s %9s | %10s\n",
        $r['name'],
        (string) $r['steady_util'],
        $mib($r['steady_arena']), $mib($r['peak_arena']),
        $mib($r['post_arena']), (string) $r['post_util'],
        $us($r['p50_ns']), $us($r['p95_ns']), $us($r['p99_ns']), $us($r['max_ns']),
        $mib($r['copied_bytes']));
}
echo \str_repeat('=', 120) . "\n";
echo "Read: steadyUtil = live/arena during churn (higher better). postMiB = footprint after draining to "
    . \number_format($drain) . " live (lower = real shrink). maxµs = worst single-op spike (maintenance tail).\n";
echo "copiedMiB = bytes relocated by compaction/cleaning (the price of high utilization + shrink).\n";

/* artifact */
$dir = __DIR__ . '/baselines';
if (!\is_dir($dir)) {
    @\mkdir($dir, 0755, true);
}
$path = $dir . '/exp1-allocator-' . \gmdate('Ymd-His') . '.json';
\file_put_contents($path, \json_encode([
    'schema'    => 'fast-exp1/1',
    'captured'  => \gmdate('c'),
    'params'    => \compact('target', 'churn', 'drain', 'seed', 'segBytes', 'watermark'),
    'results'   => $results,
], \JSON_PRETTY_PRINT) . "\n");
echo "\nArtifact: $path\n";
echo "php peak memory: " . \number_format(\memory_get_peak_usage(true)) . " bytes\n";
