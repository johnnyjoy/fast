<?php declare(strict_types = 1);

/**
 * Contract test: Layout Shared Visibility.
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

$name = 'fast-store-layout-visible-' . \getmypid();
try {
    $cleanup = new \Fast(['name' => $name, 'capacity' => 262144, 'size' => 134217728]);
    $cleanup->destroy();
} catch (\Throwable) {
    // best-effort cleanup only
}

$writer = new \Fast([
    'name' => $name,
]);

$writer['x'] = 'visible';

$reader = new \Fast(['name' => $name, 'capacity' => 262144, 'size' => 134217728]);

if ($reader['x'] !== 'visible') {
    $fail('second instance should observe inserted layout value');
}

if (count($reader) !== 1) {
    $fail('second instance count should match inserted layout value');
}

$reader->destroy();

echo 'layout shared visibility ok' . PHP_EOL;
