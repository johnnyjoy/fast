<?php declare(strict_types = 1);

/**
 * Compare value storage through real SysV shm (shm_put_var / shm_get_var).
 *
 * Models what Fast property values actually pay: extension serialize + IPC.
 *
 * Run: php tests/value_shm_bench.php
 */

require __DIR__ . '/value_codec_bench.php';

if (!\function_exists('shm_attach')) {
    fwrite(STDERR, "sysvshm extension required\n");
    exit(1);
}

function attachBenchSegment(): \SysvSharedMemory
{
    $path = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'fast_value_bench';
    \touch($path);
    $key = \ftok($path, 'v');

    $shm = @\shm_attach($key, 4 << 20, 0666);
    if ($shm === false) {
        throw new \RuntimeException('shm_attach failed');
    }

    \shm_remove($shm);
    $shm = \shm_attach($key, 4 << 20, 0666);
    if ($shm === false) {
        throw new \RuntimeException('shm_attach failed after remove');
    }

    return $shm;
}

function shmRoundTrip(\SysvSharedMemory $shm, int $slot, callable $encode, callable $decode, mixed $value, int $iterations): array
{
    $stored = $encode($value);
    \shm_put_var($shm, $slot, $stored);
    $got = $decode(\shm_get_var($shm, $slot));
    if ($got != $value && !($value === $got)) {
        if (!($value instanceof \stdClass && $got == $value)) {
            throw new \RuntimeException('round-trip mismatch');
        }
    }

    \shm_put_var($shm, $slot, $stored);
    $t0 = \hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        \shm_put_var($shm, $slot, $encode($value));
        $decode(\shm_get_var($shm, $slot));
    }

    return [
        'ns' => (\hrtime(true) - $t0) / $iterations,
        'bytes' => payloadSize($encode($value)),
    ];
}

$shm = attachBenchSegment();
$slot = 10;

$cases = [
    'int 42' => 42,
    'bool' => true,
    'null' => null,
    'string short' => 'hello',
    'string 4KiB' => \str_repeat('y', 4096),
    'array small' => ['a' => 1, 'b' => 2],
    'object' => (object) ['id' => 1, 'name' => 'x'],
];

echo 'PHP ' . PHP_VERSION . ' — value shm round-trip bench' . PHP_EOL;
echo \str_repeat('-', 68) . PHP_EOL;

foreach ($cases as $label => $value) {
    echo PHP_EOL . '=== ' . $label . ' ===' . PHP_EOL;
    echo \sprintf("%-10s %8s %14s\n", 'strategy', 'bytes', 'put+get');
    echo \str_repeat('-', 36) . PHP_EOL;

    $iters = \is_string($value) && \strlen($value) > 1000 ? 5_000 : 20_000;

    foreach (strategies() as $name => $def) {
        try {
            $result = shmRoundTrip($shm, $slot, $def['encode'], $def['decode'], $value, $iters);
            $ns = $result['ns'];
            $time = $ns >= 1_000_000
                ? \sprintf('%.2f ms', $ns / 1_000_000)
                : \sprintf('%.2f µs', $ns / 1_000);

            echo \sprintf("%-10s %8d %14s\n", $name, $result['bytes'], $time);
        } catch (\Throwable $e) {
            echo \sprintf("%-10s ERROR: %s\n", $name, $e->getMessage());
        }
    }
}

\shm_remove($shm);
