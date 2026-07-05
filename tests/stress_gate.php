<?php declare(strict_types = 1);

/**
 * \Fast stress GATE — opt-in speed regression check.
 *
 * Wraps stress_100k_bench.php (whose own exit code is correctness-only) and
 * turns it into a pass/fail speed gate WITH noise allowances, so a single jittery
 * run can never flip the result:
 *
 *   - Median of N runs, FIRST run discarded as warmup (JIT / cold pages).
 *   - The pinned baseline is a regression TRIPWIRE: dropping below it by more
 *     than the tolerance fails (or warns unless --strict).
 *   - Any correctness defect in ANY run (errors / warnings / lost / orphans) is an
 *     unconditional hard fail.
 *
 * Exit 0 = pass, 1 = fail.
 *
 * Usage:
 *   php tests/stress_gate.php [--runs=5] [--tol-total=8] [--tol-phase=15]
 *       [--baseline=PATH] [--records=100000] [--churn=500000] [--size=BYTES]
 *       [--seed=1] [--strict] [--json]
 */

namespace Fast;

$opts = [
    'runs'      => 5,
    'tol-total' => 8.0,
    'tol-phase' => 15.0,
    'baseline'  => __DIR__ . '/../research/baselines/stress-100k-baseline.json',
    'records'   => null,
    'churn'     => null,
    'size'      => null,
    'seed'      => null,
    'strict'    => false,
    'json'      => false,
];

foreach (\array_slice($argv, 1) as $arg) {
    if ($arg === '--strict') { $opts['strict'] = true; continue; }
    if ($arg === '--json')   { $opts['json']   = true; continue; }
    if (!\preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
        \fwrite(\STDERR, "unknown argument: $arg\n");
        exit(2);
    }
    [$k, $v] = [$m[1], $m[2]];
    if (!\array_key_exists($k, $opts)) {
        \fwrite(\STDERR, "unknown option: --$k\n");
        exit(2);
    }
    $opts[$k] = \in_array($k, ['tol-total', 'tol-phase'], true) ? (float) $v : (int) $v;
}

$baselinePath = (string) $opts['baseline'];
$baseline = \json_decode((string) @\file_get_contents($baselinePath), true);
if (!\is_array($baseline) || !isset($baseline['phases'], $baseline['total'])) {
    \fwrite(\STDERR, "cannot read baseline: $baselinePath\n");
    exit(2);
}

// Compare apples-to-apples: default the run geometry to the baseline's config and
// refuse to silently compare across a different shape.
$cfg = $baseline['config'] ?? [];
$records = $opts['records'] ?? (int) ($cfg['records'] ?? 100000);
$churn   = $opts['churn']   ?? (int) ($cfg['churn_ops'] ?? 500000);
$size    = $opts['size']    ?? (int) ($cfg['segment_bytes'] ?? (256 * 1024 * 1024));
$seed    = $opts['seed']    ?? (int) ($cfg['seed'] ?? 1);

$shapeDrift = $records !== (int) ($cfg['records'] ?? $records)
    || $churn !== (int) ($cfg['churn_ops'] ?? $churn)
    || $size !== (int) ($cfg['segment_bytes'] ?? $size)
    || $seed !== (int) ($cfg['seed'] ?? $seed);

// Bench phase label -> baseline JSON key.
$phaseKey = [
    'insert'            => 'insert',
    'read'              => 'read',
    'update same-size'  => 'update_same_size',
    'update larger'     => 'update_larger',
    'delete'            => 'delete',
    'reinsert'          => 'reinsert',
    'mixed write-heavy' => 'mixed_write_heavy',
];

/** @return float Baseline tx/sec for a phase node (supports legacy field names). */
$baselineTx = static function (array $node): float {
    return (float) ($node['tx_per_sec'] ?? $node['fast_tx_per_sec'] ?? 0);
};

/** Parse one bench run's stdout into per-phase tx/sec + integrity + peak. */
$parseRun = static function (string $out) use ($phaseKey): array {
    $phases = [];
    foreach (\explode("\n", $out) as $line) {
        $line = \rtrim($line);
        // name (possibly multi-word) + tx + sec + tx/sec + us/tx
        if (\preg_match('/^(\S.*?)\s+(\d+)\s+([\d.]+)\s+(\d+)\s+([\d.]+)$/', $line, $m)) {
            $label = \trim($m[1]);
            if ($label === 'TOTAL') {
                $phases['total'] = (int) $m[4];
            } elseif (isset($phaseKey[$label])) {
                $phases[$phaseKey[$label]] = (int) $m[4];
            }
        }
    }
    $grab = static function (string $re, string $out): int {
        return \preg_match($re, $out, $m) ? (int) \str_replace(',', '', $m[1]) : -1;
    };

    return [
        'phases'   => $phases,
        'lost'     => $grab('/records lost:\s+([\d,]+)/', $out),
        'orphans'  => $grab('/orphan records:\s+([\d,]+)/', $out),
        'errors'   => $grab('/errors:\s+([\d,]+)/', $out),
        'warnings' => $grab('/warnings:\s+([\d,]+)/', $out),
        'peak'     => $grab('/php peak memory:\s+([\d,]+) bytes/', $out),
    ];
};

