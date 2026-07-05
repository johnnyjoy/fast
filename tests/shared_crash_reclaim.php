<?php declare(strict_types = 1);

/**
 * Contract test: Shared Crash Reclaim.
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
 * Growth-segment crash reclaim (docs/archive/efficiency-audit.md).
 *
 * The audit found that growth shared-memory segments could be orphaned after a
 * crash / kill -9: deleteSharedSegments() only touched the handles the dying
 * process held, the stale link counter blocked any later reclaim, and the kernel
 * never reclaimed them because the segments were not marked for removal. This
 * proves the actual bug class is fixed:
 *
 *   1. A child creates a NON-persistent store and writes enough to allocate
 *      growth segments beyond segment 0.
 *   2. The child is killed with SIGKILL — no cleanup code runs.
 *   3. The orphaned growth segments are still present (the leak condition).
 *   4. Reopening the store by name detects the dead owner (PID-table sweep),
 *      reclaims the crash debris, and presents a fresh empty store.
 *   5. A final destroy reclaims every segment — root and growth alike.
 */

if (!\function_exists('pcntl_fork')) {
    fwrite(STDERR, 'skip: pcntl is required for the crash reclaim test' . PHP_EOL);
    exit(77);
}
if (!\function_exists('posix_kill')) {
    fwrite(STDERR, 'skip: posix is required for the crash reclaim test' . PHP_EOL);
    exit(77);
}

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

// Small segments so a modest payload forces growth past segment 0 quickly.
$name = 'fast-store-crash-' . \getmypid();
$size = 65536;
$capacity = 64;

// Best-effort: clear any stale debris from a previous aborted run.
try {
    (new \Fast(['name' => $name, 'capacity' => $capacity, 'size' => $size]))->destroy();
} catch (\Throwable) {
    // ignore
}

$pid = \pcntl_fork();
if ($pid === -1) {
    $fail('fork failed');
}

if ($pid === 0) {
    // Child: create a NON-persistent store and grow it past one segment.
    $child = new \Fast(['name' => $name, 'capacity' => $capacity, 'size' => $size]);
    $blob = \str_repeat('Z', 4000);
    for ($i = 0; $i < 60; $i++) {
        $child['k' . $i] = $blob . $i;
    }
    // Hang around (attached) until the parent kills us. No cleanup will run.
    \sleep(30);
    exit(0);
}

// Parent: wait until the child has allocated growth segments.
$grew = false;
for ($i = 0; $i < 500; $i++) {
    if (fast_test_shared_segment_count($name) >= 2) {
        $grew = true;
        break;
    }
    \usleep(10000);
}

if (!$grew) {
    \posix_kill($pid, \SIGKILL);
    \pcntl_waitpid($pid, $status);
    $fail('child never allocated growth segments — cannot exercise the leak path');
}

$segmentsBefore = fast_test_shared_segment_count($name);
if (!fast_test_shared_segment_exists($name, 1)) {
    \posix_kill($pid, \SIGKILL);
    \pcntl_waitpid($pid, $status);
    $fail('expected at least one growth segment before the crash');
}

// Kill the child uncleanly and reap the zombie so its PID is truly gone.
\posix_kill($pid, \SIGKILL);
\pcntl_waitpid($pid, $status);
if (!\pcntl_wifsignaled($status)) {
    $fail('child was expected to die from SIGKILL');
}

// The crash ran no cleanup, so the growth segments are still present right now:
// this is exactly the orphan/leak condition the fix must resolve.
if (!fast_test_shared_segment_exists($name, 1)) {
    $fail('growth segment should still exist immediately after kill -9 (no cleanup ran)');
}

// Reopen by name: the dead owner is swept from the PID table, the orphaned
// non-persistent store is reclaimed, and a fresh empty store is created.
$revived = new \Fast(['name' => $name, 'capacity' => $capacity, 'size' => $size]);

if ($revived->count() !== 0) {
    $fail('reopened non-persistent store after crash must be fresh/empty, got count=' . $revived->count());
}

if (fast_test_shared_segment_exists($name, $segmentsBefore - 1) && $segmentsBefore > 1) {
    // The highest pre-crash growth segment must have been reclaimed (the fresh
    // store has not grown there yet).
    $fail('crash growth segment #' . ($segmentsBefore - 1) . ' was not reclaimed on reopen');
}

// The fresh store is fully usable.
$revived['fresh'] = 'ok';
if ($revived['fresh'] !== 'ok') {
    $fail('reopened store must be writable');
}

// Destroy reclaims every segment — root and any growth — not just local handles.
$revived->destroy();

if (fast_test_shared_segment_exists($name, 0)) {
    $fail('root segment must be gone after destroy');
}
if (fast_test_shared_segment_exists($name, 1)) {
    $fail('growth segment must be gone after destroy');
}

echo 'shared crash reclaim ok (pre-crash segments=' . $segmentsBefore . ')' . PHP_EOL;
