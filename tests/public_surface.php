<?php declare(strict_types = 1);

/**
 * Contract test: public surface lock.
 *
 * The public method surface of {@see Fast} must be exactly the array-like-facade
 * story (construction, ArrayAccess, magic, Iterator, Countable, each(),
 * close()/destroy() lifecycle, PHP magic). There are no public static helpers and
 * no capability probes: shared memory and igbinary are mandatory and fail loudly
 * when missing. stats() is NOT public contract (it is private engine debt reachable
 * only via the test-only fast_test_stats() helper). Any new public method must be
 * a deliberate decision that updates this allow-list.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$allowed = [
    // constructor
    '__construct',
    // PHP object magic
    '__destruct', '__debugInfo', '__serialize', '__unserialize',
    // ArrayAccess
    'offsetExists', 'offsetGet', 'offsetSet', 'offsetUnset',
    // magic property access
    '__get', '__set', '__isset', '__unset',
    // Iterator
    'rewind', 'current', 'key', 'next', 'valid', 'seek',
    // Countable
    'count',
    // grouped operation
    'each',
    // lifecycle
    'close', 'destroy',
];

$ref = new \ReflectionClass(\Fast::class);
$publicMethods = [];
foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
    $publicMethods[] = $m->getName();
}

$allowedSet = \array_flip($allowed);

$unexpected = [];
foreach ($publicMethods as $name) {
    if (!isset($allowedSet[$name])) {
        $unexpected[] = $name;
    }
}

$missing = [];
foreach ($allowed as $name) {
    if (!\in_array($name, $publicMethods, true)) {
        $missing[] = $name;
    }
}

if ($unexpected !== []) {
    \sort($unexpected);
    $fail('unexpected public method(s) on Fast (engine leak?): ' . \implode(', ', $unexpected));
}

if ($missing !== []) {
    \sort($missing);
    $fail('expected public method(s) missing from Fast: ' . \implode(', ', $missing));
}

// Public constants are also surface. Fast must expose NONE: engine/layout/format
// constants (segment/record magics, frame version, record/value/key types, header
// and slot byte sizes, directory states, write paths) are internal to the engine
// (Shared/Format). Re-exporting them through the facade leaks binary-format
// internals. Any intentional public constant must be a deliberate decision that
// updates this allow-list.
$allowedConstants = [];
$publicConstants = [];
foreach ($ref->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $c) {
    $publicConstants[] = $c->getName();
}
$unexpectedConstants = \array_values(\array_diff($publicConstants, $allowedConstants));
if ($unexpectedConstants !== []) {
    \sort($unexpectedConstants);
    $fail('unexpected public constant(s) on Fast (engine/format leak?): ' . \implode(', ', $unexpectedConstants));
}

echo 'public surface ok' . PHP_EOL;
