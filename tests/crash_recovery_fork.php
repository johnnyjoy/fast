<?php declare(strict_types = 1);

/**
 * Contract test: Crash Recovery Fork.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/engine_access.php';

use \Fast;
use Fast\Engine\Flat;

use function Fast\fast_test_supports_shared_memory;

/*
 * End-to-end crash recovery under a REAL SIGKILL mid-write (not simulated debris).
 *
 * A peer writer is killed -9 while it is inside the write critical section, then a
 * surviving process reattaches and must recover. This proves two things the
 * single-process surgery test (crash_recovery.php) cannot:
 *
 *   1. SEM_UNDO actually releases the write semaphore when the holder dies. If it
 *      did not, the recovering attach would deadlock in lock() forever — so the
 *      whole H2 recovery feature would be worse than useless. A SIGALRM watchdog
 *      turns that hang into a clear failure instead of an infinite stall.
 *   2. The debris a genuine interrupted write leaves (odd seqlock, off counters,
 *      a possibly torn slot) is healed: the store comes back consistent, every
 *      pre-crash committed key survives intact, and no read returns garbage.
 *
 * The child writes large segment-spanning values to widen the in-critical-section
 * window so a randomly-timed kill reliably lands mid-write; we confirm by reading
 * the raw seqlock right after the kill and requiring at least one odd catch.
 */

if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
    \fwrite(STDERR, 'skip: pcntl + posix required for the fork crash test' . PHP_EOL);
    exit(77);
}
if (!fast_test_supports_shared_memory()) {
    \fwrite(STDERR, 'skip: shmop + sysvsem required' . PHP_EOL);
    exit(77);
}

const H_SEQ = 8; // mirrors Flat::H_SEQ (private)

$fail = static function (string $m): never {
    \fwrite(STDERR, 'FAIL: ' . $m . PHP_EOL);
    exit(1);
};

$name = 'fast-store-killwrite-' . \getmypid();
$size = 65536;       // small segments so big values span several -> long write window
$capacity = 256;

// best-effort clean slate
try { (new \Fast(['name' => $name, 'capacity' => $capacity, 'size' => $size]))->destroy(); } catch (\Throwable) {}

// Surviving peer + owner: keeps the store alive across every child crash, and
// holds the committed "anchor" keys whose integrity recovery must preserve.
$keeper = new \Fast(['name' => $name, 'capacity' => $capacity, 'size' => $size]);
$anchors = 40;
for ($i = 0; $i < $anchors; $i++) {
    $keeper["anchor:$i"] = "ANCHOR-VALUE-$i";
}

// Watchdog: any post-crash operation that hangs => SEM_UNDO failed / deadlock.
\pcntl_async_signals(true);
\pcntl_signal(\SIGALRM, static function () use ($fail): void {
    $fail('post-crash operation hung — write semaphore was NOT released on SIGKILL (SEM_UNDO) or recovery deadlocked');
});

$rawSeq = static function (string $name): ?int {
    return fast_test_raw_seq($name);
};

$caughtOdd = 0;
$cleanRecoveries = 0;
$maxAttempts = 30;

for ($attempt = 1; $attempt <= $maxAttempts && ($caughtOdd < 1 || $cleanRecoveries < 3); $attempt++) {
    $pid = \pcntl_fork();
    if ($pid === -1) { $fail('fork failed'); }

    if ($pid === 0) {
        // Child peer: hammer the store with big spanning writes until killed.
        // No cleanup runs on SIGKILL.
        $child = new \Fast(['name' => $name, 'capacity' => $capacity, 'size' => $size]);
        $big = \str_repeat('Z', 90000);   // > one segment payload -> wrSpan loop under lock
        // Bounded key set => endless big-value OVERWRITES, never fills the
        // directory, so the loop only ever ends via the parent's SIGKILL.
        for ($i = 0; ; $i++) {
            $child['churn:' . ($i % 24)] = $big . $i;
        }
    }

    // Parent: let the child get mid-write, then kill it uncleanly.
    \usleep(\random_int(500, 6000));
    \posix_kill($pid, \SIGKILL);
    \pcntl_waitpid($pid, $status);
    if (!\pcntl_wifsignaled($status)) { $fail('child was expected to die from SIGKILL'); }

    // Did we catch it inside the critical section? (odd seqlock == mid-write)
    $seq = $rawSeq($name);
    if ($seq === null) { $fail('store segment vanished after child crash (peer should keep it alive)'); }
    if (($seq & 1) === 1) { $caughtOdd++; }

    // Reattach: triggers recoverIfCrashed(). If the child died holding the lock,
    // this is exactly where a missing SEM_UNDO would deadlock — hence the alarm.
    \pcntl_alarm(15);
    $revived = new \Fast(['name' => $name, 'capacity' => $capacity, 'size' => $size]);

    // Seqlock must be healed back to even.
    $after = $rawSeq($name);
    if ($after === null || ($after & 1) !== 0) {
        $fail('seqlock not healed after recovery (got ' . \var_export($after, true) . ')');
    }

    // Every committed anchor must survive intact — no loss, no garbage.
    for ($i = 0; $i < $anchors; $i++) {
        if (!isset($revived["anchor:$i"])) { $fail("anchor:$i lost after crash recovery"); }
        if ($revived["anchor:$i"] !== "ANCHOR-VALUE-$i") {
            $fail("anchor:$i corrupted: " . \var_export($revived["anchor:$i"], true));
        }
    }

    // Counter/iteration consistency: count() must equal what is actually readable,
    // and every yielded value must decode (a torn/quarantined slot must not surface).
    $seen = 0;
    foreach ($revived as $k => $v) {
        $seen++;
        if ($v === null && \strncmp((string) $k, 'anchor:', 7) === 0) {
            $fail("anchor key $k decoded to null (garbage)");
        }
    }
    if ($seen !== \count($revived)) {
        $fail('iteration count ' . $seen . ' != count() ' . \count($revived) . ' after recovery');
    }

    // The recovered store must still take writes correctly.
    $probe = "postcrash:$attempt";
    $revived[$probe] = 'live';
    if ($revived[$probe] !== 'live') { $fail('recovered store rejected a new write'); }

    \pcntl_alarm(0);
    $revived->close();
    $cleanRecoveries++;
}

$keeper->destroy();

if ($caughtOdd < 1) {
    $fail('never caught a mid-write (odd seqlock) across ' . $maxAttempts . ' kills — could not exercise the real-crash repair path');
}

echo 'crash recovery fork ok (real mid-write catches=' . $caughtOdd
    . ', clean recoveries=' . $cleanRecoveries . ')' . PHP_EOL;
