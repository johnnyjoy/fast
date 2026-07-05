<?php declare(strict_types = 1);

/**
 * Contract test: Platform Guard.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;
use Fast\Engine\Flat;

/**
 * L3 regression: shared mode hard-requires a 64-bit PHP build (the directory is
 * indexed with unpack('P', hash) & mask and the layout stores 64-bit fields). The
 * engine must REFUSE a 32-bit build at attach() with a clear error rather than
 * silently corrupting the directory.
 *
 * We cannot run a 32-bit interpreter here, so the refusal logic is proven via the
 * pure Flat::assertIntSize($size) helper (reflection): size 4 -> throws with a
 * clear message; size 8 -> accepted. We also confirm shared mode actually works on
 * this (64-bit) build, and that local mode has no such requirement.
 */

$fail = static function (string $m): never { \fwrite(\STDERR, $m . \PHP_EOL); exit(1); };

// This test harness itself only makes sense on a 64-bit build.
if (\PHP_INT_SIZE !== 8) {
    echo 'platform guard ok (skipped: not a 64-bit build)' . \PHP_EOL;
    exit(0);
}

$assertIntSize = new \ReflectionMethod(Flat::class, 'assertIntSize');
$assertIntSize->setAccessible(true);

// 32-bit (size 4) must be refused with a clear, typed error.
$threw = false;
try {
    $assertIntSize->invoke(null, 4);
} catch (\RuntimeException $e) {
    $threw = true;
    if (\stripos($e->getMessage(), '64-bit') === false) {
        $fail('refusal message should mention "64-bit", got: ' . $e->getMessage());
    }
    if (\strpos($e->getMessage(), '32-bit') === false) {
        $fail('refusal message should report the offending width "32-bit", got: ' . $e->getMessage());
    }
}
if (!$threw) {
    $fail('assertIntSize(4) must throw RuntimeException on a 32-bit width');
}

// 64-bit (size 8) must be accepted silently.
$assertIntSize->invoke(null, 8);

// And shared mode must actually work end-to-end on this 64-bit build.
if (\extension_loaded('shmop') && \extension_loaded('sysvsem')) {
    $name = 'fast-platform-' . \getmypid();
    try { (new \Fast($name))->destroy(); } catch (\Throwable) { /* best-effort */ }

    $store = new \Fast($name);
    $store['k'] = 123;
    if (($store['k'] ?? null) !== 123) {
        $fail('shared mode round-trip failed on a 64-bit build');
    }
    $store->destroy();
}

// Local mode never touches the 64-bit layout — it must work regardless.
$local = new \Fast([]);
$local['x'] = 'y';
if (($local['x'] ?? null) !== 'y') {
    $fail('local mode round-trip failed');
}

echo 'platform guard ok' . \PHP_EOL;
