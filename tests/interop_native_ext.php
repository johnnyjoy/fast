<?php declare(strict_types = 1);

/**
 * Phase 7 gate: native XFST store shared across ext-fast processes (write/read round-trip).
 *
 * Exit 0 on success, 1 on failure, 2 if ext-fast is missing.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

if (!\extension_loaded('fast')) {
    \fwrite(STDERR, "interop_native_ext requires ext-fast\n");
    exit(2);
}

$fail = static function (string $message): never {
    \fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
};

$phpBin = \PHP_BINARY;
$extSo = \getenv('FAST_EXT_SO') ?: \dirname(__DIR__) . '/ext/fast/modules/fast.so';
if (!\is_file($extSo)) {
    $fail('ext .so not found at ' . $extSo);
}

$worker = __DIR__ . '/interop_native_worker.php';
$name = 'fast-interop-native-' . \getmypid() . '-' . \bin2hex(\random_bytes(4));

$runWorker = static function (string $action) use ($phpBin, $extSo, $worker, $name, $fail): void {
    $cmd = \sprintf(
        '%s -d extension=%s %s %s %s 2>&1',
        \escapeshellarg($phpBin),
        \escapeshellarg($extSo),
        \escapeshellarg($worker),
        \escapeshellarg($action),
        \escapeshellarg($name)
    );
    \passthru($cmd, $code);
    if ($code !== 0) {
        $fail("native worker $action exited $code");
    }
};

try {
    (new \Fast(['name' => $name, 'capacity' => 1024, 'size' => 8 * 1024 * 1024]))->destroy();
} catch (\Throwable) {
}

$runWorker('write');

$parent = new \Fast(['name' => $name, 'persistent' => true]);
if (($parent['from_a'] ?? null) !== 'hello-a') {
    $fail('parent read from_a failed');
}
$parent['from_b'] = 'hello-b';
$parent->close();

$runWorker('read');

$done = new \Fast(['name' => $name, 'persistent' => true]);
$done->destroy();

echo "interop native ext ok\n";
