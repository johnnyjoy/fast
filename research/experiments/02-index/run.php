<?php declare(strict_types = 1);
/**
 * Design study — Experiment 2: index bake-off (§3).
 *
 * Clean-room, algorithm-level. In PHP/shmop the cost that matters is NOT cache
 * lines or SIMD (unavailable) but the number of REGION READS per lookup (each
 * shmop read is a memcpy out of the segment) and the BYTES copied, plus the
 * directory MEMORY per entry. We measure exactly those.
 *
 * Contenders:
 *   open-linear-40  the CURRENT directory: open addressing, linear probe,
 *                   40-byte slots, 1 entry/slot, full-64-bit-hash short-circuit.
 *                   Each probe = one 40-byte region read.
 *   bucket-fp       proposed: hash -> bucket of B entries packed in ONE 64-byte
 *                   line; a single bucket read returns B (fingerprint,id) pairs
 *                   scanned in-memory; only a fingerprint match triggers a key
 *                   read. Linear bucket probing on overflow.
 *
 * Reported at load factors 0.5 / 0.7 / 0.9:
 *   - directory bytes per entry (memory overhead)
 *   - region reads per lookup (hit & miss): avg + p99
 *   - bytes copied per lookup (directory + key reads)
 *   - key reads per hit (fingerprint false-positive cost for bucket-fp)
 *   - insert probe length: avg + p99
 *
 * Usage:
 *   php research/experiments/02-index/run.php
 *   php research/experiments/02-index/run.php --n=1000000 --lookups=500000
 *   php research/experiments/02-index/run.php --bucket=8 --keyread=64
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

$N        = (int) $opt('n', '100000');
$LOOKUPS  = (int) $opt('lookups', '200000');
$B        = (int) $opt('bucket', '8');        // entries per bucket (one cache line)
$keyRead  = (int) $opt('keyread', '64');      // bytes copied for a key/block read
$seed     = (int) $opt('seed', '1');
$alphas   = \array_map('floatval', \explode(',', $opt('alphas', '0.5,0.7,0.9')));

$SLOT_A   = 40;   // current directory slot bytes
$LINE_B   = 64;   // bucket-fp bucket bytes (one cache line)

\mt_srand($seed);

$hasXxh = \in_array('xxh3', \hash_algos(), true);
/** @return array{0:int,1:int} [hash60, fp8] */
function hashKey(string $key, bool $hasXxh): array
{
    $hex = $hasXxh ? \hash('xxh3', $key) : \hash('sha1', $key);
    $h  = (int) \hexdec(\substr($hex, 0, 15));        // 60-bit positive
    $fp = (int) \hexdec(\substr($hex, 8, 2)) & 0xFF;  // a different byte => decorrelated
    return [$h, $fp];
}

function nextPow2(int $v): int
{
    $p = 1;
    while ($p < $v) {
        $p <<= 1;
    }
    return \max(2, $p);
}

/** @return array{avg:float,p99:float,max:int} */
function dist(array $samples): array
{
    if ($samples === []) {
        return ['avg' => 0.0, 'p99' => 0.0, 'max' => 0];
    }
    \sort($samples, \SORT_NUMERIC);
    $n = \count($samples);
    return [
        'avg' => \array_sum($samples) / $n,
        'p99' => (float) $samples[\min($n - 1, (int) \floor(0.99 * ($n - 1)))],
        'max' => (int) $samples[$n - 1],
    ];
}

/* keys: half int-like, half string-like (just distinct strings to hash) */
$keys = [];
for ($i = 0; $i < $N; $i++) {
    $keys[] = ($i & 1) ? "user:$i" : "k$i";
}
$missKeys = [];
for ($i = 0; $i < $LOOKUPS; $i++) {
    $missKeys[] = "absent:" . \mt_rand() . ":$i";
}

/* precompute hashes */
$H = [];
foreach ($keys as $k) {
    $H[] = hashKey($k, $hasXxh);
}

