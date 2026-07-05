<?php declare(strict_types = 1);

/**
 * Contract test: Crash Recovery.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;
use Fast\Engine\Flat;

/*
 * Crash recovery: a writer killed inside its critical section leaves the seqlock
 * odd and the header counters / a slot in a half-written state. We simulate that
 * exact debris with raw shmop surgery on segment 0, then prove a fresh attach
 * (recoverIfCrashed) heals it: seqlock back to even, counters rebuilt from the
 * slot table, and a torn slot quarantined so it never returns garbage.
 *
 * Layout mirrors Flat (private consts, intentionally duplicated for raw access).
 */
const HEADER = 1024;
const SLOT   = 32;
const H_SEQ  = 8;
const H_LIVE = 12;

$fail = static function (string $m): never {
    \fwrite(STDERR, 'FAIL: ' . $m . PHP_EOL);
    exit(1);
};

$name = 'fast-store-crash-' . \bin2hex(\random_bytes(6));

$a = new \Fast(['name' => $name, 'capacity' => 1024]);
for ($i = 0; $i < 100; $i++) {
    $a["k$i"] = "v$i";
}
if (\count($a) !== 100) {
    $fail('setup: expected 100 live, got ' . \count($a));
}

// --- raw surgery: open segment 0 and inject crash debris ---
$seg = @\shmop_open(Flat::segKey($name, 0), 'w', 0, 0);
if ($seg === false) {
    $fail('could not open raw segment for surgery');
}

// 1) seqlock odd: a writer that died after seq+1 but before seq+2.
$seq = \unpack('V', \shmop_read($seg, H_SEQ, 4))[1];
\shmop_write($seg, \pack('V', $seq | 1), H_SEQ);

// 2) bogus live counter: a half-finished op left H_LIVE wrong.
\shmop_write($seg, \pack('V', 999999), H_LIVE);

// 3) tear one live slot's confirm tag so its key hash no longer validates.
$tornSlot = -1;
for ($si = 0; $si < 1024; $si++) {
    $state = \ord(\shmop_read($seg, HEADER + $si * SLOT + 22, 1));
    if ($state === 1) { // ST_LIVE
        \shmop_write($seg, \str_repeat("\xFF", 8), HEADER + $si * SLOT + 24); // smash hb2
        $tornSlot = $si;
        break;
    }
}
if ($tornSlot < 0) {
    $fail('surgery: found no live slot to tear');
}
\shmop_close($seg);

// --- reattach: triggers recoverIfCrashed on a non-orphaned existing store ---
$b = new \Fast(['name' => $name]);

// counters rebuilt: exactly one key was torn out -> 99 live, all readable.
if (\count($b) !== 99) {
    $fail('counter rebuild: expected 99 live after one torn slot, got ' . \count($b));
}

$readable = 0;
for ($i = 0; $i < 100; $i++) {
    if (isset($b["k$i"])) {
        if ($b["k$i"] !== "v$i") {
            $fail("garbage value for k$i: " . \var_export($b["k$i"], true));
        }
        $readable++;
    }
}
if ($readable !== 99) {
    $fail('expected 99 readable keys, got ' . $readable);
}

// seqlock healed to even: a fresh raw read must show an even counter.
$chk = @\shmop_open(Flat::segKey($name, 0), 'w', 0, 0);
$healed = \unpack('V', \shmop_read($chk, H_SEQ, 4))[1];
\shmop_close($chk);
if (($healed & 1) !== 0) {
    $fail('seqlock still odd after recovery: ' . $healed);
}

// the healed store must still take writes (insert + overwrite) correctly.
$b['fresh'] = 'ok';
if ($b['fresh'] !== 'ok' || \count($b) !== 100) {
    $fail('post-recovery write failed');
}

$b->close();
$a->destroy();

echo 'crash_recovery ok' . PHP_EOL;
