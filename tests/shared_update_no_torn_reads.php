<?php declare(strict_types = 1);

/**
 * Contract test: Shared Update No Torn Reads.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

if (!\function_exists('pcntl_fork')) {
    fwrite(STDERR, 'skip: pcntl required' . PHP_EOL);
    exit(77);
}

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

/**
 * Build a fixed-length, self-checking same-size payload for a single character.
 *
 * Every payload is exactly the same byte length (block + ':' + 8 hex checksum),
 * so each write takes the same-size overwrite path. A torn read (half "A", half
 * "B") will fail either the uniform-block check or the crc32 check.
 */
$pattern = static function (string $char): string {
    $block = \str_repeat($char, 64);
    return $block . ':' . \sprintf('%08x', \crc32($block));
};

$validate = static function (mixed $value) use ($pattern): bool {
    if (!\is_string($value)) {
        return false;
    }
    $parts = \explode(':', $value);
    if (\count($parts) !== 2 || \strlen($parts[0]) !== 64) {
        return false;
    }
    $char = $parts[0][0];
    if ($parts[0] !== \str_repeat($char, 64)) {
        return false;
    }
    return $value === $pattern($char);
};

$seconds = (int) (\getenv('FAST_TORN_SECONDS') ?: 3);
$name = 'fast-store-torn-' . \getmypid();

try {
    $cleanup = new \Fast($name);
    $cleanup->destroy();
} catch (\Throwable) {
    // best-effort cleanup only
}

$store = new \Fast($name);
$store['k'] = $pattern('A');

$writerPid = \pcntl_fork();
if ($writerPid === -1) {
    $fail('fork failed (writer)');
}

if ($writerPid === 0) {
    $w = new \Fast($name);
    $deadline = \microtime(true) + $seconds;
    $i = 0;
    while (\microtime(true) < $deadline) {
        $char = \chr(\ord('A') + ($i % 26));
        $w['k'] = $pattern($char);
        $i++;
    }
    exit(0);
}

$readerPid = \pcntl_fork();
if ($readerPid === -1) {
    $fail('fork failed (reader)');
}

if ($readerPid === 0) {
    $r = new \Fast($name);
    $deadline = \microtime(true) + $seconds;
    $reads = 0;
    $torn = 0;
    $sample = '';
    while (\microtime(true) < $deadline) {
        $value = $r['k'];
        $reads++;
        if (!$validate($value)) {
            $torn++;
            if ($sample === '') {
                $sample = \is_string($value) ? \substr($value, 0, 80) : \gettype($value);
            }
        }
    }

    echo \json_encode([
        'reads' => $reads,
        'torn' => $torn,
        'sample' => $sample,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($torn === 0 ? 0 : 1);
}

$writerStatus = 0;
$readerStatus = 0;
\pcntl_waitpid($writerPid, $writerStatus);
\pcntl_waitpid($readerPid, $readerStatus);

$store->destroy();

if (!\pcntl_wifexited($writerStatus) || \pcntl_wexitstatus($writerStatus) !== 0) {
    $fail('writer process failed');
}
if (!\pcntl_wifexited($readerStatus) || \pcntl_wexitstatus($readerStatus) !== 0) {
    $fail('reader observed a torn same-size update');
}

echo 'shared update no torn reads ok' . PHP_EOL;
