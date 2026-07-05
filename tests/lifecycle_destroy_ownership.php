<?php declare(strict_types = 1);

/**
 * Contract test: Lifecycle Destroy Ownership.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/engine_access.php';

use \Fast;

/**
 * destroy() ownership contract (docs/specification.md).
 *
 * destroy() removes a store only when this is the sole connected process. While
 * another process is connected it fails clearly and leaves the store intact and
 * readable. The rule is identical for persistent and non-persistent stores.
 */

if (!\function_exists('pcntl_fork')) {
    fwrite(STDERR, 'skip: pcntl is required for multi-process ownership test' . PHP_EOL);
    exit(77);
}

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

// ---- Sole owner may destroy (persistent and non-persistent) -----------------
foreach (['np' => false, 'p' => true] as $tag => $persistent) {
    $name = 'fast-store-destroy-sole-' . $tag . '-' . \getmypid();
    $store = new \Fast(['name' => $name, 'persistent' => $persistent]);
    $store['k'] = 1;
    try {
        $store->destroy();
    } catch (\Throwable $e) {
        $fail("sole-owner destroy ($tag) must succeed: " . $e->getMessage());
    }
    // fast_test_open_existing() is the test-only attach-existing-only probe: it
    // throws when the store is gone. A destroyed store must no longer exist.
    $removed = false;
    try {
        fast_test_open_existing($name);
    } catch (\Throwable) {
        $removed = true;
    }
    if (!$removed) {
        $fail("sole-owner destroy ($tag) must remove the store");
    }
}

// ---- destroy() fails while a second process is attached ---------------------
$name = 'fast-store-destroy-shared-' . \getmypid();
$sync = 'fast-store-destroy-sync-' . \getmypid();

$owner = new \Fast(['name' => $name, 'persistent' => true]);
$owner['payload'] = 'live-data';
$syncStore = new \Fast(['name' => $sync, 'persistent' => true]);

$pid = \pcntl_fork();
if ($pid === -1) {
    $fail('fork failed');
}
if ($pid === 0) {
    $child = new \Fast($name);
    $childSync = new \Fast($sync);
    $childSync['attached'] = 1;        // tell the parent we are connected
    // Hold the attachment until the parent says it has tried to destroy.
    for ($i = 0; $i < 500; $i++) {
        if (isset($childSync['parent_done'])) {
            break;
        }
        \usleep(10000);
    }
    $ok = (($child['payload'] ?? null) === 'live-data'); // data survived failed destroy
    $child->close();
    $childSync->close();
    exit($ok ? 0 : 1);
}

// wait for child to attach
for ($i = 0; $i < 500; $i++) {
    if (isset($syncStore['attached'])) {
        break;
    }
    \usleep(10000);
}
if (!isset($syncStore['attached'])) {
    $fail('child did not attach in time');
}

$threw = false;
try {
    $owner->destroy();
} catch (\RuntimeException $e) {
    $threw = true;
    if (!\str_contains($e->getMessage(), 'still connected')) {
        $fail('destroy-while-attached error should explain the connected processes: ' . $e->getMessage());
    }
}
if (!$threw) {
    $fail('destroy() must fail while a second process is attached');
}

// store must be intact for this process after the refused destroy
if (($owner['payload'] ?? null) !== 'live-data') {
    $fail('refused destroy() must leave the store intact for this process');
}

$syncStore['parent_done'] = 1; // release the child
\pcntl_waitpid($pid, $status);
if (!\pcntl_wifexited($status) || \pcntl_wexitstatus($status) !== 0) {
    $fail('refused destroy() must leave the store readable by the other process');
}

// child is gone; this process is now the sole owner and may destroy
$owner->destroy();
$syncStore->destroy();

echo 'lifecycle destroy ownership ok' . PHP_EOL;
