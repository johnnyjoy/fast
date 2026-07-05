<?php declare(strict_types = 1);

/**
 * Contract test: Striped Capacity Semantics.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use Fast\Engine\Flat;
use Fast\Engine\Striped;
use InvalidArgumentException;

/**
 * L4 regression: capacity/size are TOTALS split evenly across stripes. The two
 * surprises that bit users are (a) a cryptic "too small" failure deep inside a
 * sub-store when size/stripes drops below the per-stripe minimum, and (b) the
 * split being invisible. This proves:
 *
 *   - a too-small total is refused UP FRONT with an actionable message that names
 *     the split, the per-stripe minimum, and the exact size to use;
 *   - the suggested minimum size is accepted;
 *   - the cold-path diagnostic now reports per_stripe_slots / per_stripe_size.
 */

if (!\extension_loaded('shmop') || !\extension_loaded('sysvsem')) {
    echo 'striped capacity semantics ok (skipped: no shared memory)' . \PHP_EOL;
    exit(0);
}

$fail = static function (string $m): never { \fwrite(\STDERR, $m . \PHP_EOL); exit(1); };

$STRIPES  = 4;
$SLOTS    = 256;
$perSlots = \intdiv($SLOTS, $STRIPES);                 // 64
$minPer   = Flat::minSegmentSize($perSlots, true);     // tagged order log (Striped uses tagged)
$required = $minPer * $STRIPES;                         // smallest accepted TOTAL size

// (a) a total whose per-stripe share is one byte under the minimum must be refused
// up front, before any sub-store is created.
$tooSmall = $required - $STRIPES;                       // perSize = $minPer - 1
$threw = false;
try {
    (new Striped())->attach('fast-cap-small-' . \getmypid(), $tooSmall, $SLOTS, false, $STRIPES);
} catch (InvalidArgumentException $e) {
    $threw = true;
    $msg = $e->getMessage();
    foreach (['stripes', 'minimum', 'increase size', (string) $STRIPES, (string) $required] as $needle) {
        if (\strpos($msg, $needle) === false) {
            $fail('validation message missing "' . $needle . '": ' . $msg);
        }
    }
}
if (!$threw) {
    $fail('too-small total size must be refused with InvalidArgumentException');
}

// (b) the exact minimum the message suggests must be accepted.
$events = [];
Flat::setDiagnostics(static function (string $event, array $ctx) use (&$events): void {
    $events[] = [$event, $ctx];
});

$ok = new Striped();
$ok->attach('fast-cap-ok-' . \getmypid(), $required, $SLOTS, false, $STRIPES);
$ok->set('k', 1);
$ok->close();

// (c) the distribution diagnostic exposes the per-stripe geometry (the split).
$dist = null;
foreach ($events as [$evt, $ctx]) {
    if ($evt === 'striped.distribution' && ($ctx['phase'] ?? null) === 'close') { $dist = $ctx; break; }
}
if ($dist === null) {
    $fail('expected a striped.distribution event on close');
}
if (($dist['per_stripe_slots'] ?? null) !== $perSlots) {
    $fail('per_stripe_slots should be ' . $perSlots . ', got ' . \json_encode($dist['per_stripe_slots'] ?? null));
}
if (($dist['per_stripe_size'] ?? null) !== \intdiv($required, $STRIPES)) {
    $fail('per_stripe_size should be ' . \intdiv($required, $STRIPES)
        . ', got ' . \json_encode($dist['per_stripe_size'] ?? null));
}

Flat::setDiagnostics(null);

echo 'striped capacity semantics ok' . \PHP_EOL;
