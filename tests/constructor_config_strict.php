<?php declare(strict_types = 1);

/**
 * Contract test: Constructor Config Strict.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/engine_access.php';

use \Fast;
use InvalidArgumentException;

/**
 * Constructor config honesty: only name/capacity/size/persistent are honored.
 * persistent is a real option (default false) accepted as true or false. Every
 * other key (typo or not-implemented option) must throw InvalidArgumentException
 * rather than be silently ignored.
 */

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

// ---- Accepted forms must work -----------------------------------------------
$accepted = [
    'new \Fast()'                 => static fn () => new \Fast(),
    'new \Fast([])'               => static fn () => new \Fast([]),
];

$suffix = '-' . \getmypid();
$accepted['new \Fast(string name)'] = static fn () => new \Fast('fast-p26-cfg-a' . $suffix);
$accepted['new \Fast([name])'] = static fn () => new \Fast(['name' => 'fast-p26-cfg-b' . $suffix]);
$accepted['new \Fast([name, capacity])'] = static fn () => new \Fast(['name' => 'fast-p26-cfg-c' . $suffix, 'capacity' => 1024]);
$accepted['new \Fast([name, size])'] = static fn () => new \Fast(['name' => 'fast-p26-cfg-d' . $suffix, 'size' => 1048576]);
$accepted['new \Fast([name, persistent=>true])'] = static fn () => new \Fast(['name' => 'fast-p26-cfg-e' . $suffix, 'persistent' => true]);
$accepted['new \Fast([name, persistent=>false])'] = static fn () => new \Fast(['name' => 'fast-p26-cfg-f' . $suffix, 'persistent' => false]);

foreach ($accepted as $label => $make) {
    try {
        $store = $make();
    } catch (\Throwable $e) {
        $fail("accepted form failed: $label => " . $e->getMessage());
    }
    if (fast_test_stats($store)['shared']) {
        $store->destroy();
    }
}

// capacity/size accepted in local mode too (no name => no shared memory)
try {
    new \Fast(['capacity' => 2048]);
    new \Fast(['size' => 1048576]);
} catch (\Throwable $e) {
    $fail('capacity/size in local mode should be accepted: ' . $e->getMessage());
}

// ---- persistent is a valid key, not a rejection ----------------------------
foreach ([true, false] as $persistentValue) {
    try {
        new \Fast(['capacity' => 1024, 'persistent' => $persistentValue]);
    } catch (\Throwable $e) {
        $fail('persistent => ' . \var_export($persistentValue, true) . ' must be accepted: ' . $e->getMessage());
    }
}

// ---- Rejected forms must throw with a clear message -------------------------
$rejected = [
    ['name' => 'cache', 'project_id' => 1],
    ['name' => 'cache', 'key' => 123],
    ['name' => 'cache', 'permissions' => 0600],
    ['nmae' => 'cache'],
    ['name' => 'cache', 'unknown' => 'x'],
    ['name' => 'cache', 'persistent' => true, 'project_id' => 1],
];

foreach ($rejected as $config) {
    $badKey = '';
    foreach ($config as $k => $_v) {
        if ($k !== 'name' && $k !== 'capacity' && $k !== 'size' && $k !== 'persistent') {
            $badKey = (string) $k;
            break;
        }
    }

    $threw = false;
    try {
        new \Fast($config);
    } catch (InvalidArgumentException $e) {
        $threw = true;
        if (\stripos($e->getMessage(), 'Unsupported Fast config key') === false) {
            $fail('rejection message should name the unsupported key for: ' . $badKey . ' (got: ' . $e->getMessage() . ')');
        }
        if (\strpos($e->getMessage(), $badKey) === false) {
            $fail('rejection message should include the offending key: ' . $badKey);
        }
    }

    if (!$threw) {
        $fail('config with unsupported key should throw: ' . $badKey);
    }
}

echo 'constructor config strict ok' . PHP_EOL;
