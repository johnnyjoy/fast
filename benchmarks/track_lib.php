<?php declare(strict_types = 1);
/**
 * Fast bench track harness — hrtime() on real property access only.
 *
 * Measures \Fast via Matrix\benchSize(): insert, warm lookup,
 * cold lookup, update. No inline instrumentation, env flags, or counter hooks.
 *
 * Caller must require tests/bootstrap.php before including this file.
 */
require __DIR__ . '/../tests/index_matrix_lib.php';
require __DIR__ . '/lib/Track.php';

use function Matrix\benchSize;

/**
 * @param list<int> $sizes
 *
 * @return array<string, mixed>
 */
function runExperiments(array $sizes): array
{
    $rows = [];

    foreach ($sizes as $n) {
        $rows[(string) $n] = [
            'matrix' => benchSize('fast', $n, 0x7fff + $n),
        ];
    }

    return [
        'generated_at' => \gmdate('c'),
        'sizes'        => $sizes,
        'experiments'  => [
            'baseline' => [
                'flags' => [],
                'sizes' => $rows,
            ],
        ],
    ];
}

/**
 * @param array<string, mixed> $payload
 *
 * @return string
 */
function formatReport(array $payload): string
{
    $lines = [
        '# Fast bench track',
        '',
        'Generated: ' . ($payload['generated_at'] ?? ''),
        '',
    ];

    if (($payload['seed_runs'] ?? 1) > 1) {
        $lines[] = 'Seed runs: ' . (int) $payload['seed_runs'] . ' (median snapshot)';
        $lines[] = '';
    }

    $lines = \array_merge($lines, [
        'Metrics: p95 latency (µs) from hrtime() wrapping `$shm->{key}` property access.',
        'No inline instrumentation — observable use only.',
        '',
    ]);

    foreach ($payload['experiments'] as $expName => $exp) {
        $lines[] = '## ' . $expName;
        $lines[] = '';

        foreach ($exp['sizes'] as $n => $row) {
            $matrix = $row['matrix'];

            $lines[] = '### N=' . $n;
            $lines[] = '';
            $lines[] = '| op | p95 µs |';
            $lines[] = '|---|---:|';
            $lines[] = \sprintf('| insert | %.1f |', $matrix['insert']['p95_us'] ?? 0.0);
            $lines[] = \sprintf('| lookup cold | %.1f |', $matrix['lookup_cold']['p95_us'] ?? 0.0);
            $lines[] = \sprintf('| lookup warm | %.1f |', $matrix['lookup_warm']['p95_us'] ?? 0.0);
            $lines[] = \sprintf('| update | %.1f |', $matrix['update']['p95_us'] ?? 0.0);
            $lines[] = '';
        }
    }

    return \implode("\n", $lines) . "\n";
}
