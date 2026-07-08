<?php declare(strict_types = 1);

/**
 * Phase 7 gate: cross-engine verification bundle (ext-fast required).
 *
 * Runs interop, order-log invariant, and striped churn checks in one pass.
 * Individual tests are also invoked by tests/run.php; this script is the
 * single pre-merge gate documented in the remediation plan.
 *
 * Exit 0 on success, 1 on failure, 2 if ext-fast is unavailable.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

if (!\extension_loaded('fast')) {
    $extSo = \getenv('FAST_EXT_SO') ?: \dirname(__DIR__) . '/ext/fast/modules/fast.so';
    if (!\is_file($extSo)) {
        \fwrite(STDERR, "cross_engine_gate requires ext-fast (build ext/fast or set FAST_EXT_SO)\n");
        exit(2);
    }
}

$fail = static function (string $message): never {
    \fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
};

$phpBin = \PHP_BINARY;
$extSo = \getenv('FAST_EXT_SO') ?: \dirname(__DIR__) . '/ext/fast/modules/fast.so';
$phpExt = \is_file($extSo)
    ? 'php -d extension=' . \escapeshellarg($extSo)
    : 'php';

$run = static function (string $script) use ($phpExt, $fail): void {
    $path = __DIR__ . '/' . $script;
    \passthru($phpExt . ' ' . \escapeshellarg($path) . ' 2>&1', $code);
    if ($code !== 0) {
        $fail($script . ' exited ' . $code);
    }
};

$gates = [
    'interop_php_ext.php',
    'interop_native_ext.php',
    'order_log_bound.php',
    'striped_order_churn.php',
    'crash_recovery.php',
    'crash_recovery_fork.php',
];

foreach ($gates as $gate) {
    echo '=== cross-engine: ' . $gate . ' ===' . PHP_EOL;
    $run($gate);
}

echo 'cross engine gate ok' . PHP_EOL;
