<?php declare(strict_types = 1);

/**
 * Contract test: Shared Sleep.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

// Sleep is a real detach; __serialize/__unserialize never carry shared contents.
// What survives a sleep/wake round trip is decided purely by persistence.

// ---- Persistent: sleep/wake preserves contents -----------------------------
$pName = 'fast-shared-sleep-p-' . \getmypid();
$store = new \Fast(['name' => $pName, 'persistent' => true]);
$store->payload = ['hello' => 'world', 'n' => 42];
$store->counter = 7;

$blob = \serialize($store);          // sleep: last process detaches, store survives
$restored = \unserialize($blob);     // wake: reattaches to the surviving store

if (!$restored instanceof Fast) {
    $fail('unserialize failed');
}
if (($restored->payload['hello'] ?? null) !== 'world' || ($restored->payload['n'] ?? null) !== 42) {
    $fail('persistent payload not restored after wakeup');
}
if ($restored->counter !== 7) {
    $fail('persistent counter not restored after wakeup');
}

$restored->counter++;
if ($restored->counter !== 8) {
    $fail('post-wakeup increment failed');
}

$restored->destroy();

// ---- Non-persistent, sole sleeper: contents do NOT survive ------------------
$npName = 'fast-shared-sleep-np-' . \getmypid();
$np = new \Fast(['name' => $npName]);
$np['gone'] = 'soon';

$blobNp = \serialize($np);           // sleep by the last connected process => reclaimed
$wokeNp = \unserialize($blobNp);     // wake recreates the store empty by identity

if (!$wokeNp instanceof Fast) {
    $fail('non-persistent unserialize failed');
}
if (isset($wokeNp['gone'])) {
    $fail('non-persistent sole sleep must NOT preserve contents');
}
if (\count($wokeNp) !== 0) {
    $fail('non-persistent woken store must be empty');
}

// the recreated store is fully usable
$wokeNp['fresh'] = 1;
if ($wokeNp['fresh'] !== 1) {
    $fail('recreated non-persistent store should be writable');
}
$wokeNp->destroy();

echo 'shared sleep ok' . PHP_EOL;
