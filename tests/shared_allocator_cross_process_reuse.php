<?php declare(strict_types = 1);

/**
 * Contract test: Shared Allocator Cross Process Reuse.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/engine_access.php';

use \Fast;

// Cross-process free-list reuse: the allocator's free list lives entirely in shared
// memory (heads in the header, links in the arena), so a block freed by one process
// must be reusable by a different, freshly-attached process. We prove it
// behaviorally: many fresh child processes each write a large value and delete it.
// If freed space were NOT reused, the arena would grow without bound and the store
// would spill into many segments; with reuse it stays tightly bounded.

if (!\function_exists('pcntl_fork')) {
    fwrite(STDERR, "skip: pcntl is required for cross-process reuse test\n");
    exit(77);
}

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$name = 'fast-store-xproc-reuse-' . \bin2hex(\random_bytes(8));
$size = 8388608;          // 8 MiB segment
$bigLen = 300000;         // each cycle (re)allocates ~300 KB
$cycles = 60;             // ~18 MB churned: must NOT grow the arena if reused

try {
    (new \Fast(['name' => $name, 'capacity' => 1024, 'size' => $size]))->destroy();
} catch (\Throwable) {
    // ignore
}

// Persistent parent handle keeps the store alive while child processes come and go.
$owner = new \Fast(['name' => $name, 'capacity' => 1024, 'size' => $size, 'persistent' => true]);

for ($c = 0; $c < $cycles; $c++) {
    $pid = \pcntl_fork();
    if ($pid === -1) {
        $fail('fork failed at cycle ' . $c);
    }
    if ($pid === 0) {
        $child = new \Fast($name);
        $child['payload'] = \str_repeat('A', $bigLen);
        if ($child['payload'] !== \str_repeat('A', $bigLen)) {
            fwrite(STDERR, "child read-back mismatch\n");
            exit(2);
        }
        unset($child['payload']); // free the block back into the shared free list
        $child->close();
        exit(0);
    }
    \pcntl_waitpid($pid, $status);
    if (!\pcntl_wifexited($status) || \pcntl_wexitstatus($status) !== 0) {
        $fail('child cycle ' . $c . ' failed (code ' . \pcntl_wexitstatus($status) . ')');
    }
}

// With cross-process reuse the ~18 MB of churn is served by recycling one freed
// block, so the store stays within a small, bounded number of segments. Without
// reuse it would have grown to several. Allow a little slack for directory/order
// regions and class rounding.
$segments = fast_test_shared_segment_count($name);
if ($segments > 2) {
    $fail('arena grew to ' . $segments . ' segments under reuse churn — freed blocks were not reused across processes');
}

// One more fresh process must still be able to allocate and read a value that fits
// the recycled block.
$pid = \pcntl_fork();
if ($pid === -1) {
    $fail('final fork failed');
}
if ($pid === 0) {
    $b = new \Fast($name);
    $b['payload'] = \str_repeat('B', 200000);
    $ok = $b['payload'] === \str_repeat('B', 200000);
    $b->close();
    exit($ok ? 0 : 3);
}
\pcntl_waitpid($pid, $status);
if (!\pcntl_wifexited($status) || \pcntl_wexitstatus($status) !== 0) {
    $fail('final process failed to reuse freed shared block (code ' . \pcntl_wexitstatus($status) . ')');
}

if ($owner['payload'] !== \str_repeat('B', 200000)) {
    $fail('final value not readable after cross-process reuse');
}

$owner->destroy();

echo 'shared allocator cross-process reuse ok (segments=' . $segments . ')' . PHP_EOL;
