<?php declare(strict_types = 1);

/**
 * Contract test: Layout Insert Read.
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

$name = 'fast-store-layout-insert-' . \getmypid();
try {
    $cleanup = new \Fast(['name' => $name, 'capacity' => 262144, 'size' => 134217728]);
    $cleanup->destroy();
} catch (\Throwable) {
    // best-effort cleanup only
}

$fast = new \Fast([
    'name' => $name,
]);

$fast['x'] = 123;

if ($fast['x'] !== 123) {
    $fail('layout insert should remain readable');
}

if (!isset($fast['x'])) {
    $fail('layout isset() should see inserted key');
}

if ($fast['x'] !== 123) {
    $fail('layout read should round-trip the value');
}

if (count($fast) !== 1) {
    $fail('layout count should be one after one insert');
}

$fast->destroy();

echo 'layout insert read ok' . PHP_EOL;
