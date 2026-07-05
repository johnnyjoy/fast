<?php declare(strict_types = 1);

/**
 * Fast perf regression gate — N=1000 track matrix vs committed best floor
 * (benchmarks/history/baseline.json, per-metric bests).
 *
 * Fails when any gated latency metric exceeds best floor + tolerance (5%).
 * Latency only — hrtime() on real property access, no inline instrumentation.
 */
require __DIR__ . '/bootstrap.php';



require __DIR__ . '/../benchmarks/track_lib.php';

use Bench\Track;

/** @var list<int> */
const GATE_SIZES = [1_000];

if (\in_array('--selftest', $argv ?? [], true)) {
    $baseline = [
        'sizes' => [
            '1000' => [
                'lookup_warm_p95_us' => 10.0,
            ],
        ],
        'gate' => ['sizes' => [1000]],
    ];
    $current = [
        1000 => [
            'lookup_warm_p95_us' => 12.0,
        ],
    ];
    $comparison = Track::compare($baseline, $current);
    $violations = Track::gateViolations($comparison, $baseline, GATE_SIZES);

    if ($violations === [] || isset($violations[0]['reason'])) {
        \fwrite(STDERR, "selftest FAIL: 20% latency regression should fail gate\n");
        exit(1);
    }

    $withinBase = [
        'sizes' => ['1000' => ['lookup_warm_p95_us' => 10.0]],
        'gate' => ['sizes' => [1000]],
    ];
    $withinNow = [1000 => ['lookup_warm_p95_us' => 10.4]];
    $withinCmp = Track::compare($withinBase, $withinNow);
    $withinViolations = Track::gateViolations($withinCmp, $withinBase, GATE_SIZES);

    if ($withinViolations !== []) {
        \fwrite(STDERR, "selftest FAIL: 4% latency change should pass 5% tolerance\n");
        exit(1);
    }

    echo "perf track gate selftest ok\n";
    exit(0);
}

$baseline = Track::loadBaseline();

if ($baseline === null) {
    \fwrite(
        STDERR,
        'perf track gate: missing best floor — run: php benchmarks/track.php --record-best' . PHP_EOL,
    );
    exit(1);
}

$payload = runExperiments(GATE_SIZES);

/** @var array<int|string, array<string, float>> $currentBySize */
$currentBySize = [];

foreach ($payload['experiments']['baseline']['sizes'] ?? [] as $n => $row) {
    $currentBySize[$n] = Track::metricsFromExperimentRow($row);
}

$comparison = Track::compare($baseline, $currentBySize);
$violations = Track::gateViolations($comparison, $baseline, GATE_SIZES);

if ($violations !== []) {
    \fwrite(\STDERR, Track::formatGateFailures($violations));
    exit(1);
}

echo 'perf track gate ok @ N=' . \implode(',', GATE_SIZES) . PHP_EOL;
exit(0);
