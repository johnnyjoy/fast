<?php declare(strict_types = 1);

/**
 * Contract test: Layout Key Types.
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

$name = 'fast-store-layout-key-types-' . \getmypid();
try {
    $cleanup = new \Fast(['name' => $name, 'capacity' => 262144, 'size' => 134217728]);
    $cleanup->destroy();
} catch (\Throwable) {
    // best-effort cleanup only
}

$fast = new \Fast([
    'name' => $name,
]);

$fast[1] = 'integer';
$fast['1'] = 'string';

if ($fast[1] !== 'integer') {
    $fail('integer key should remain distinct');
}

if ($fast['1'] !== 'string') {
    $fail('string key should remain distinct');
}

if (count($fast) !== 2) {
    $fail('distinct int/string keys should count separately');
}

$fast->destroy();

echo 'layout key types ok' . PHP_EOL;
