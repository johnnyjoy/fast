<?php declare(strict_types = 1);

/**
 * Contract test: Objects.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

class FastTestBox
{
    public string $label = 'box';

    public int $n = 0;
}

class FastWakeBomb
{
    public function __wakeup(): void
    {
        throw new \RuntimeException('wakeup bomb detonated');
    }
}

$fail = static function (string $message): never {
    \fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$name = 'fast-store-objects-' . \bin2hex(\random_bytes(6));

$writer = new \Fast(['name' => $name, 'persistent' => true]);

$box = new FastTestBox();
$box->n = 42;
$writer->obj = $box;
$writer->plain = (object) ['x' => 1];
$writer->close();

$reader = new \Fast(['name' => $name]);
$got = $reader->obj;
$plain = $reader->plain;

if (!$got instanceof FastTestBox || $got->n !== 42 || $got->label !== 'box') {
    $fail('FastTestBox round-trip failed');
}

if (!\is_object($plain) || !isset($plain->x) || $plain->x !== 1) {
    $fail('stdClass round-trip failed');
}

// Existence checks must not deserialize. A stored object whose __wakeup throws
// proves it: isset() must succeed without detonating, and the write itself must
// not decode the value either. Only a value read deserializes.
$reader['bomb'] = new FastWakeBomb();

if (!isset($reader['bomb'])) {
    $fail('isset on a stored object should be true without deserializing');
}

$detonated = false;
try {
    $ignored = $reader['bomb'];
} catch (\RuntimeException $e) {
    $detonated = \str_contains($e->getMessage(), 'wakeup bomb');
}

if (!$detonated) {
    $fail('value read should deserialize and trigger __wakeup');
}

$reader->destroy();

echo 'object round-trip ok' . PHP_EOL;
