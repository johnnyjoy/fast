<?php declare(strict_types = 1);

/**
 * Contract test: Diagnostics Sink.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use Fast\Engine\Flat;

/**
 * M6 regression: otherwise-silent failures must be observable via the opt-in
 * diagnostic sink, and the default (no sink) must stay completely silent.
 *
 * We force a REAL failure: remove a store's semaphore out from under it, then
 * drive Flat::removeSem() — its sem_remove() now returns false, the exact kind of
 * leak that used to vanish. With FAST_DIAG=stderr the failure must surface
 * on stderr; with the env unset, nothing must be emitted.
 *
 * The trigger runs in a child process (FAST_DIAG_TRIGGER=1) so the env auto-install
 * path is exercised end-to-end exactly as an operator would enable it.
 */

if (!\extension_loaded('shmop') || !\extension_loaded('sysvsem')) {
    \fwrite(\STDERR, 'skip: shmop + sysvsem required' . \PHP_EOL);
    exit(77);
}

// ---- child / trigger mode: provoke a real sem_remove() failure ----
if (\getenv('FAST_DIAG_TRIGGER') === '1') {
    $name = 'fast-diag-' . \getmypid();
    $f = new Flat();
    $f->attach($name, 65536, 64, false);

    // Remove the store's semaphore behind its back (same key Flat derives).
    $semKey = \crc32('fast-flat-sem:' . $name) & 0x7fffffff;
    $s = @\sem_get($semKey, 1, 0600, true);
    if ($s !== false) { @\sem_remove($s); }

    // Drive removeSem(): @sem_remove() now returns false -> diag('sem_remove_failed').
    $m = new \ReflectionMethod(Flat::class, 'removeSem');
    $m->setAccessible(true);
    $m->invoke($f);

    // Best-effort cleanup of the leaked segment + lock file (sem is already gone).
    $h = @\shmop_open(Flat::segKey($name, 0), 'w', 0600, 0);
    if ($h !== false) { @\shmop_delete($h); }
    @\unlink((\is_dir('/dev/shm') && \is_writable('/dev/shm') ? '/dev/shm' : \sys_get_temp_dir())
        . '/fast-flat-' . Flat::segKey($name, 0) . '.lock');
    exit(0);
}

// ---- parent mode: run the trigger with the sink on and off, inspect stderr ----
$fail = static function (string $m): never { \fwrite(\STDERR, $m . \PHP_EOL); exit(1); };

$runStderr = static function (?string $diagMode): string {
    $env = 'FAST_DIAG_TRIGGER=1 ';
    if ($diagMode !== null) { $env .= 'FAST_DIAG=' . \escapeshellarg($diagMode) . ' '; }
    // 2>&1 1>/dev/null: capture stderr only (stdout discarded).
    $cmd = $env . \escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg(__FILE__) . ' 2>&1 1>/dev/null';
    return (string) \shell_exec($cmd);
};

$on  = $runStderr('stderr');
$off = $runStderr(null);

if (\strpos($on, 'sem_remove_failed') === false) {
    $fail('sink ON: expected "sem_remove_failed" on stderr, got: ' . \trim($on));
}
if (\strpos($on, 'Fast ') === false) {
    $fail('sink ON: diagnostic line missing the "Fast" prefix, got: ' . \trim($on));
}
if (\strpos($off, 'sem_remove_failed') !== false) {
    $fail('sink OFF (default): a diagnostic leaked to stderr: ' . \trim($off));
}
if (\trim($off) !== '') {
    $fail('sink OFF (default): expected total silence, got: ' . \trim($off));
}

echo 'diagnostics sink ok' . \PHP_EOL;
