<?php declare(strict_types = 1);

/**
 * Contract test: Iterator Current Single Decode.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

final class IteratorCurrentProbe
{
    public static int $decoded = 0;

    public function __serialize(): array
    {
        return ['value' => 1];
    }

    public function __unserialize(array $data): void
    {
        self::$decoded++;
    }

    public function __wakeup(): void
    {
        self::$decoded++;
    }
}

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$name = 'fast-store-iterator-current-' . \getmypid();
try {
    $cleanup = new \Fast($name);
    $cleanup->destroy();
} catch (\Throwable) {
    // best-effort cleanup only
}

$writer = new \Fast(['name' => $name]);
$writer['probe'] = new IteratorCurrentProbe();

$reader = new \Fast(['name' => $name]);

// A value must be decoded exactly once per iteration position, and repeated
// current() calls at the same position must reuse the cached decode rather than
// re-deserialising the stored bytes. (Decoding happens inside the seqlock-validated
// read so it is torn-safe; we assert it is not redundant.)
IteratorCurrentProbe::$decoded = 0;
$reader->rewind();

$value = $reader->current();
if (!$value instanceof IteratorCurrentProbe) {
    $fail('current() should return the stored object');
}
$reader->current();
$reader->current();

if (IteratorCurrentProbe::$decoded !== 1) {
    $fail('iteration should decode the stored object exactly once (got ' . IteratorCurrentProbe::$decoded . ')');
}

$reader->destroy();
$writer->close();

echo 'iterator current single decode ok' . PHP_EOL;
