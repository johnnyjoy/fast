<?php declare(strict_types = 1);

/**
 * Phase 3 gate: mixed PHP / ext-fast interop on LAYOUT_PHP (compat) stores.
 *
 * Exit 0 on success, 1 on failure, 2 if ext-fast is required but missing.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

if (!\extension_loaded('fast')) {
    \fwrite(STDERR, "interop_php_ext requires ext-fast\n");
    exit(2);
}

$fail = static function (string $message): never {
    \fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
};

$phpBin = \PHP_BINARY;
$worker = __DIR__ . '/interop_worker.php';
$name = 'fast-interop-' . \getmypid() . '-' . \bin2hex(\random_bytes(4));

$runPhp = static function (string $action) use ($phpBin, $worker, $name, $fail): void {
    $cmd = \sprintf(
        'FAST_BACKEND=php %s %s %s %s 2>&1',
        \escapeshellarg($phpBin),
        \escapeshellarg($worker),
        \escapeshellarg($action),
        \escapeshellarg($name)
    );
    \passthru($cmd, $code);
    if ($code !== 0) {
        $fail("PHP worker $action exited $code");
    }
};

// PHP creates a compat-layout store (pure PHP is always LAYOUT_PHP).
$runPhp('write');

// ext-fast reads and extends the same store with compat enabled.
$ext = new \Fast(['name' => $name, 'compat' => true, 'capacity' => 1024, 'size' => 8 * 1024 * 1024, 'persistent' => true]);

if (($ext['from_php'] ?? null) !== 'hello-php') {
    $fail('ext read from_php failed');
}
if (($ext['count'] ?? null) !== 42) {
    $fail('ext read count failed');
}
if (!isset($ext['nested'])) {
    $fail('ext read nested missing');
}

$ext['from_ext'] = 'hello-ext';
$ext->close();

// PHP verifies ext writes.
$runPhp('read');

// ext destroys via compat.
$ext2 = new \Fast(['name' => $name, 'compat' => true, 'persistent' => true]);
try {
    $ext2->destroy();
} catch (\Throwable $e) {
    $fail('ext destroy failed: ' . $e->getMessage());
}

// Store must be gone for PHP too.
$runPhp('gone');

echo "interop php/ext ok\n";
