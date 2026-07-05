<?php declare(strict_types = 1);

/**
 * Contract test: Striped Sleep Recreate.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

/**
 * Striped + NON-persistent sleep, the SOLE-sleeper branch:
 *   The last linked process going to sleep reclaims the (non-persistent) stripes.
 *   On wake the store is missing, so it is RECREATED empty by identity and is
 *   immediately usable. Mirrors shared_sleep.php's non-persistent branch, striped.
 */

$fail = static function (string $m): never { \fwrite(\STDERR, $m . \PHP_EOL); exit(1); };

$name = 'fast-striped-recreate-' . \getmypid();
$cfg  = ['name' => $name, 'capacity' => 2048, 'size' => 4 * 1024 * 1024, 'stripes' => 4]; // NON-persistent

try { (new \Fast($cfg))->destroy(); } catch (\Throwable) {}

$store = new \Fast($cfg);
for ($i = 0; $i < 300; $i++) { $store[$i] = $i * 3; }
if (\count($store) !== 300) { $fail('seed count wrong'); }

$blob = \serialize($store);     // sole sleeper => every stripe reclaimed
$woke = \unserialize($blob);    // wake: stripes missing => recreated empty

if (!($woke instanceof Fast)) { $fail('wakeup did not produce a Fast'); }
if (\count($woke) !== 0) { $fail('non-persistent sole sleep must recreate EMPTY, got ' . \count($woke)); }
if (isset($woke[7])) { $fail('stale data leaked into a recreated store'); }

// fully usable after recreation, across all stripes
for ($i = 0; $i < 50; $i++) { $woke["fresh:$i"] = $i; }
if (\count($woke) !== 50) { $fail('recreated store not writable'); }
if ($woke['fresh:42'] !== 42) { $fail('recreated store read-back wrong'); }

$woke->destroy();

echo 'striped sleep recreate ok' . \PHP_EOL;
