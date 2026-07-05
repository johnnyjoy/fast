<?php declare(strict_types = 1);

/**
 * Contract test: Shared Segments.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/engine_access.php';

use \Fast;

// Multi-segment growth: when the value arena outgrows segment 0, the store grows
// into additional shmop segments transparently. A peer that attaches by name must
// read values living in the overflow segment(s), and destroy() must reclaim every
// segment (segment 0 and all growth segments).

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$name = 'fast-store-dynamic-' . \bin2hex(\random_bytes(8));
$size = 65536;          // small segments so a modest volume forces growth
$writer = new \Fast([
    'name' => $name,
    'capacity' => 64,
    'size' => $size,
]);
$writer['seed'] = 'ok';

// Each value (~4000 bytes) fits comfortably in one segment, but the aggregate far
// exceeds a single 64 KiB segment, forcing growth beyond segment 0.
$payload = \str_repeat('Z', 4000);
$count = 40;
for ($i = 0; $i < $count; $i++) {
    $writer['k' . $i] = $payload . $i;
}

if (fast_test_shared_segment_count($name) < 2) {
    $fail('shared mode should grow beyond one segment for a larger working set');
}

$reader = new \Fast(['name' => $name, 'capacity' => 64, 'size' => $size]);

if ($reader['seed'] !== 'ok') {
    $fail('attached reader lost the seed value');
}
if ($reader['k' . ($count - 1)] !== $payload . ($count - 1)) {
    $fail('attached reader lost a value stored in an overflow segment');
}
if ($reader->count() !== $count + 1) {
    $fail('attached reader count mismatch: expected ' . ($count + 1) . ', got ' . $reader->count());
}

$reader->destroy();

if (fast_test_shared_segment_exists($name, 1)) {
    $fail('overflow segment should be freed after destroy');
}
if (fast_test_shared_segment_exists($name, 0)) {
    $fail('base segment should be freed after destroy');
}

echo 'shared segments ok' . PHP_EOL;
