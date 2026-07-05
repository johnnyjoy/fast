<?php declare(strict_types = 1);

/**
 * Contract test: Shm Exhaustion No Drift.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use Fast\Engine\Flat;
use Fast\Exception\ShmExhaustedException;

/*
 * M1: shared-memory exhaustion must fail atomically and clearly.
 *
 * The allocator preflights the destination growth segment before advancing the
 * frontier or bumping LIVECAPS, so an out-of-shm insert:
 *   - throws a typed ShmExhaustedException with an actionable message, and
 *   - leaves the space accounting (frontier, LIVECAPS, dirtyBytes) UNCHANGED,
 *     so the compaction trigger never drifts on a failed write.
 *
 * We force a deterministic creation failure without depending on the host shm
 * limit: after the store exists, we demand an impossibly large per-segment size
 * so the next growth segment can never be created.
 */
const H_SEQ = 8;        // mirrors Flat private layout
const H_FRONTIER = 24;
const H_LIVECAPS = 576;

$fail = static function (string $m): never {
    \fwrite(STDERR, 'FAIL: ' . $m . PHP_EOL);
    exit(1);
};

if (!\extension_loaded('shmop') || !\extension_loaded('sysvsem')) {
    \fwrite(STDERR, 'skip: shmop + sysvsem required' . PHP_EOL);
    exit(77);
}

$name = 'fast-store-shmx-' . \bin2hex(\random_bytes(6));

$flat = new Flat();
$flat->attach($name, 65536, 64, false);   // payload 64512/segment

$raw = static function (string $name, int $off) use ($fail): int {
    $seg = @\shmop_open(Flat::segKey($name, 0), 'a', 0, 0);
    if ($seg === false) { $fail('could not open segment 0 for raw read'); }
    return \unpack('P', \shmop_read($seg, $off, 8))[1];
};

$dirty = static function (Flat $f): int {
    $rp = new \ReflectionProperty(Flat::class, 'dirtyBytes');
    $rp->setAccessible(true);
    return (int) $rp->getValue($f);
};

$big = \str_repeat('Z', 20000);   // cap 32768; two of these straddle into segment 1
$flat->set('big1', $big);

$frontierBefore = $raw($name, H_FRONTIER);
$capsBefore     = $raw($name, H_LIVECAPS);
$dirtyBefore    = $dirty($flat);

// Make the next growth-segment creation impossible.
$flat->size = 1 << 50;

$threw = false;
try {
    $flat->set('big2', $big);     // must push past segment 0 -> ensureSeg(1) fails
} catch (ShmExhaustedException $e) {
    $threw = true;
    if (\stripos($e->getMessage(), 'shared memory') === false) {
        $fail('exhaustion message is not actionable: ' . $e->getMessage());
    }
}
if (!$threw) {
    $fail('expected ShmExhaustedException when shared memory is exhausted');
}

// No accounting drift on the failure path.
$frontierAfter = $raw($name, H_FRONTIER);
$capsAfter     = $raw($name, H_LIVECAPS);
$dirtyAfter    = $dirty($flat);
if ($frontierAfter !== $frontierBefore) { $fail("frontier drifted: $frontierBefore -> $frontierAfter"); }
if ($capsAfter !== $capsBefore)         { $fail("LIVECAPS drifted: $capsBefore -> $capsAfter"); }
if ($dirtyAfter !== $dirtyBefore)       { $fail("dirtyBytes drifted: $dirtyBefore -> $dirtyAfter"); }

// Seqlock must be healed even (the failed write's window closed cleanly).
if (($raw($name, H_SEQ) & 1) !== 0) {
    $fail('seqlock left odd after a failed insert');
}

// The store is still fully usable: restore size, a fitting write succeeds and
// pre-failure data is intact.
$flat->size = 65536;
$flat->set('small', 'ok');
$v = null;
if (!$flat->get('small', $v) || $v !== 'ok')  { $fail('store unusable after an exhaustion failure'); }
if (!$flat->get('big1', $v)  || $v !== $big)   { $fail('pre-failure data was lost'); }

$flat->destroy();

echo 'shm exhaustion no drift ok' . PHP_EOL;
