<?php declare(strict_types = 1);

/**
 * Contract test runner — executes every tests/*.php gate except opt-in benches.
 *
 * Environment:
 *   FAST_BACKEND=php  (default) — userland \\Fast via composer
 *   FAST_BACKEND=ext  — ext-fast must be loaded; runs full contract suite
 *
 * Exit 0 when all invoked tests pass.
 */

$dir = __DIR__;
\chdir($dir);

$backend = \getenv('FAST_BACKEND') ?: 'php';

$extSo = \getenv('FAST_EXT_SO') ?: \dirname(__DIR__) . '/ext/fast/modules/fast.so';
$extAvailable = \extension_loaded('fast') || ($backend === 'ext' && \is_file($extSo));

if ($backend === 'ext' && !$extAvailable) {
    \fwrite(STDERR, "FAST_BACKEND=ext but ext-fast is not available (build ext/fast or set FAST_EXT_SO)\n");
    exit(2);
}

$failed = 0;
$files = \glob($dir . '/*.php') ?: [];
\sort($files);

$phpCmd = 'php';
if ($backend === 'ext' && \is_file($extSo)) {
    $phpCmd = 'php -d extension=' . \escapeshellarg($extSo);
}

$skip = [
    'run.php',
    'bootstrap.php',
    'index_matrix_lib.php',
    'ref_ops.php',
    'perf_track_gate.php',
    'stress_gate.php',
    'interop_worker.php',
];

/** @var list<string> run only with FAST_BACKEND=ext */
$extOnly = [
    'ext_native_smoke.php',
    'ext_compat_smoke.php',
    'ext_striped_smoke.php',
    'interop_php_ext.php',
];

foreach ($files as $file) {
    $base = \basename($file);

    if (\in_array($base, $skip, true) || \str_ends_with($base, '_bench.php')) {
        continue;
    }

    if ($backend !== 'ext' && \in_array($base, $extOnly, true)) {
        continue;
    }

    echo '=== ' . $base . ' ===' . PHP_EOL;
    \passthru($phpCmd . ' ' . \escapeshellarg($file), $code);

    if ($code !== 0) {
        $failed++;
        break;
    }
}

exit($failed > 0 ? 1 : 0);
