<?php declare(strict_types = 1);

/**
 * Contract test: Shared Growth Lifecycle.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/engine_access.php';

use \Fast;

use function Fast\fast_test_shared_segment_exists;
use function Fast\fast_test_shared_segment_count;

/**
 * Growth-segment lifecycle (docs/design-law.md, docs/archive/efficiency-audit.md).
 *
 * Stores that grow beyond segment 0 must follow the same reclaim contract as
 * single-segment stores, across EVERY segment — not just the handles a process
 * happens to hold:
 *
 *   - non-persistent: a clean final close reclaims all growth segments;
 *   - persistent:     growth segments survive a close and reopen with their data,
 *                     and destroy() reclaims them.
 */

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

if (\extension_loaded('fast')) {
    echo 'shared growth lifecycle ok (skipped: ext-native grows via mmap, not multi-segment shmop)' . PHP_EOL;
    exit(0);
}

// Small segments so a modest payload forces growth past segment 0.
$size = 65536;
$capacity = 64;
$blob = \str_repeat('Q', 4000);

// ---- Non-persistent: final close reclaims every growth segment --------------
$npName = 'fast-store-growth-np-' . \bin2hex(\random_bytes(6));
$np = new \Fast(['name' => $npName, 'capacity' => $capacity, 'size' => $size]);
for ($i = 0; $i < 60; $i++) {
    $np['k' . $i] = $blob . $i;
}

if (fast_test_shared_segment_count($npName) < 2) {
    $np->destroy();
    $fail('non-persistent store should have grown past one segment');
}

// Drop the only handle: last one out, non-persistent => reclaim all segments.
unset($np);

if (fast_test_shared_segment_exists($npName, 0)) {
    $fail('non-persistent root segment must be gone after final close');
}
if (fast_test_shared_segment_exists($npName, 1)) {
    $fail('non-persistent growth segment must be gone after final close');
}

// ---- Persistent: growth survives close, reopens, and destroy reclaims -------
$pName = 'fast-store-growth-p-' . \bin2hex(\random_bytes(6));
$writer = new \Fast(['name' => $pName, 'capacity' => $capacity, 'size' => $size, 'persistent' => true]);
for ($i = 0; $i < 60; $i++) {
    $writer['k' . $i] = $blob . $i;
}
$grownCount = fast_test_shared_segment_count($pName);
if ($grownCount < 2) {
    $writer->destroy();
    $fail('persistent store should have grown past one segment');
}
$expectedLast = $blob . '59';
$writer->close();

// Persistent store and all its growth segments survive the last close.
if (fast_test_shared_segment_count($pName) !== $grownCount) {
    $fail('persistent store must keep all growth segments after close');
}

$reader = new \Fast(['name' => $pName, 'capacity' => $capacity, 'size' => $size, 'persistent' => true]);
if ($reader->count() !== 60) {
    $fail('persistent reopened store lost entries, count=' . $reader->count());
}
// k59 lives in a growth segment the reader must attach by key.
if ($reader['k59'] !== $expectedLast) {
    $fail('persistent reopened store lost a value stored in a growth segment');
}

$reader->destroy();
if (fast_test_shared_segment_exists($pName, 0) || fast_test_shared_segment_exists($pName, 1)) {
    $fail('destroy() must reclaim every segment of a persistent grown store');
}

echo 'shared growth lifecycle ok (np grew, persistent grew to ' . $grownCount . ' segments)' . PHP_EOL;
