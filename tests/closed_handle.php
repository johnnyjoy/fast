<?php declare(strict_types = 1);

/**
 * Contract test: Closed Handle.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/engine_access.php';

use \Fast;

/**
 * Closed-handle contract (docs/specification.md "Closed handles").
 *
 * close() disconnects this handle and is safe to call more than once. After
 * close() the handle is dead: every data operation (ArrayAccess, magic property
 * access, iteration, count(), each()), destroy(), and serialization fail clearly
 * with a thrown exception. A closed handle never silently reopens the store,
 * returns empty/null/false, or behaves like an empty array. __destruct of an
 * already-closed handle is a safe no-op (no double link decrement).
 */

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$expectThrow = static function (callable $op, string $label) use ($fail): void {
    try {
        $op();
    } catch (\Throwable) {
        return; // threw clearly => good
    }
    $fail($label . ' must throw after close(), but it returned silently');
};

// ---- Local (in-process) handle: universal guard behavior --------------------
// The closed-handle guard is mode-independent, so the throw checks run without
// requiring shared-memory extensions.
$local = new \Fast();
$local['k'] = 'v';
$local->prop = 1;

$local->close();

// close() is idempotent: a second (and third) close() is a safe no-op.
$local->close();
$local->close();

// Every data operation must fail clearly after close().
$expectThrow(static fn () => $local['k'], 'array read');
$expectThrow(static function () use ($local): void { $local['k'] = 'x'; }, 'array write');
$expectThrow(static fn () => isset($local['k']), 'array isset');
$expectThrow(static function () use ($local): void { unset($local['k']); }, 'array unset');

$expectThrow(static fn () => $local->prop, 'magic get');
$expectThrow(static function () use ($local): void { $local->prop = 2; }, 'magic set');
$expectThrow(static fn () => isset($local->prop), 'magic isset');
$expectThrow(static function () use ($local): void { unset($local->prop); }, 'magic unset');

$expectThrow(static function () use ($local): void {
    foreach ($local as $_k => $_v) {
    }
}, 'foreach');

$expectThrow(static fn () => \count($local), 'count');
$expectThrow(static fn () => $local->each('count'), 'each');
$expectThrow(static fn () => $local->destroy(), 'destroy');
$expectThrow(static fn () => \serialize($local), 'serialize');

// ---- Shared handle: lifecycle correctness of close()/destroy ----------------
// Shared memory is mandatory (Fast throws when shmop/sysvsem are missing), so the
// shared lifecycle checks run unconditionally rather than behind a skip guard.
$base = 'fast-store-closed-' . \getmypid();

// new \Fast() is open-or-create and can never prove a store is gone, so use the
// test-only attach-existing-only probe: it throws when the store is reclaimed.
$storeReclaimed = static function (string $name): bool {
    try {
        $h = fast_test_open_existing($name);
    } catch (\Throwable) {
        return true; // missing => reclaimed
    }
    $h->close();
    return false;
};

// Idempotent close() must not double-drop the process link or break reclaim
// of a non-persistent sole-handle store.
$np = $base . '-np';
$a = new \Fast(['name' => $np]);
$a['x'] = 1;
$a->close();
$a->close(); // second close: must be a safe no-op
try {
    fast_test_open_existing($np);
    $fail('non-persistent store must be reclaimed after its final close');
} catch (\Throwable) {
    // missing => reclaimed correctly; no double decrement corrupted state
}

// destroy() after close() must throw, not reopen or operate on a stale handle.
$p = $base . '-p';
$b = new \Fast(['name' => $p, 'persistent' => true]);
$b['x'] = 'kept';
$b->close();
$expectThrow(static fn () => $b->destroy(), 'destroy after close (shared)');

// The persistent store must still be intact and destroyable via a fresh handle.
$c = new \Fast($p);
if (($c['x'] ?? null) !== 'kept') {
    $fail('persistent store must survive a handle close()');
}
$c->destroy();

// __destruct after an explicit close() must be a safe no-op: the destructor
// calls close() again (idempotent), so it must not double-drop the process
// link nor reclaim the store a second time. Non-persistent sole handle: the
// explicit close() already reclaimed it; the later __destruct must not error.
$d = $base . '-destruct';
$h = new \Fast(['name' => $d]);
$h['x'] = 1;
$h->close(); // reclaims (non-persistent sole handle)
unset($h);   // __destruct on already-closed handle: safe no-op, must not crash

if (!$storeReclaimed($d)) {
    $fail('store must stay reclaimed; __destruct after close must not resurrect or corrupt it');
}

echo 'closed handle ok' . PHP_EOL;
