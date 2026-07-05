<?php declare(strict_types = 1);

/**
 * Contract test: Striped Basic.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

$fail = static function (string $m): never { \fwrite(\STDERR, $m . \PHP_EOL); exit(1); };

$name = 'fast-striped-basic-' . \getmypid();

// best-effort cleanup of any debris from a prior aborted run
try { (new \Fast(['name' => $name, 'capacity' => 4096, 'size' => 8 * 1024 * 1024, 'stripes' => 8, 'persistent' => true]))->destroy(); } catch (\Throwable) {}

// persistent so the serialize/wakeup round-trip below survives (per Fast's sleep
// contract: only persistent stores keep contents across a sole-sleeper wake).
$store = new \Fast(['name' => $name, 'capacity' => 4096, 'size' => 8 * 1024 * 1024, 'stripes' => 8, 'persistent' => true]);

// ---- insert a mix of int + string keys, scalar + container values ----
$N = 2000;
$expectOrder = [];
for ($i = 0; $i < $N; $i++) {
    $k = ($i % 2 === 0) ? "user:$i" : $i;            // exercise both key types + both prefixes
    $v = ($i % 3 === 0) ? ['i' => $i, 'p' => \str_repeat('x', $i % 50)] : $i * 7;
    $store[$k] = $v;
    $expectOrder[] = $k;
}

if (\count($store) !== $N) { $fail('count mismatch: ' . \count($store) . " != $N"); }

// ---- read back every value ----
for ($i = 0; $i < $N; $i++) {
    $k = ($i % 2 === 0) ? "user:$i" : $i;
    $want = ($i % 3 === 0) ? ['i' => $i, 'p' => \str_repeat('x', $i % 50)] : $i * 7;
    $got = $store[$k];
    if ($got != $want) { $fail("value mismatch for key " . \var_export($k, true)); }
    if (!isset($store[$k])) { $fail("isset false for present key " . \var_export($k, true)); }
}

// ---- SINGLE-WRITER iteration order must be STRICT insertion order ----
// One process inserted sequentially, so hrtime tags are monotonic and the k-way
// merge must reproduce the exact insertion order.
$iterKeys = [];
foreach ($store as $k => $v) { $iterKeys[] = $k; }
if ($iterKeys !== $expectOrder) {
    // find first divergence for a useful message
    $n = \min(\count($iterKeys), \count($expectOrder));
    $at = -1;
    for ($j = 0; $j < $n; $j++) { if ($iterKeys[$j] !== $expectOrder[$j]) { $at = $j; break; } }
    $fail("single-writer iteration not strict insertion order (got " . \count($iterKeys)
        . " keys, first divergence at index $at)");
}

// ---- deletes route correctly and shrink the set ----
$deleted = 0;
for ($i = 0; $i < $N; $i += 5) {
    $k = ($i % 2 === 0) ? "user:$i" : $i;
    unset($store[$k]);
    $deleted++;
}
if (\count($store) !== $N - $deleted) { $fail('count after deletes wrong: ' . \count($store) . ' != ' . ($N - $deleted)); }
for ($i = 0; $i < $N; $i += 5) {
    $k = ($i % 2 === 0) ? "user:$i" : $i;
    if (isset($store[$k])) { $fail("deleted key still present: " . \var_export($k, true)); }
}

// surviving keys still iterate in insertion order (deletes don't reorder)
$expectSurv = [];
for ($i = 0; $i < $N; $i++) { if ($i % 5 !== 0) { $expectSurv[] = ($i % 2 === 0) ? "user:$i" : $i; } }
$iterSurv = [];
foreach ($store as $k => $v) { $iterSurv[] = $k; }
if ($iterSurv !== $expectSurv) { $fail('post-delete iteration not in insertion order'); }

// ---- overwrite a SURVIVING key updates in place (no count change) ----
// user:4 -> i=4: even key, survived deletes (4%5!=0).
$store['user:4'] = 'OVERWRITTEN';
if ($store['user:4'] !== 'OVERWRITTEN') { $fail('overwrite not visible'); }
if (\count($store) !== $N - $deleted) { $fail('overwrite must not change count'); }

// ---- serialize / wakeup round-trip preserves a striped store ----
$blob = \serialize($store);          // sleep: $store detaches (persistent => survives)
$woke = \unserialize($blob);         // wake: reattaches to the surviving striped store
if (!($woke instanceof Fast)) { $fail('wakeup did not produce a Fast'); }
// user:2 -> i=2: even key, 2%3!=0 so value = 2*7 = 14 (and it survived deletes: 2%5!=0)
if ($woke['user:2'] !== 2 * 7) { $fail('wakeup lost data: user:2 = ' . \var_export($woke['user:2'], true)); }
if ($woke['user:4'] !== 'OVERWRITTEN') { $fail('wakeup lost overwrite'); }
if (\count($woke) !== $N - $deleted) { $fail('wakeup count mismatch: ' . \count($woke) . ' != ' . ($N - $deleted)); }

// $store is now detached (sleep closed it); the woken handle is the sole owner.
$woke->destroy();

echo 'striped basic ok' . \PHP_EOL;
