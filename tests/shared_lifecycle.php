<?php declare(strict_types = 1);

/**
 * Contract test: Shared Lifecycle.
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

$name = 'fast-store-' . \bin2hex(\random_bytes(8));

$writer = new \Fast(['name' => $name, 'persistent' => true]);
$writer['alpha'] = 1;
$writer[7] = 'seven';

if ($writer->count() !== 2) {
    $fail('writer should report two live entries');
}

$writer->close();

$reader = new \Fast($name);

if ($reader['alpha'] !== 1) {
    $fail('attached reader lost alpha');
}

if ($reader[7] !== 'seven') {
    $fail('attached reader lost integer key');
}

if (!isset($reader['alpha'])) {
    $fail('attached reader should see alpha');
}

unset($reader['alpha']);

if (isset($reader['alpha'])) {
    $fail('delete should be visible in shared mode');
}

$reader->destroy();

echo 'shared lifecycle ok' . PHP_EOL;
