<?php declare(strict_types = 1);

/**
 * Contract test: Each Lockfree Snapshot.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/engine_access.php';

use \Fast;

/**
 * L1: each() must NOT hold the writer lock across the user callback. It takes a
 * brief-lock snapshot of the current key set, releases the lock, then invokes the
 * callback per entry with the lock free. This proves:
 *
 *   1. the engine lock is released (lockDepth == 0) while each callback runs;
 *   2. callback mutations succeed lock-free and persist;
 *   3. the walk is a MEMBERSHIP snapshot — entries inserted by the callback are
 *      not visited, and entries deleted before they are reached are skipped.
 */

$fail = static function (string $message): never {
    \fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

if (!fast_test_supports_shared_memory()) {
    echo 'each lockfree snapshot ok (skipped: no shared memory)' . PHP_EOL;
    exit(0);
}

/** Read the engine's private lock depth via reflection (test-only probe). */
function each_lf_lock_depth(Fast $store): int
{
    static $engineProp = null;
    if ($engineProp === null) {
        $engineProp = new \ReflectionProperty(\Fast::class, 'engine');
        $engineProp->setAccessible(true);
    }
    $engine = $engineProp->getValue($store);

    $depthProp = new \ReflectionProperty($engine, 'lockDepth');
    $depthProp->setAccessible(true);
    return (int) $depthProp->getValue($engine);
}

final class EachLockFreeProbe
{
    public static int $maxDepthSeen = -1;
    /** @var array<int,int|string> */
    public static array $visited = [];
    public static bool $mutated = false;

    /** Asserts the lock is free on entry, then mutates the store mid-walk. */
    public static function step(Fast $store, int|string $key, mixed $value): void
    {
        $depth = each_lf_lock_depth($store);
        if ($depth > self::$maxDepthSeen) { self::$maxDepthSeen = $depth; }
        self::$visited[] = $key;

        // On the first entry, mutate the store while iterating: insert a brand-new
        // key and delete a not-yet-visited one. Both happen with the walk's lock
        // released, so they must succeed without deadlock.
        if (!self::$mutated) {
            self::$mutated = true;
            $store['d'] = 40;   // inserted after snapshot -> must NOT be visited
            unset($store['c']); // deleted before reached    -> must be skipped
        }
    }
}

$name = 'fast-each-lockfree-' . \getmypid();
try { (new \Fast($name))->destroy(); } catch (\Throwable) { /* best-effort */ }

$store = new \Fast($name);
$store['a'] = 10;
$store['b'] = 20;
$store['c'] = 30;

EachLockFreeProbe::$maxDepthSeen = -1;
EachLockFreeProbe::$visited = [];
EachLockFreeProbe::$mutated = false;

$count = $store->each([EachLockFreeProbe::class, 'step']);

// 1. lock released across every callback.
if (EachLockFreeProbe::$maxDepthSeen !== 0) {
    $fail('each() held the lock during the callback (max lockDepth seen: '
        . EachLockFreeProbe::$maxDepthSeen . ')');
}

// 3. snapshot membership: 'd' (inserted mid-walk) never visited; 'c' (deleted
//    mid-walk) skipped. Visited set is {a, b}, in snapshot order.
if (EachLockFreeProbe::$visited !== ['a', 'b']) {
    $fail('snapshot visitation mismatch: ' . \json_encode(EachLockFreeProbe::$visited));
}
if ($count !== 2) {
    $fail('each() should count only the 2 entries actually visited; got ' . $count);
}

// 2. callback mutations persisted (writes ran lock-free during the walk).
if (isset($store['c'])) {
    $fail("callback's unset('c') did not persist");
}
if (!isset($store['d']) || $store['d'] !== 40) {
    $fail("callback's insert of 'd' did not persist");
}
if (\count($store) !== 3) { // a, b, d
    $fail('post-walk count mismatch: ' . \count($store));
}

$store->destroy();

echo 'each lockfree snapshot ok' . PHP_EOL;
