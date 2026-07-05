<?php declare(strict_types = 1);

/**
 * Contract test: Striped Skew Diag.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use Fast\Engine\Flat;
use Fast\Engine\Striped;

/**
 * L2 regression: striping only scales when writes spread across stripes. We can't
 * make a hot key spread (routing is keyed by design), but we CAN make the skew
 * observable. This proves the opt-in M6 diagnostic sink surfaces the per-stripe
 * distribution on the cold paths, and that the default (no sink) stays silent.
 *
 *   - all keys routed to ONE stripe  -> imbalance == stripes (worst case)
 *   - one key per stripe (even)      -> imbalance == 1.0     (best case)
 *   - no sink installed              -> nothing emitted, no error
 */

if (!\extension_loaded('shmop') || !\extension_loaded('sysvsem')) {
    echo 'striped skew diag ok (skipped: no shared memory)' . \PHP_EOL;
    exit(0);
}

$fail = static function (string $m): never { \fwrite(\STDERR, $m . \PHP_EOL); exit(1); };

$STRIPES = 4;
$SLOTS   = 256;
$SIZE    = 262144; // 65536 per stripe

// Reflect Striped::route() so the test can deterministically place keys in a
// chosen stripe (route is private by design; lookups must agree on a key's home).
$routeMethod = new \ReflectionMethod(Striped::class, 'route');
$routeMethod->setAccessible(true);

/** Find $n distinct string keys that all route to $targetStripe. */
$keysFor = static function (Striped $s, int $targetStripe, int $n) use ($routeMethod): array {
    $out = [];
    $i = 0;
    while (\count($out) < $n) {
        $k = 'k' . $i++;
        if ($routeMethod->invoke($s, $k) === $targetStripe) { $out[] = $k; }
        if ($i > 1_000_000) { break; } // safety; statistically unreachable
    }
    return $out;
};

// Capture sink: record every emitted event.
$events = [];
Flat::setDiagnostics(static function (string $event, array $ctx) use (&$events): void {
    $events[] = [$event, $ctx];
});

// ---- worst case: every key collapses into stripe 0 -> imbalance == stripes ----
$skew = new Striped();
$skew->attach('fast-skew-' . \getmypid(), $SIZE, $SLOTS, false, $STRIPES);
foreach ($keysFor($skew, 0, 20) as $k) { $skew->set($k, 1); }
$skew->close();

$dist = null;
foreach ($events as [$evt, $ctx]) {
    if ($evt === 'striped.distribution' && ($ctx['phase'] ?? null) === 'close') { $dist = $ctx; break; }
}
if ($dist === null) {
    $fail('skewed: expected a striped.distribution event on close, got none');
}
if ($dist['total'] !== 20) {
    $fail('skewed: expected total 20, got ' . $dist['total']);
}
if (\abs($dist['imbalance'] - $STRIPES) > 1e-9) {
    $fail('skewed: expected imbalance == ' . $STRIPES . ' (all in one stripe), got ' . $dist['imbalance']);
}
$nonEmpty = \count(\array_filter($dist['counts'], static fn (int $c): bool => $c > 0));
if ($nonEmpty !== 1) {
    $fail('skewed: expected exactly one non-empty stripe, got ' . $nonEmpty);
}

// ---- best case: one key per stripe -> imbalance == 1.0 ----
$events = [];
$even = new Striped();
$even->attach('fast-even-' . \getmypid(), $SIZE, $SLOTS, false, $STRIPES);
for ($st = 0; $st < $STRIPES; $st++) {
    foreach ($keysFor($even, $st, 1) as $k) { $even->set($k, 1); }
}
$even->close();

$dist = null;
foreach ($events as [$evt, $ctx]) {
    if ($evt === 'striped.distribution' && ($ctx['phase'] ?? null) === 'close') { $dist = $ctx; break; }
}
if ($dist === null) {
    $fail('even: expected a striped.distribution event on close, got none');
}
if ($dist['total'] !== $STRIPES || \abs($dist['imbalance'] - 1.0) > 1e-9) {
    $fail('even: expected total ' . $STRIPES . ' and imbalance 1.0, got total='
        . $dist['total'] . ' imbalance=' . $dist['imbalance']);
}

// ---- default silent: no sink installed -> no events, no error ----
Flat::setDiagnostics(null);
$events = [];
$quiet = new Striped();
$quiet->attach('fast-quiet-' . \getmypid(), $SIZE, $SLOTS, false, $STRIPES);
foreach ($keysFor($quiet, 0, 5) as $k) { $quiet->set($k, 1); }
$quiet->compact();
$quiet->close();

if ($events !== []) {
    $fail('default silent: a diagnostic was emitted with no sink installed: ' . \json_encode($events));
}

echo 'striped skew diag ok' . \PHP_EOL;
