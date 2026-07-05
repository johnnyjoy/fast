<?php declare(strict_types = 1);

/**
 * Contract test: Has Metadata Only.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

final class HasMetadataProbe
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

$name = 'fast-store-has-metadata-' . \getmypid();
try {
    $cleanup = new \Fast($name);
    $cleanup->destroy();
} catch (\Throwable) {
    // best-effort cleanup only
}

$store = new \Fast(['name' => $name]);
$store['probe'] = new HasMetadataProbe();
HasMetadataProbe::$decoded = 0;

if (!isset($store['probe'])) {
    $fail('isset() should report the stored key');
}

if (HasMetadataProbe::$decoded !== 0) {
    $fail('isset() should not decode the stored value');
}

$value = $store['probe'];
if (!$value instanceof HasMetadataProbe) {
    $fail('array read should still decode the stored value');
}

if (HasMetadataProbe::$decoded === 0) {
    $fail('array read should decode the stored value');
}

$store->destroy();

echo 'has metadata only ok' . PHP_EOL;
