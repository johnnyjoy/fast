<?php declare(strict_types = 1);

/**
 * Contract test: Shared Stale Sync Insert.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

// Cross-process visibility: Flat reads live shared memory on every access (no
// client-side cache), so an already-attached peer must observe a writer's inserts
// AND deletes immediately — without re-attaching. This replaces the old
// "stale-sync / incremental refresh" mechanism tests: there is no stale state to
// synchronise, only a live shared view to verify.

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$name = 'fast-store-xproc-vis-' . \getmypid();
try {
    (new \Fast($name))->destroy();
} catch (\Throwable) {
    // best-effort cleanup
}

$writer = new \Fast($name);
$writer['seed'] = 'ok';

// Reader attaches once and is NEVER re-created below; it must see every later change.
$reader = new \Fast($name);
if ($reader['seed'] !== 'ok') {
    $fail('reader should see the initial seed');
}
if (isset($reader['late'])) {
    $fail('reader should not see a key that does not exist yet');
}

// Insert a brand-new key after the reader attached.
$writer['late'] = 'arrived';
if (!isset($reader['late']) || $reader['late'] !== 'arrived') {
    $fail('already-attached reader must observe a newly inserted key');
}

// Insert several more, then read them all back through the long-lived reader.
for ($i = 0; $i < 50; $i++) {
    $writer['n' . $i] = $i;
}
for ($i = 0; $i < 50; $i++) {
    if ($reader['n' . $i] !== $i) {
        $fail('reader missed inserted key n' . $i);
    }
}
if (\count($reader) !== 52) {
    $fail('reader count mismatch after inserts: expected 52, got ' . \count($reader));
}

// Deletes must be visible too.
$writer->offsetUnset('late');
if (isset($reader['late'])) {
    $fail('already-attached reader must observe a deleted key');
}
unset($writer['n0']);
if (isset($reader['n0'])) {
    $fail('reader still sees a deleted key n0');
}
if (\count($reader) !== 50) {
    $fail('reader count mismatch after deletes: expected 50, got ' . \count($reader));
}

$reader->destroy();

echo 'shared stale sync insert ok' . PHP_EOL;