/* ============================ open-linear-40 ============================ */
function buildOpenLinear(array $H, float $alpha): array
{
    // exact sizing to hit the target load factor (pow2+mask is an impl detail and
    // identical for both contenders, so modulo here keeps the comparison fair)
    $S = (int) \ceil(\count($H) / $alpha);
    $slotHash = [];   // index => hash (occupied), absent => empty
    $home = [];       // entry index => placed slot index
    $insertProbes = [];

    foreach ($H as $e => [$h, $fp]) {
        $idx = $h % $S;
        $i = 0;
        while (isset($slotHash[($idx + $i) % $S])) {
            $i++;
        }
        $slot = ($idx + $i) % $S;
        $slotHash[$slot] = $h;
        $home[$e] = $slot;
        $insertProbes[] = $i + 1;
    }

    return [$S, $S, $slotHash, $home, $insertProbes];
}

/* ============================ bucket-fp ============================ */
function buildBucketFp(array $H, float $alpha, int $B): array
{
    $S = (int) \ceil(\count($H) / ($B * $alpha));
    $buckets = [];        // bIndex => list of [fp, hash]
    $count = [];          // bIndex => count
    $home = [];           // entry => bIndex it landed in
    $insertProbes = [];

    foreach ($H as $e => [$h, $fp]) {
        $b = $h % $S;
        $i = 0;
        while (($count[($b + $i) % $S] ?? 0) >= $B) {
            $i++;
        }
        $bi = ($b + $i) % $S;
        $buckets[$bi][] = [$fp, $h];
        $count[$bi] = ($count[$bi] ?? 0) + 1;
        $home[$e] = $bi;
        $insertProbes[] = $i + 1;
    }

    return [$S, $S, $buckets, $count, $home, $insertProbes];
}

/* ============================ run ============================ */
echo "Experiment 2 — index bake-off (PHP " . \PHP_VERSION . ", xxh3=" . ($hasXxh ? 'yes' : 'no') . ")\n";
echo "N=" . \number_format($N) . " lookups=" . \number_format($LOOKUPS)
    . " bucket=$B keyRead={$keyRead}B  slotA={$SLOT_A}B lineB={$LINE_B}B\n";

$results = [];

foreach ($alphas as $alpha) {
    /* ---- open-linear-40 ---- */
    [$S, , $slotHash, $home, $insA] = buildOpenLinear($H, $alpha);
    $hitReads = [];
    $hitBytes = [];
    foreach ($H as $e => [$h, $fp]) {
        $idx = $h % $S;
        $reads = 0;
        for ($i = 0; ; $i++) {
            $slot = ($idx + $i) % $S;
            $reads++;                       // 40-byte slot read
            if (($slotHash[$slot] ?? null) === $h && $slot === $home[$e]) {
                break;                      // found (full-hash match, no FP)
            }
        }
        $hitReads[] = $reads;
        $hitBytes[] = $reads * 40 + $keyRead;   // + one key/block read on hit
    }
    $missReads = [];
    $missBytes = [];
    foreach (\array_slice($GLOBALS['missKeys'], 0, 20000) as $mk) {
        [$h] = hashKey($mk, $GLOBALS['hasXxh']);
        $idx = $h % $S;
        $reads = 0;
        for ($i = 0; ; $i++) {
            $slot = ($idx + $i) % $S;
            $reads++;
            if (!isset($slotHash[$slot])) {
                break;                      // empty slot ends probe (miss)
            }
        }
        $missReads[] = $reads;
        $missBytes[] = $reads * 40;          // no key read on miss
    }
    $dirBytesA = $S * 40;
    $results[] = buildRow('open-linear-40', $alpha, $dirBytesA, $N,
        dist($hitReads), dist($missReads), dist($hitBytes), dist($missBytes),
        dist($insA), 1.0);

    /* ---- bucket-fp ---- */
    [$Sb, , $buckets, $count, $homeB, $insB] = buildBucketFp($H, $alpha, $B);
    $hitReadsB = [];
    $hitBytesB = [];
    $keyReadsHit = [];
    foreach ($H as $e => [$h, $fp]) {
        $b = $h % $Sb;
        $bucketReads = 0;
        $keyReads = 0;
        $found = false;
        for ($i = 0; !$found; $i++) {
            $bi = ($b + $i) % $Sb;
            $bucketReads++;                  // one 64-byte bucket read
            foreach ($buckets[$bi] ?? [] as [$efp, $eh]) {
                if ($efp === $fp) {
                    $keyReads++;             // fingerprint match -> key read
                    if ($eh === $h && $bi === $homeB[$e]) {
                        $found = true;
                        break;
                    }
                }
            }
            if (!$found && ($count[$bi] ?? 0) < $B) {
                break; // open bucket: would have stopped (defensive; hit always found earlier)
            }
        }
        $hitReadsB[] = $bucketReads;
        $keyReadsHit[] = $keyReads;
        $hitBytesB[] = $bucketReads * $LINE_B + $keyReads * $keyRead;
    }
    $missReadsB = [];
    $missBytesB = [];
    foreach (\array_slice($GLOBALS['missKeys'], 0, 20000) as $mk) {
        [$h, $fp] = hashKey($mk, $GLOBALS['hasXxh']);
        $b = $h % $Sb;
        $bucketReads = 0;
        $keyReads = 0;
        for ($i = 0; ; $i++) {
            $bi = ($b + $i) % $Sb;
            $bucketReads++;
            foreach ($buckets[$bi] ?? [] as [$efp, $eh]) {
                if ($efp === $fp) {
                    $keyReads++;             // FP false positive -> wasted key read
                }
            }
            if (($count[$bi] ?? 0) < $B) {
                break;                       // non-full bucket ends probe (miss)
            }
        }
        $missReadsB[] = $bucketReads;
        $missBytesB[] = $bucketReads * $LINE_B + $keyReads * $keyRead;
    }
    $dirBytesB = $Sb * $LINE_B;
    $results[] = buildRow('bucket-fp', $alpha, $dirBytesB, $N,
        dist($hitReadsB), dist($missReadsB), dist($hitBytesB), dist($missBytesB),
        dist($insB), 1.0 + (\array_sum($keyReadsHit) / \max(1, \count($keyReadsHit)) - 1.0));
}

