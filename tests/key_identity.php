<?php declare(strict_types = 1);

/**
 * Contract test: Key Identity.
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
$store[1] = 'int';
$store['1'] = 'string';

if ($store[1] !== 'int') {
    $fail('int key 1 should remain distinct');
}

if ($store['1'] !== 'string') {
    $fail('string key "1" should remain distinct');
}

if (count($store) !== 2) {
    $fail('int and string keys should both be counted');
}

$keys = [];
foreach ($store as $key => $value) {
    $keys[] = $key;
}

if ($keys !== [1, '1']) {
    $fail('int and string key order should remain distinct');
}

echo 'key identity ok' . PHP_EOL;
