<?php declare(strict_types = 1);

/**
 * Contract test runner — executes every tests/*.php gate except opt-in benches.
 *
 * Exit 0 when all invoked tests pass.
 */

$dir = __DIR__;
\chdir($dir);

$failed = 0;
$files = \glob($dir . '/*.php') ?: [];
\sort($files);

$skip = [
    'run.php',
    'bootstrap.php',
    'index_matrix_lib.php',
    // PHP engine ref/compound diagnostic — emits warnings by design (manual: php ref_ops.php)
    'ref_ops.php',
    // Phase 6 perf verdict — not a functional gate
    'perf_track_gate.php',
    // Opt-in stress speed gate — runs the 100k bench 5x (~25s); not a functional gate
    'stress_gate.php',
];

foreach ($files as $file) {
    $base = \basename($file);

    if (\in_array($base, $skip, true) || \str_ends_with($base, '_bench.php')) {
        continue;
    }

    echo '=== ' . $base . ' ===' . PHP_EOL;
    \passthru('php ' . \escapeshellarg($file), $code);

    if ($code !== 0) {
        $failed++;
        break;
    }
}

exit($failed > 0 ? 1 : 0);