/* ---- table ---- */
function buildRow(string $name, float $alpha, int $dirBytes, int $n,
    array $hitR, array $missR, array $hitB, array $missB, array $ins, float $keyReadsHit): array
{
    return \compact('name', 'alpha', 'dirBytes', 'n', 'hitR', 'missR', 'hitB', 'missB', 'ins', 'keyReadsHit');
}

$f2 = static fn (float $v): string => \number_format($v, 2);
echo "\n";
\printf("%-16s %5s | %9s | %12s %12s | %12s %12s | %10s %10s | %9s\n",
    'index', 'α', 'B/entry', 'hit reads avg', 'hit reads p99', 'miss reads avg', 'miss reads p99',
    'hit bytes', 'miss bytes', 'ins p99');
echo \str_repeat('-', 124) . "\n";
foreach ($results as $r) {
    \printf("%-16s %5s | %9s | %12s %12s | %12s %12s | %10s %10s | %9s\n",
        $r['name'], $f2($r['alpha']),
        $f2($r['dirBytes'] / $r['n']),
        $f2($r['hitR']['avg']), $f2($r['hitR']['p99']),
        $f2($r['missR']['avg']), $f2($r['missR']['p99']),
        $f2($r['hitB']['avg']), $f2($r['missB']['avg']),
        $f2($r['ins']['p99']));
}
echo \str_repeat('-', 124) . "\n";
echo "Reads = region copies per lookup (40B slot for current; 64B bucket for bucket-fp). "
    . "Bytes = directory bytes + key reads (key read = {$keyRead}B).\n";
echo "bucket-fp key reads per hit (fingerprint FP cost): ";
foreach ($results as $r) {
    if ($r['name'] === 'bucket-fp') {
        echo $f2($r['alpha']) . "=>" . $f2($r['keyReadsHit']) . "  ";
    }
}
echo "\n";

/* artifact */
$dir = __DIR__ . '/baselines';
if (!\is_dir($dir)) {
    @\mkdir($dir, 0755, true);
}
$path = $dir . '/exp2-index-' . \gmdate('Ymd-His') . '.json';
\file_put_contents($path, \json_encode([
    'schema' => 'fast-exp2/1',
    'captured' => \gmdate('c'),
    'params' => \compact('N', 'LOOKUPS', 'B', 'keyRead', 'seed', 'alphas', 'hasXxh'),
    'results' => $results,
], \JSON_PRETTY_PRINT) . "\n");
echo "\nArtifact: $path\n";
echo "php peak memory: " . \number_format(\memory_get_peak_usage(true)) . " bytes\n";
