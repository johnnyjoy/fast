<?php declare(strict_types = 1);

/**
 * Contract test: Stats Debug.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/engine_access.php';

use \Fast;

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$store = new \Fast();
$store['secret'] = 'do-not-leak';
$store['n'] = null;

$stats = fast_test_stats($store);
$debug = $store->__debugInfo();

if (!\is_array($stats) || !\is_array($debug)) {
    $fail('stats/debugInfo should return arrays');
}

if ($stats !== $debug) {
    $fail('__debugInfo should mirror stats()');
}

if (\in_array('do-not-leak', $stats, true) || \in_array('do-not-leak', $debug, true)) {
    $fail('stats must not expose stored values');
}

foreach (['shared', 'name', 'count', 'directory_slots', 'persistent', 'shared_size'] as $field) {
    if (!\array_key_exists($field, $stats)) {
        $fail('missing stats field: ' . $field);
    }
}

if ($stats['count'] < 2) {
    $fail('stats count should reflect stored keys');
}

echo 'stats debug ok' . PHP_EOL;
