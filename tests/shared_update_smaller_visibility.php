<?php declare(strict_types = 1);

/**
 * Contract test: Shared Update Smaller Visibility.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$name = 'fast-store-upd-smaller-' . \getmypid();
try {
    $cleanup = new \Fast($name);
    $cleanup->destroy();
} catch (\Throwable) {
    // best-effort cleanup only
}

$writer = new \Fast($name);
$writer['x'] = \str_repeat('a', 12);

$reader = new \Fast($name);
if ($reader['x'] !== \str_repeat('a', 12)) {
    $fail('reader should see initial larger value');
}

// Smaller overwrite — overwrite in place + release tail remainder to free list.
$writer['x'] = 'bbb';
if ($writer['x'] !== 'bbb') {
    $fail('writer should observe its own smaller overwrite');
}
if ($reader['x'] !== 'bbb') {
    $fail('already-attached reader should see smaller overwrite (cross-process publication)');
}

$reader->destroy();

echo 'shared update smaller visibility ok' . PHP_EOL;
