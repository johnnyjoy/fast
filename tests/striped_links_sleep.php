<?php declare(strict_types = 1);

/**
 * Contract test: Striped Links Sleep.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

/**
 * Striped + NON-persistent sleep contract, the multi-link branch:
 *
 *   - If the sleeping process is NOT the last linked process, the shared memory
 *     is NOT its problem: it just detaches. A live peer keeps every stripe alive,
 *     so the data survives, and on wake the sleeper simply RECONNECTS and sees it.
 *   - (The sole-sleeper "contents are lost / recreated empty" branch is covered by
 *     shared_sleep.php for the monolith and striped_basic.php for persistent.)
 *
 * Coordination uses two flag files (no dependency on the store under test).
 */

$fail = static function (string $m): never { \fwrite(\STDERR, $m . \PHP_EOL); exit(1); };

$name  = 'fast-striped-links-' . \getmypid();
$cfg   = ['name' => $name, 'capacity' => 2048, 'size' => 4 * 1024 * 1024, 'stripes' => 4]; // NON-persistent
$dir   = \sys_get_temp_dir();
$ready = $dir . '/' . $name . '.child_ready';
$woke  = $dir . '/' . $name . '.parent_woke';
@\unlink($ready); @\unlink($woke);

$waitFor = static function (string $path, float $timeout) use ($fail): void {
    $deadline = \microtime(true) + $timeout;
    while (!\is_file($path)) {
        if (\microtime(true) > $deadline) { $fail('timed out waiting for ' . \basename($path)); }
        \usleep(2000);
    }
};

// best-effort debris cleanup
try { (new \Fast($cfg))->destroy(); } catch (\Throwable) {}

// Parent: create the non-persistent striped store and populate it.
$store = new \Fast($cfg);
for ($i = 0; $i < 500; $i++) { $store["key:$i"] = ['i' => $i, 'tag' => 'parent']; }
if (\count($store) !== 500) { $fail('seed count wrong: ' . \count($store)); }

$pid = \pcntl_fork();
if ($pid === -1) { $fail('fork failed'); }

if ($pid === 0) {
    // ---- CHILD: second linked process; holds every stripe open across parent sleep.
    $peer = new \Fast($cfg);                 // 2nd link on each sub-store
    if ($peer['key:0']['i'] !== 0) { exit(11); }
    \touch($ready);                         // tell parent a peer is attached

    // Wait until the parent has slept AND woken, then prove the store survived.
    $deadline = \microtime(true) + 15.0;
    while (!\is_file($woke)) {
        if (\microtime(true) > $deadline) { exit(12); }
        \usleep(2000);
    }
    if (\count($peer) < 500) { exit(13); }            // parent's data must still be here
    if ($peer['key:250']['tag'] !== 'parent') { exit(14); }
    $peer['child:done'] = 1;                          // mutate so the parent can see the peer wrote
    $peer->close();
    exit(0);
}

// ---- PARENT: sleep while the child holds the store, then wake and reconnect.
$waitFor($ready, 15.0);

$blob = \serialize($store);     // sleep: parent detaches, but it is NOT the last link -> store survives
$store = \unserialize($blob);   // wake: reconnect to the surviving striped store
if (!($store instanceof Fast)) { $fail('wakeup did not produce a Fast'); }
if (\count($store) < 500) { $fail('non-last sleeper LOST data on wake: count=' . \count($store)); }
if ($store['key:123']['tag'] !== 'parent') { $fail('woken parent cannot see its own surviving data'); }

\touch($woke);                  // release the child to do its final checks

\pcntl_waitpid($pid, $status);
if (!\pcntl_wifexited($status) || \pcntl_wexitstatus($status) !== 0) {
    $fail('peer process failed (exit ' . (\pcntl_wifexited($status) ? \pcntl_wexitstatus($status) : 'signal') . ')');
}

if (!isset($store['child:done'])) { $fail('peer mutation not visible to woken parent'); }

@\unlink($ready); @\unlink($woke);
$store->destroy();

echo 'striped links sleep ok' . \PHP_EOL;
