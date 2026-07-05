<?php declare(strict_types = 1);

/**
 * Contract test: Lifecycle Detach.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/engine_access.php';

use \Fast;

/**
 * close() contract (docs/specification.md).
 *
 * For a non-persistent store, the final close (the last connected process
 * leaving) reclaims the store. For a persistent store, the final close leaves
 * the store alive and re-openable by name; close never destroys a persistent
 * store.
 */

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

// new \Fast('name') is open-or-create, so it can never prove a store is GONE.
// fast_test_open_existing() is the test-only attach-existing-only probe: it
// throws when the named store no longer exists. Reclaim is proven when it throws.
$storeGone = static function (string $name): bool {
    try {
        $h = fast_test_open_existing($name);
    } catch (\Throwable) {
        return true; // missing => reclaimed
    }
    $h->close();
    return false; // still present
};

$base = 'fast-store-close-' . \getmypid();

// ---- Non-persistent final close reclaims the store --------------------------
$np = $base . '-np';
$a = new \Fast(['name' => $np]);
$a['k'] = 1;
$a->close();
if (!$storeGone($np)) {
    $fail('non-persistent store must not survive its final close');
}

// ---- Persistent final close preserves the store ----------------------------
$p = $base . '-p';
$b = new \Fast(['name' => $p, 'persistent' => true]);
$b['k'] = 'kept';
$b->close(); // final close must NOT destroy a persistent store

$re = new \Fast($p);
if (($re['k'] ?? null) !== 'kept') {
    $fail('persistent store must survive its final close');
}

// ---- __destruct of a non-persistent sole handle also reclaims ---------------
$d = $base . '-destruct';
$h = new \Fast(['name' => $d]);
$h['x'] = 1;
unset($h); // __destruct => final close => reclaim
if (!$storeGone($d)) {
    $fail('non-persistent store must be reclaimed on sole-handle __destruct');
}

// cleanup: $re is the sole owner of the persistent store
$re->destroy();

echo 'lifecycle close ok' . PHP_EOL;
