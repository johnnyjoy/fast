<?php declare(strict_types = 1);

/**
 * Contract test: New Key Iteration.
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

$store = new \Fast();
$expected = [];
for ($i = 0; $i < 1000; $i++) {
    $key = 'k' . $i;
    $expected[] = $key;
    $store[$key] = $i;
}

if (count($store) !== 1000) {
    $fail('count should match inserted keys');
}

$seen = [];
foreach ($store as $key => $value) {
    $seen[] = $key;
    if ($value !== (int) \substr($key, 1)) {
        $fail('iteration value mismatch for ' . $key);
    }
}

if ($seen !== $expected) {
    $fail('iteration order mismatch');
}

echo 'new key iteration ok' . PHP_EOL;
