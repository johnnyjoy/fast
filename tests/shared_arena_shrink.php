<?php declare(strict_types = 1);

/**
 * Contract test: Shared Arena Shrink.
 *
 * After a peak allocation and delete, compact() must repack live data and return
 * unused arena space (native ext: ftruncate mmap; PHP: drop trailing segments).
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/engine_access.php';

use \Fast;

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$name = 'fast-store-arena-shrink-' . \bin2hex(\random_bytes(8));
$size = 8388608; // 8 MiB initial mmap / segment
$bigLen = 5000000; // ~5 MiB payload forces arena growth past the initial mapping

try {
    (new \Fast(['name' => $name, 'capacity' => 1024, 'size' => $size]))->destroy();
} catch (\Throwable) {
    // best-effort cleanup only
}

$store = new \Fast(['name' => $name, 'capacity' => 1024, 'size' => $size]);
$store['anchor'] = 'keep';
$store['big'] = \str_repeat('Z', $bigLen);

$peakFrontier = fast_test_frontier($name);
if ($peakFrontier === null || $peakFrontier <= $size / 2) {
    $fail('expected arena frontier to advance under a large write');
}

$peakNativeSize = fast_test_native_shm_size($name);
if (\extension_loaded('fast') && ($peakNativeSize === null || $peakNativeSize <= $size)) {
    $fail('expected native mmap to grow beyond the initial ' . $size . ' bytes');
}

unset($store['big']);

fast_test_compact($store);

if ($store['anchor'] !== 'keep') {
    $fail('live anchor value lost after compact()');
}
if (\count($store) !== 1) {
    $fail('unexpected live count after compact(): ' . \count($store));
}

$afterFrontier = fast_test_frontier($name);
if ($afterFrontier === null || $afterFrontier >= $peakFrontier) {
    $fail('frontier did not recede after compact()');
}

if (\extension_loaded('fast')) {
    $afterNativeSize = fast_test_native_shm_size($name);
    if ($afterNativeSize === null || $afterNativeSize >= $peakNativeSize) {
        $fail('native mmap did not shrink after compact()');
    }
    if ($afterNativeSize > (int) ($size * 1.25)) {
        $fail('native mmap still well above initial size after compact(): ' . $afterNativeSize);
    }
} else {
    $segments = fast_test_shared_segment_count($name);
    if ($segments > 2) {
        $fail('trailing shared segments not reclaimed after compact(): ' . $segments);
    }
}

$store->destroy();

echo 'shared arena shrink ok' . PHP_EOL;
