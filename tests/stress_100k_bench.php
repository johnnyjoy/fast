<?php declare(strict_types = 1);

/**
 * \Fast burn-in stress benchmark — SHARED mode.
 *
 * Canonical workload: insert/read/update/delete/reinsert/mixed phases with a PHP-side
 * mirror validating read-after-write, deletes, tombstones, count, and missing-key
 * behavior. Uses the PUBLIC Fast API only (ArrayAccess + count).
 *
 * Manual (*_bench.php => skipped by run.php). Exit code reflects CORRECTNESS
 * (errors/warnings) only, never speed. Arena exhaustion is fail-soft: it is a
 * measured result (the no-reuse/no-shrink debt), recorded and reported, not a crash.
 *
 * Usage: php tests/stress_100k_bench.php [n=100000] [churn=500000] [size=256MiB] [seed=1]
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

$n     = (int) ($argv[1] ?? 100000);
$churn = (int) ($argv[2] ?? 500000);
$size  = (int) ($argv[3] ?? (256 * 1024 * 1024));
$seed  = (int) ($argv[4] ?? 1);

\mt_srand($seed);

$errors   = 0;
$warnings = 0;
$firstMsgs = [];

\set_error_handler(static function (int $errno, string $errstr) use (&$warnings, &$firstMsgs): bool {
    // Respect the @ operator: a probe like @shmop_open() on a not-yet-created
    // segment is an expected, suppressed failure, not a leaked warning.
    if (!(\error_reporting() & $errno)) {
        return false;
    }

    if ($errno === \E_WARNING || $errno === \E_USER_WARNING) {
        $warnings++;
        if (\count($firstMsgs) < 8) {
            $firstMsgs[] = $errstr;
        }
    }

    return false;
});

$fail = static function (string $msg) use (&$errors, &$firstMsgs): void {
    $errors++;
    if (\count($firstMsgs) < 8) {
        $firstMsgs[] = $msg;
    }
};

$payloads = [0, 32, 96, 300, 1200, 4096];

$makeValue = static function (string $key, int $marker, int $payloadLen) {
    $v = ['k' => $key, 'm' => $marker, 'flag' => ($marker & 1) === 1];
    if ($payloadLen > 0) {
        $v['p'] = \str_repeat(\chr(65 + ($marker % 26)), $payloadLen);
    }

    return $v;
};

$totals = ['tx' => 0, 'sec' => 0.0];

$report = static function (string $name, int $tx, int $start, int $end) use (&$totals): void {
    $sec = ($end - $start) / 1_000_000_000;
    $tps = $sec > 0 ? $tx / $sec : 0.0;
    $us  = $tx > 0 ? ($sec * 1_000_000) / $tx : 0.0;

    $totals['tx'] += $tx;
    $totals['sec'] += $sec;

    \printf("%-26s %10d  %8.3f  %12.0f  %9.2f\n", $name, $tx, $sec, $tps, $us);
};

// directory slots must be a power of two sized for ~0.6 load factor
$cap = 1024;
while ($cap < (int) \ceil($n / 0.6)) {
    $cap <<= 1;
}

$name = 'fast-stress-' . \getmypid();

// clean any stale segment of this name, then a fresh one
try {
    $stale = new \Fast(['name' => $name, 'capacity' => $cap, 'size' => $size]);
    $stale->destroy();
    unset($stale);
} catch (\Throwable $e) {
    // no stale segment / not destroyable — fine
}

$shm = new \Fast(['name' => $name, 'capacity' => $cap, 'size' => $size]);

\printf("Fast shared stress benchmark\n");
\printf("records:       %s\n", \number_format($n));
\printf("churn ops:     %s\n", \number_format($churn));
\printf("segment bytes: %s\n", \number_format($size));
\printf("dir slots:     %s\n", \number_format($cap));
\printf("seed:          %d\n\n", $seed);

\printf("%-26s %10s  %8s  %12s  %9s\n", 'phase', 'tx', 'sec', 'tx/sec', 'us/tx');
\printf("%'-70s\n", '');

$live = [];
$mark = 0;

try {

// --- phase 1: insert n ---
$start = \hrtime(true);
for ($i = 0; $i < $n; $i++) {
    $k = "user:$i";
    $mark++;
    $shm[$k] = $makeValue($k, $mark, 0);
    $live[$k] = $mark;
}
$report('insert', $n, $start, \hrtime(true));

if (\count($shm) !== $n) {
    $fail('count after insert expected ' . $n . ', got ' . \count($shm));
}

// --- phase 2: sequential read-back + validate ---
$start = \hrtime(true);
for ($i = 0; $i < $n; $i++) {
    $k = "user:$i";
    $v = $shm[$k];
    if (!\is_array($v) || $v['k'] !== $k || $v['m'] !== $live[$k]) {
        $fail('bad read ' . $k);
    }
}
$report('read', $n, $start, \hrtime(true));

// --- phase 3: update same-size ---
$start = \hrtime(true);
for ($i = 0; $i < $n; $i++) {
    $k = "user:$i";
    $mark++;
    $shm[$k] = $makeValue($k, $mark, 0);
    $live[$k] = $mark;
}
$report('update same-size', $n, $start, \hrtime(true));

// --- phase 4: update larger (size-class jumps) ---
$start = \hrtime(true);
for ($i = 0; $i < $n; $i++) {
    $k = "user:$i";
    $mark++;
    $shm[$k] = $makeValue($k, $mark, $payloads[$i % \count($payloads)]);
    $live[$k] = $mark;
}
$report('update larger', $n, $start, \hrtime(true));

// --- phase 5: delete half ---
$deleteN = \intdiv($n, 2);

$start = \hrtime(true);
for ($i = 0; $i < $deleteN; $i++) {
    $k = "user:$i";
    unset($shm[$k]);
    unset($live[$k]);
}
$report('delete', $deleteN, $start, \hrtime(true));

if (\count($shm) !== $n - $deleteN) {
    $fail('count after delete expected ' . ($n - $deleteN) . ', got ' . \count($shm));
}

// --- phase 6: reinsert (forces reclaimed-id/space reuse) ---
$start = \hrtime(true);
for ($i = 0; $i < $deleteN; $i++) {
    $k = "new:$i";
    $mark++;
    $shm[$k] = $makeValue($k, $mark, 0);
    $live[$k] = $mark;
}
$report('reinsert', $deleteN, $start, \hrtime(true));

if (\count($shm) !== $n) {
    $fail('count after reinsert expected ' . $n . ', got ' . \count($shm));
}

// --- phase 7: reuse verification ---
for ($i = 0; $i < $deleteN; $i++) {
    if (isset($shm["user:$i"])) {
        $fail('deleted key still present user:' . $i);
    }
    $k = "new:$i";
    $v = $shm[$k];
    if (!\is_array($v) || $v['k'] !== $k || $v['m'] !== $live[$k]) {
        $fail('reused wrong value ' . $k);
    }
}

// --- phase 8: stored null/false/0/'' vs missing ---
$shm['z:null']  = null;
$shm['z:false'] = false;
$shm['z:zero']  = 0;
$shm['z:empty'] = '';

// Read directly, NOT via ??: the null-coalescing operator returns its default
// whenever the left side is null, so it can never observe a stored null. The
// Fast contract is: a stored null reads back as null, and isset() is false.
if (isset($shm['z:null']) || $shm['z:null'] !== null) {
    $fail('stored null must read back as null (isset() false by contract)');
}
if ($shm['z:false'] !== false || $shm['z:zero'] !== 0 || $shm['z:empty'] !== '') {
    $fail('stored false/0/"" must read back exactly');
}
if (isset($shm['z:never']) || ($shm['z:never'] ?? 'dflt') !== 'dflt') {
    $fail('never-set key must be missing and yield the default');
}
unset($shm['z:null'], $shm['z:false'], $shm['z:zero'], $shm['z:empty']);

// --- phase 9: random write-heavy churn (mirror-validated) ---
$universe = \array_keys($live);
for ($g = 0; $g < 256; $g++) {
    $universe[] = "ghost:$g";
}
$uMax = \count($universe) - 1;

$start = \hrtime(true);
for ($op = 0; $op < $churn; $op++) {
    $r = \mt_rand(1, 100);
    $k = $universe[\mt_rand(0, $uMax)];

    if ($r <= 60) {
        $mark++;
        $shm[$k] = $makeValue($k, $mark, $payloads[$op % \count($payloads)]);
        $live[$k] = $mark;
    } elseif ($r <= 80) {
        $v = $shm[$k] ?? null;
        if (isset($live[$k])) {
            if (!\is_array($v) || $v['k'] !== $k || $v['m'] !== $live[$k]) {
                $fail('churn get mismatch ' . $k);
            }
        } elseif ($v !== null) {
            $fail('churn get on missing returned non-null ' . $k);
        }
    } elseif ($r <= 90) {
        unset($shm[$k]);
        unset($live[$k]);
    } else {
        if (isset($shm[$k]) !== isset($live[$k])) {
            $fail('churn isset mismatch ' . $k);
        }
    }
}
$report('mixed write-heavy', $churn, $start, \hrtime(true));

// --- phase 10: final correctness scan ---
if (\count($shm) !== \count($live)) {
    $fail('final count expected ' . \count($live) . ', got ' . \count($shm));
}
foreach ($live as $k => $m) {
    $v = $shm[$k];
    if (!\is_array($v) || $v['k'] !== $k || $v['m'] !== $m) {
        $fail('final scan wrong value ' . $k);
    }
}
for ($g = 0; $g < 256; $g++) {
    $k = "ghost:$g";
    if (isset($shm[$k]) !== isset($live[$k])) {
        $fail('ghost key presence mismatch ' . $k);
    }
}

} catch (\Throwable $e) {
    $fail('aborted (' . \get_class($e) . '): ' . $e->getMessage());
}

$tps = $totals['sec'] > 0 ? $totals['tx'] / $totals['sec'] : 0.0;
$us  = $totals['tx'] > 0 ? ($totals['sec'] * 1_000_000) / $totals['tx'] : 0.0;
\printf("\n%-26s %10d  %8.3f  %12.0f  %9.2f\n", 'TOTAL', $totals['tx'], $totals['sec'], $tps, $us);

$mirror   = \count($live);
$shmCount = \count($shm);
$lost     = 0;
foreach ($live as $k => $m) {
    if (!isset($shm[$k])) {
        $lost++;
    }
}
$orphans = $shmCount - ($mirror - $lost);

if ($lost !== 0) {
    $fail('DATA LOSS: ' . $lost . ' live key(s) missing from the segment');
}
if ($orphans !== 0) {
    $fail('ORPHANS: ' . $orphans . ' segment entr(ies) absent from the mirror');
}

\printf("\ninserted (phase 1):    %s\n", \number_format($n));
\printf("live after churn:      %s\n", \number_format($mirror));
\printf("segment count:         %s\n", \number_format($shmCount));
\printf("records lost:          %d\n", $lost);
\printf("orphan records:        %d\n", $orphans);
\printf("errors:                %d\n", $errors);
\printf("warnings:              %d\n", $warnings);
\printf("php peak memory:       %s bytes\n", \number_format(\memory_get_peak_usage(true)));

if ($firstMsgs !== []) {
    \printf("\nfirst issues:\n");
    foreach ($firstMsgs as $m) {
        \printf("  - %s\n", $m);
    }
}

try {
    $shm->destroy();
} catch (\Throwable $e) {
    // already reclaimed
}
\restore_error_handler();

exit($errors === 0 && $warnings === 0 ? 0 : 1);
