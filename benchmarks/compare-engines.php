<?php declare(strict_types = 1);

/**
 * Compare Flat and Striped on the same tasks — visitor-facing benchmark.
 *
 * Runs three scenarios that answer the question "which engine for my workload?":
 *
 *   1. Many writers, keys spread     — Striped's intended job; Flat vs Striped
 *   2. Many writers, one hot key     — Flat's world; Striped should not help
 *   3. Single writer, keys spread    — Flat default; Striped overhead only
 *
 * Usage:
 *   php benchmarks/compare-engines.php
 *   php benchmarks/compare-engines.php --workers=4,8 --stripes=8 --ops=2000
 *   php benchmarks/compare-engines.php --json > results.json
 */

require __DIR__ . '/../tests/bootstrap.php';
require __DIR__ . '/lib/Stats.php';
require __DIR__ . '/lib/Bench.php';
require __DIR__ . '/lib/EngineCompare.php';

if (!\function_exists('pcntl_fork')) {
    \fwrite(STDERR, "This comparison needs pcntl (multi-process PHP).\n");
    exit(2);
}

$workers = [4, 8];
$stripes = 8;
$opsPerWorker = 2000;
$keySpace = Bench\EngineCompare::DEFAULT_KEY_SPACE;
$seed = 42;
$json = false;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];

    if ($arg === '--json') {
        $json = true;
    } elseif (\str_starts_with($arg, '--workers=')) {
        $workers = \array_map('intval', \explode(',', \substr($arg, 10)));
    } elseif (\str_starts_with($arg, '--stripes=')) {
        $stripes = (int) \substr($arg, 10);
    } elseif (\str_starts_with($arg, '--ops=')) {
        $opsPerWorker = (int) \substr($arg, 6);
    } elseif (\str_starts_with($arg, '--key-space=')) {
        $keySpace = (int) \substr($arg, 12);
    } elseif ($arg === '--help' || $arg === '-h') {
        \fwrite(STDERR, "Usage: php benchmarks/compare-engines.php [--workers=4,8] [--stripes=8] [--ops=2000] [--json]\n");
        exit(0);
    }
}

if ($stripes < 2 || ($stripes & ($stripes - 1)) !== 0) {
    \fwrite(STDERR, "--stripes must be a power of two >= 2\n");
    exit(1);
}

$matrix = Bench\EngineCompare::runMatrix($workers, $stripes, $opsPerWorker, $keySpace, $seed);

if ($json) {
    echo \json_encode($matrix, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

echo Bench\EngineCompare::formatHuman($matrix);
exit(0);
