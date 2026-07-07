<?php declare(strict_types = 1);

/**
 * Interop worker — invoked as a subprocess by interop_php_ext.php (PHP backend only).
 *
 * Usage: php interop_worker.php <write|read|destroy> <store-name> [persistent=0|1]
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

if (\extension_loaded('fast')) {
    \fwrite(STDERR, "interop_worker must run without ext-fast\n");
    exit(2);
}

$action = $argv[1] ?? '';
$name = $argv[2] ?? '';
$persistent = ($argv[3] ?? '0') === '1';

if ($name === '' || !\in_array($action, ['write', 'read', 'destroy', 'gone'], true)) {
    \fwrite(STDERR, "usage: interop_worker.php <write|read|destroy|gone> <name> [persistent]\n");
    exit(1);
}

$fail = static function (string $message): never {
    \fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$config = [
    'name' => $name,
    'capacity' => 1024,
    'size' => 8 * 1024 * 1024,
    'persistent' => true,
];

if ($action === 'write') {
    $store = new \Fast($config);
    $store['from_php'] = 'hello-php';
    $store['count'] = 42;
    $store['nested'] = ['a' => 1, 'b' => [2, 3]];
    $store->close();
    exit(0);
}

if ($action === 'read') {
    $store = new \Fast($name);
    if (($store['from_php'] ?? null) !== 'hello-php') {
        $fail('read: from_php mismatch');
    }
    if (($store['from_ext'] ?? null) !== 'hello-ext') {
        $fail('read: from_ext mismatch');
    }
    if (($store['count'] ?? null) !== 42) {
        $fail('read: count mismatch');
    }
    if (($store['nested']['b'][1] ?? null) !== 3) {
        $fail('read: nested mismatch');
    }
    $store->close();
    exit(0);
}

if ($action === 'gone') {
    require __DIR__ . '/fixtures/engine_access.php';
    exit(fast_test_shared_segment_exists($name, 0) ? 1 : 0);
}

$store = new \Fast($name);
$store->destroy();
exit(0);
