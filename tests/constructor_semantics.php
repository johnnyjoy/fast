<?php declare(strict_types = 1);

/**
 * Contract test: Constructor Semantics.
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

$local = new \Fast([]);
$local['x'] = 1;

if (fast_test_stats($local)['shared'] !== false) {
    $fail('empty config should stay local-only');
}

if (fast_test_stats($local)['name'] !== null) {
    $fail('local-only stats should not expose a shared name');
}

if ($local['x'] !== 1) {
    $fail('local-only mode should behave normally');
}

$shared = new \Fast(['name' => 'constructor-semantics-' . \getmypid()]);
$shared['y'] = 2;
$stats = fast_test_stats($shared);

if ($stats['shared'] !== true) {
    $fail('named constructor should open shared mode');
}

if ($stats['name'] === null || $stats['name'] === '') {
    $fail('shared stats should expose the shared name');
}

if ($shared['y'] !== 2) {
    $fail('shared constructor should persist values in-process');
}

$shared->destroy();

echo 'constructor semantics ok' . PHP_EOL;
