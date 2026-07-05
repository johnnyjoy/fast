<?php declare(strict_types = 1);
/**
 * Daily Fast perf tracker — compare vs committed best floor (history/baseline.json).
 *
 *   php benchmarks/track.php               # run + scorecard vs best floor
 *   php benchmarks/track.php --gate        # run + fail exit 1 on perf regressions
 *   php benchmarks/track.php --record-best # merge only improved metrics into best floor
 *   php benchmarks/track.php --record      # overwrite floor with this run (avoid; use --record-best)
 *   php benchmarks/track.php --seed-runs=5 # seed/record from the median of several clean runs
 */
require __DIR__ . '/../tests/bootstrap.php';



require __DIR__ . '/track_lib.php';

use Bench\Track;

$record = false;
$recordBest = false;
$gate = false;
$sizes = [1_000, 10_000, 25_000];
$note = null;
$seedRuns = 1;

foreach (\array_slice($argv, 1) as $arg) {
    if ($arg === '--record') {
        $record = true;

        continue;
    }

    if ($arg === '--record-best') {
        $recordBest = true;

        continue;
    }

    if ($arg === '--gate') {
        $gate = true;

        continue;
    }

    if (\str_starts_with($arg, '--sizes=')) {
        $sizes = \array_map('intval', \array_filter(\explode(',', \substr($arg, 8))));

        continue;
    }

    if (\str_starts_with($arg, '--note=')) {
        $note = \substr($arg, 7);

        continue;
    }

    if (\str_starts_with($arg, '--seed-runs=')) {
        $seedRuns = \max(1, (int) \substr($arg, 12));

        continue;
    }
}

/**
 * @param list<int> $sizes
 *
 * @return array{payload: array<string, mixed>, currentBySize: array<int|string, array<string, float>>}
 */
function runTrackedExperiments(array $sizes, int $sampleRuns): array
{
    $samples = [];
    $lastPayload = null;

    for ($i = 0; $i < $sampleRuns; $i++) {
        $payload = runExperiments($sizes);
        $lastPayload = $payload;

        /** @var array<int|string, array<string, float>> $currentBySize */
        $currentBySize = [];

        foreach ($payload['experiments']['baseline']['sizes'] ?? [] as $n => $row) {
            $currentBySize[$n] = Track::metricsFromExperimentRow($row);
        }

        $samples[] = $currentBySize;
    }

    $currentBySize = $sampleRuns > 1
        ? Track::medianBySize($samples)
        : ($samples[0] ?? []);

    $payload = $lastPayload ?? [
        'generated_at' => \gmdate('c'),
        'sizes'        => $sizes,
        'experiments'   => [
            'baseline' => [
                'flags' => [],
                'sizes' => [],
            ],
        ],
    ];

    $payload['generated_at'] = \gmdate('c');
    $payload['seed_runs'] = $sampleRuns;
    $payload['experiments']['baseline']['sizes'] = [];

    foreach ($currentBySize as $n => $metrics) {
        $payload['experiments']['baseline']['sizes'][(string) $n] = [
            'matrix' => [
                'lookup_warm' => [
                    'p95_us' => $metrics['lookup_warm_p95_us'] ?? 0.0,
                ],
                'update' => [
                    'p95_us' => $metrics['update_p95_us'] ?? 0.0,
                ],
                'insert' => [
                    'p95_us' => $metrics['insert_p95_us'] ?? 0.0,
                ],
            ],
        ];
    }

    return [
        'payload' => $payload,
        'currentBySize' => $currentBySize,
    ];
}

$needsSeedMedian = $record || $recordBest || $gate;
if ($needsSeedMedian && $seedRuns < 5) {
    $seedRuns = 5;
}

$baseline = Track::loadBaseline();
$run = runTrackedExperiments($sizes, $seedRuns);
$payload = $run['payload'];
$currentBySize = $run['currentBySize'];

$seedNote = $note;
if ($needsSeedMedian) {
    $medianNote = 'median of ' . $seedRuns . ' clean sequential runs';
    $seedNote = $seedNote !== null && $seedNote !== ''
        ? $seedNote . ' — ' . $medianNote
        : $medianNote;
}

$snapshot = Track::buildSnapshot($currentBySize, $seedNote);
Track::appendLedger($snapshot);

$comparison = Track::compare($baseline, $currentBySize);
\fwrite(\STDERR, Track::formatScorecard($comparison, $baseline));

if ($gate) {
    $sizeFilter = \array_map('intval', \array_keys($currentBySize));
    $violations = Track::gateViolations($comparison, $baseline, $sizeFilter);

    if ($violations !== []) {
        \fwrite(\STDERR, Track::formatGateFailures($violations));
        exit(1);
    }

    \fwrite(\STDERR, "perf gate OK\n");
}

if ($record) {
    \fwrite(
        \STDERR,
        "warning: --record overwrites the committed best floor with this snapshot;"
        . " prefer --record-best\n",
    );
    Track::saveBaseline($snapshot, $seedNote ?? 'snapshot override');
    \fwrite(\STDERR, 'best floor overwritten: ' . Track::baselinePath() . "\n");
} elseif ($recordBest) {
    if ($baseline === null) {
        Track::seedBestFloor($snapshot, $seedNote ?? 'initial best floor');
        \fwrite(\STDERR, 'best floor seeded: ' . Track::baselinePath() . "\n");
    } elseif (Track::mergeBestIntoBaseline($baseline, $currentBySize)) {
        \fwrite(\STDERR, 'best floor updated (merged wins): ' . Track::baselinePath() . "\n");
    } else {
        \fwrite(\STDERR, "best floor unchanged (no improvements)\n");
    }
}

$ts = \gmdate('Ymd-His');
$resultDir = __DIR__ . '/results';
$reportDir = __DIR__ . '/reports';

if (!\is_dir($resultDir)) {
    \mkdir($resultDir, 0755, true);
}

if (!\is_dir($reportDir)) {
    \mkdir($reportDir, 0755, true);
}

$md = formatReport($payload);
$md .= "\n" . Track::formatMarkdown($comparison, $baseline) . "\n";

$jsonPath = $resultDir . '/' . $ts . '-track.json';
$mdPath = $reportDir . '/' . $ts . '-track.md';

\file_put_contents($jsonPath, \json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
\file_put_contents($mdPath, $md);

\fwrite(\STDERR, $jsonPath . "\n");
\fwrite(\STDERR, $mdPath . "\n");

exit(0);
