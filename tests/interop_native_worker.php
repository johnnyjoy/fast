<?php declare(strict_types = 1);

/**
 * Native interop worker — subprocess helper for interop_native_ext.php (ext-fast only).
 *
 * Usage: php -d extension=fast.so interop_native_worker.php <write|read> <store-name>
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

if (!\extension_loaded('fast')) {
    \fwrite(STDERR, "interop_native_worker requires ext-fast\n");
    exit(2);
}

$action = $argv[1] ?? '';
$name = $argv[2] ?? '';

if ($name === '' || !\in_array($action, ['write', 'read'], true)) {
    \fwrite(STDERR, "usage: interop_native_worker.php <write|read> <name>\n");
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
    $store['from_a'] = 'hello-a';
    $store['payload'] = ['x' => 1, 'y' => [2, 3]];
    $store->close();
    exit(0);
}

$store = new \Fast($name);
if (($store['from_a'] ?? null) !== 'hello-a') {
    $fail('read: from_a mismatch');
}
if (($store['from_b'] ?? null) !== 'hello-b') {
    $fail('read: from_b mismatch');
}
if (($store['payload']['y'][1] ?? null) !== 3) {
    $fail('read: payload mismatch');
}
$store->close();
exit(0);
