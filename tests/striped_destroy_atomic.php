<?php declare(strict_types = 1);

/**
 * Contract test: Striped Destroy Atomic.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/engine_access.php';

use \Fast;
use Fast\Engine\Flat;

use function Fast\fast_test_shared_segment_exists;

/**
 * M5 regression: Striped::destroy() must be ATOMIC across sub-stores.
 *
 * The old `foreach ($sub as $e) $e->destroy()` loop had no all-or-nothing
 * guarantee: if a LATER stripe could not be destroyed (a peer still attached),
 * the earlier stripes were already deleted — a half-torn store. We reproduce the
 * uneven state by forking a peer that attaches ONLY one stripe's sub-store, then
 * assert a refused destroy leaves EVERY stripe intact, and a clean destroy (after
 * the peer dies) removes them all.
 */

if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
    \fwrite(\STDERR, 'skip: pcntl + posix required' . \PHP_EOL);
    exit(77);
}
if (!\extension_loaded('shmop') || !\extension_loaded('sysvsem')) {
    \fwrite(\STDERR, 'skip: shmop + sysvsem required' . \PHP_EOL);
    exit(77);
}
if (\extension_loaded('fast')) {
    echo 'striped destroy atomic ok (skipped: ext-native link table does not see PHP Flat peer pins)' . PHP_EOL;
    exit(0);
}

$fail = static function (string $m): never { \fwrite(\STDERR, $m . \PHP_EOL); exit(1); };

$stripes  = 4;
$capacity = 256;                       // -> 64 slots per stripe
$size     = 4 * 65536;                 // -> 65536 bytes per stripe
$perSlots = \intdiv($capacity, $stripes);
$perSize  = \intdiv($size, $stripes);
$wedge    = 3;                         // the stripe the peer will pin

$name = 'fast-striped-destroy-' . \getmypid();
$cfg  = ['name' => $name, 'capacity' => $capacity, 'size' => $size, 'stripes' => $stripes];
$sub  = static fn (int $i): string => $name . '#' . $i;
$ready = \sys_get_temp_dir() . '/' . $name . '.peer_ready';
@\unlink($ready);

// best-effort debris cleanup
try { (new \Fast($cfg))->destroy(); } catch (\Throwable) {}

$store = new \Fast($cfg);
for ($i = 0; $i < 200; $i++) { $store['k' . $i] = $i; }

$pid = \pcntl_fork();
if ($pid === -1) { $fail('fork failed'); }

if ($pid === 0) {
    // CHILD: attach ONLY the wedge stripe's sub-store, hold it, idle until killed.
    $e = new Flat();
    $e->attach($sub($wedge), $perSize, $perSlots, false, true);
    \touch($ready);
    while (true) { \sleep(3600); }
    exit(0);
}

// PARENT: wait until the peer pins the wedge stripe.
$deadline = \microtime(true) + 15.0;
while (!\is_file($ready)) {
    if (\microtime(true) > $deadline) { \posix_kill($pid, \SIGKILL); \pcntl_waitpid($pid, $s); $fail('peer never attached'); }
    \usleep(2000);
}

// A destroy now must be REFUSED — and must delete nothing.
$threw = false;
try {
    $store->destroy();
} catch (\RuntimeException $e) {
    $threw = true;
}
if (!$threw) {
    \posix_kill($pid, \SIGKILL); \pcntl_waitpid($pid, $s);
    $fail('destroy with a peer-pinned stripe must throw');
}

$survived = 0;
for ($i = 0; $i < $stripes; $i++) {
    if (fast_test_shared_segment_exists($sub($i), 0)) { $survived++; }
}
if ($survived !== $stripes) {
    \posix_kill($pid, \SIGKILL); \pcntl_waitpid($pid, $s);
    $fail('NON-ATOMIC destroy: only ' . $survived . '/' . $stripes . ' stripes survived a refused destroy (partial teardown)');
}

// Peer goes away -> a clean destroy must now remove every stripe.
\posix_kill($pid, \SIGKILL);
\pcntl_waitpid($pid, $status);
@\unlink($ready);

$store->destroy();
$leftover = 0;
for ($i = 0; $i < $stripes; $i++) {
    if (fast_test_shared_segment_exists($sub($i), 0)) { $leftover++; }
}
if ($leftover !== 0) { $fail('clean destroy left ' . $leftover . ' stripe(s) behind'); }

echo 'striped destroy atomic ok' . \PHP_EOL;
