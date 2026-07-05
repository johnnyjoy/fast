<?php declare(strict_types = 1);

/**
 * Contract test: Named Open Existing.
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

$name = 'fast-store-open-existing-' . \getmypid();
try {
    $cleanup = new \Fast($name);
    $cleanup->destroy();
} catch (\Throwable) {
    // best-effort cleanup only
}

$writer = new \Fast(['name' => $name]);
$writer['alpha'] = 1;
$writer['beta'] = 2;

$reader = new \Fast(['name' => $name]);

if ($reader['alpha'] !== 1 || $reader['beta'] !== 2) {
    $fail('reopening an existing named instance should preserve data');
}

if (\count($reader) !== 2) {
    $fail('reopening an existing named instance should preserve count');
}

$reader->destroy();
$writer->close();

echo 'named open existing ok' . PHP_EOL;
