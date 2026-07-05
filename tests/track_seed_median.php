<?php declare(strict_types = 1);

/**
 * Contract test: Track Seed Median.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../benchmarks/lib/Track.php';

use Bench\Track;

function seedMedianFail(string $msg): never
{
    \fwrite(\STDERR, $msg . PHP_EOL);
    exit(1);
}

$samples = [
    [
        1000 => [
            'lookup_warm_p95_us' => 1.0,
            'update_p95_us' => 10.0,
            'insert_p95_us' => 20.0,
        ],
        10000 => [
            'lookup_warm_p95_us' => 2.0,
            'update_p95_us' => 11.0,
            'insert_p95_us' => 21.0,
        ],
    ],
    [
        1000 => [
            'lookup_warm_p95_us' => 3.0,
            'update_p95_us' => 12.0,
            'insert_p95_us' => 22.0,
        ],
        10000 => [
            'lookup_warm_p95_us' => 4.0,
            'update_p95_us' => 13.0,
            'insert_p95_us' => 23.0,
        ],
    ],
    [
        1000 => [
            'lookup_warm_p95_us' => 5.0,
            'update_p95_us' => 14.0,
            'insert_p95_us' => 24.0,
        ],
        10000 => [
            'lookup_warm_p95_us' => 6.0,
            'update_p95_us' => 15.0,
            'insert_p95_us' => 25.0,
        ],
    ],
];

$median = Track::medianBySize($samples);

if (\abs(($median[1000]['lookup_warm_p95_us'] ?? -1) - 3.0) > 0.0001) {
    seedMedianFail('median lookup warm for N=1000 should be 3.0');
}

if (\abs(($median[10000]['update_p95_us'] ?? -1) - 13.0) > 0.0001) {
    seedMedianFail('median update for N=10000 should be 13.0');
}

$pairMedian = Track::median([10, 2, 6, 4]);
if (\abs($pairMedian - 5.0) > 0.0001) {
    seedMedianFail('median of even sample set should average the middle pair');
}

$snapshot = Track::buildMedianSnapshot($samples, 'median seed');
if (($snapshot['note'] ?? null) !== 'median seed') {
    seedMedianFail('median snapshot note was not preserved');
}

if (\abs(($snapshot['sizes']['1000']['insert_p95_us'] ?? -1) - 22.0) > 0.0001) {
    seedMedianFail('median snapshot insert for N=1000 should be 22.0');
}

echo 'track seed median ok' . PHP_EOL;
