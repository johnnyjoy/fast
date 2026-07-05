<?php declare(strict_types = 1);

/**
 * Contract test: Shared Direct Reads.
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

$name = 'fast-store-direct-' . \getmypid();
$writer = new \Fast(['name' => $name, 'persistent' => true]);
$payload = ['name' => 'Ada', 'flags' => 1, 'blob' => \str_repeat('x', 1024 * 64)];
$writer['user'] = $payload;
$writer['nil'] = null;
$writer->close();

$reader = new \Fast($name);

if (!isset($reader['user'])) {
    $fail('shared direct isset() should see user');
}

$value = $reader['user'];
if ($value !== $payload) {
    $fail('shared direct array read value mismatch');
}

if ($reader['user'] !== $payload) {
    $fail('shared direct array read value mismatch');
}

$value = $reader['nil'];
if ($value !== null) {
    $fail('shared direct null round-trip failed');
}

if (($reader['missing'] ?? 'fallback') !== 'fallback') {
    $fail('shared direct missing default failed');
}

$reader->destroy();

echo 'shared direct reads ok' . PHP_EOL;
