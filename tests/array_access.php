<?php declare(strict_types = 1);

/**
 * Contract test: Array Access.
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

if (isset($store['missing'])) {
    $fail('missing key should not be set');
}

$store['alpha'] = 1;
$store[7] = 'seven';
$store['nil'] = null;

if (!isset($store['alpha'])) {
    $fail('alpha should exist via array access');
}

if ($store['alpha'] !== 1) {
    $fail('alpha array read mismatch');
}

if ($store[7] !== 'seven') {
    $fail('integer array read mismatch');
}

// Deliberate P2.5 contract: isset() follows PHP array semantics — a stored
// null is reported as NOT set, exactly like isset($array['nil']) on a native
// PHP array. Fast intentionally does not expose array_key_exists-style presence
// independent of null as public API.
if (isset($store['nil'])) {
    $fail('stored null must report isset() === false (PHP-like semantics)');
}

if ($store['nil'] !== null) {
    $fail('stored null should read back as null');
}

unset($store['alpha']);

if (isset($store['alpha'])) {
    $fail('unset should remove alpha');
}

echo 'array access ok' . PHP_EOL;