$median = static function (array $xs): float {
    \sort($xs);
    $n = \count($xs);
    if ($n === 0) { return 0.0; }
    $mid = \intdiv($n, 2);

    return $n % 2 ? (float) $xs[$mid] : ($xs[$mid - 1] + $xs[$mid]) / 2.0;
};

$bench = __DIR__ . '/stress_100k_bench.php';
$runs  = \max(1, (int) $opts['runs']);

\fwrite(\STDERR, \sprintf(
    "stress gate: %d runs (1 warmup discarded), records=%s churn=%s size=%s seed=%d\n",
    $runs, \number_format($records), \number_format($churn), \number_format($size), $seed
));
if ($shapeDrift) {
    \fwrite(\STDERR, "WARNING: run geometry differs from baseline config — comparison is not apples-to-apples.\n");
}

$results = [];
$correctnessFail = null;
for ($r = 0; $r < $runs; $r++) {
    $cmd = \sprintf(
        '%s %s %d %d %d %d 2>&1',
        \escapeshellarg(\PHP_BINARY),
        \escapeshellarg($bench),
        $records, $churn, $size, $seed
    );
    \exec($cmd, $lines, $rc);
    $out = \implode("\n", $lines);
    $lines = [];
    $run = $parseRun($out);

    $defects = [];
    if ($run['errors']   > 0) { $defects[] = "errors={$run['errors']}"; }
    if ($run['warnings'] > 0) { $defects[] = "warnings={$run['warnings']}"; }
    if ($run['lost']     > 0) { $defects[] = "lost={$run['lost']}"; }
    if ($run['orphans']  > 0) { $defects[] = "orphans={$run['orphans']}"; }
    if ($rc !== 0)            { $defects[] = "exit=$rc"; }
    if ($defects !== [] && $correctnessFail === null) {
        $correctnessFail = "run " . ($r + 1) . ": " . \implode(' ', $defects);
    }

    $tag = $r === 0 ? 'warmup' : 'measure';
    \fwrite(\STDERR, \sprintf(
        "  run %d/%d [%s] total=%s tx/sec%s\n",
        $r + 1, $runs, $tag,
        \number_format($run['phases']['total'] ?? 0),
        $defects === [] ? '' : '  DEFECT: ' . \implode(' ', $defects)
    ));
    $results[] = $run;
}

// Drop the warmup run (first) when we have more than one sample.
$measured = \count($results) > 1 ? \array_slice($results, 1) : $results;

// Median per phase across measured runs.
$phaseKeys = \array_merge(\array_values($phaseKey), ['total']);
$medians = [];
foreach ($phaseKeys as $key) {
    $vals = [];
    foreach ($measured as $run) {
        if (isset($run['phases'][$key])) { $vals[] = $run['phases'][$key]; }
    }
    $medians[$key] = $median($vals);
}

$rows = [];
$regressed = false;

foreach ($phaseKeys as $key) {
    $isTotal = $key === 'total';
    $node = $isTotal ? $baseline['total'] : $baseline['phases'][$key];
    $ref  = $baselineTx($node);
    $tol  = $isTotal ? (float) $opts['tol-total'] : (float) $opts['tol-phase'];
    $floor = $ref * (1 - $tol / 100);
    $got   = $medians[$key];

    if ($got < $floor) {
        $status = 'FAIL';
        $regressed = true;
    } else {
        $status = 'OK';
    }

    $rows[] = [
        'phase'  => $key,
        'median' => $got,
        'ref'    => $ref,
        'floor'  => $floor,
        'status' => $status,
    ];
}

if ($opts['json']) {
    echo \json_encode([
        'runs'       => $runs,
        'measured'   => \count($measured),
        'rows'       => $rows,
        'shape_drift'=> $shapeDrift,
        'correctness_fail' => $correctnessFail,
        'pass'       => !$regressed && $correctnessFail === null,
    ], \JSON_PRETTY_PRINT) . "\n";
} else {
    \printf("\n%-20s %14s %14s %14s  %s\n", 'phase', 'median tx/s', 'baseline', 'floor', 'status');
    \printf("%'-82s\n", '');
    foreach ($rows as $row) {
        \printf(
            "%-20s %14s %14s %14s  %s\n",
            $row['phase'],
            \number_format($row['median']),
            \number_format($row['ref']),
            \number_format($row['floor']),
            $row['status'] === 'OK' ? 'OK' : 'FAIL (below baseline floor)'
        );
    }
    echo "\n";
}

if ($correctnessFail !== null) {
    \fwrite(\STDERR, "GATE FAIL — correctness defect: $correctnessFail\n");
    exit(1);
}
if ($regressed) {
    $msg = $opts['strict']
        ? "GATE FAIL — regressed past tolerance vs the pinned baseline.\n"
        : "GATE FAIL — regressed past tolerance vs the pinned baseline.\n";
    \fwrite(\STDERR, $msg);
    exit(1);
}

\fwrite(\STDERR, "GATE PASS — holds the pinned baseline within tolerance.\n");
exit(0);
