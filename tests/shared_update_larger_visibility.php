<?php declare(strict_types = 1);

/**
 * Contract test: Shared Update Larger Visibility.
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

$name = 'fast-store-upd-larger-' . \getmypid();
try {
    $cleanup = new \Fast($name);
    $cleanup->destroy();
} catch (\Throwable) {
    // best-effort cleanup only
}

$writer = new \Fast($name);
$writer['x'] = 'aaa';

$reader = new \Fast($name);
if ($reader['x'] !== 'aaa') {
    $fail('reader should see initial small value');
}

// Larger replace — allocate a new block, repoint id/dir slots, free old block.
$large = \str_repeat('b', 100000);
$writer['x'] = $large;
if ($writer['x'] !== $large) {
    $fail('writer should observe its own larger replace');
}

$got = $reader['x'];
if ($got !== $large) {
    $fail('already-attached reader should see larger replace exactly (len=' . \strlen((string) $got) . ')');
}

$reader->destroy();

echo 'shared update larger visibility ok' . PHP_EOL;
