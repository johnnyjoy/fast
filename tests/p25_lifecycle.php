<?php declare(strict_types = 1);

/**
 * Contract test: P25 Lifecycle.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/engine_access.php';

use \Fast;

/**
 * Lifecycle contract (matches docs/specification.md).
 *
 * Named shared stores are NON-PERSISTENT by default: when the last connected
 * process closes, the store is reclaimed. persistent => true opts a store into
 * surviving its last close so a later process can re-open by name. destroy()
 * is permitted only to the sole connected process.
 */

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

// ---- Local mode: no shared memory is created --------------------------------
$local = new \Fast();
$local['x'] = 1;
if ($local['x'] !== 1) {
    $fail('local store should behave normally');
}
if (fast_test_stats($local)['shared'] !== false) {
    $fail('local mode must not create a shared store');
}
unset($local);

$base = 'fast-p25-life-' . \getmypid();

// ---- Non-persistent default: last close reclaims the store ------------------
$npName = $base . '-np';
$np = new \Fast(['name' => $npName]);
$np['kept'] = 'value';
$np->close(); // sole connected process leaves => store is reclaimed

// fast_test_open_existing() is the test-only attach-existing-only probe: it throws
// when the store is gone. A reclaimed store must no longer exist.
$reclaimed = false;
try {
    fast_test_open_existing($npName);
} catch (\Throwable) {
    $reclaimed = true;
}
if (!$reclaimed) {
    $fail('non-persistent store must be reclaimed when the last process closes');
}

// ---- Persistent: survives close and a later same-process re-open ------------
$pName = $base . '-p';
$a = new \Fast(['name' => $pName, 'persistent' => true]);
$a['kept'] = 'value';
$a->close(); // release this handle; persistent store must remain

$b = new \Fast($pName);
if (($b['kept'] ?? null) !== 'value') {
    $fail('persistent store must survive close() and re-open');
}

// ---- Cross-process visibility (separate process sees the data) --------------
if (\function_exists('pcntl_fork')) {
    $pid = \pcntl_fork();
    if ($pid === 0) {
        $child = new \Fast($pName);
        $ok = (($child['kept'] ?? null) === 'value');
        $child->close();
        exit($ok ? 0 : 1);
    }
    \pcntl_waitpid($pid, $status);
    if (!\pcntl_wifexited($status) || \pcntl_wexitstatus($status) !== 0) {
        $fail('a separate process must see the persistent named store');
    }
}

// ---- destroy() by the sole owner is the teardown ----------------------------
$b->destroy(); // $b is the only connected process now

$c = new \Fast(['name' => $pName, 'persistent' => true]);
if (isset($c['kept'])) {
    $fail('destroy() must remove the store; a later attach must be empty');
}
$c->destroy();

echo 'p25 lifecycle ok' . PHP_EOL;
