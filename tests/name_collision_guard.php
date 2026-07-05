<?php declare(strict_types = 1);

/**
 * Contract test: Name Collision Guard.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;
use Fast\Engine\Flat;

/**
 * M3 regression: a crc32 name-key collision must be refused, never silently
 * served as another store's data.
 *
 * The segment key is a 31-bit crc32 of the store name, so two different names can
 * alias the same segment (a real collision is found here by a birthday search —
 * ~46k high-entropy draws). Opening the second colliding name must throw a clear
 * collision error; the first store must remain intact and readable.
 */

if (!\extension_loaded('shmop') || !\extension_loaded('sysvsem')) {
    \fwrite(\STDERR, 'skip: shmop + sysvsem required' . \PHP_EOL);
    exit(77);
}

$fail = static function (string $m): never { \fwrite(\STDERR, $m . \PHP_EOL); exit(1); };

// Find two distinct names that collide on segment 0. crc32 is linear, so
// high-entropy names are required for uniform (birthday-rate) collisions.
$seen = [];
$a = $b = null;
for ($i = 0; $i < 5_000_000; $i++) {
    $name = 'rax-cg-' . \bin2hex(\random_bytes(6));
    $k = Flat::segKey($name, 0);
    if (isset($seen[$k]) && $seen[$k] !== $name) { $a = $seen[$k]; $b = $name; break; }
    $seen[$k] = $name;
}
if ($a === null || $b === null) {
    $fail('could not find a crc32 collision pair (unexpected for a 31-bit key)');
}
if (Flat::segKey($a, 0) !== Flat::segKey($b, 0)) {
    $fail('search returned a non-colliding pair');
}

$cfg = static fn (string $n): array => ['name' => $n, 'capacity' => 64, 'size' => 65536];

// Clean any debris from a prior aborted run (best effort).
foreach ([$a, $b] as $n) { try { (new \Fast($cfg($n)))->destroy(); } catch (\Throwable) {} }

// Create + populate store A.
$storeA = new \Fast($cfg($a));
$storeA['owner'] = $a;
$storeA['n']     = 42;

// Opening the colliding name B must throw — not return A's data.
$threw = false;
try {
    $storeB = new \Fast($cfg($b));
    // If we get here the guard failed; report exactly how it aliased.
    $owner = isset($storeB['owner']) ? (string) $storeB['owner'] : '(none)';
    try { $storeA->destroy(); } catch (\Throwable) {}
    $fail('collision NOT refused: opening "' . $b . '" aliased "' . $a . '" (B[owner]=' . $owner . ')');
} catch (\RuntimeException $e) {
    $threw = true;
    if (\stripos($e->getMessage(), 'collision') === false) {
        try { $storeA->destroy(); } catch (\Throwable) {}
        $fail('threw, but not a recognizable collision error: ' . $e->getMessage());
    }
}

if (!$threw) { $fail('expected a collision exception'); }

// Store A must be untouched and fully readable after the refused collision.
if ($storeA['owner'] !== $a || $storeA['n'] !== 42 || \count($storeA) !== 2) {
    try { $storeA->destroy(); } catch (\Throwable) {}
    $fail('store A was disturbed by the refused collision attach');
}

$storeA->destroy();
foreach ([$a, $b] as $n) { try { (new \Fast($cfg($n)))->destroy(); } catch (\Throwable) {} }

echo 'name collision guard ok' . \PHP_EOL;
