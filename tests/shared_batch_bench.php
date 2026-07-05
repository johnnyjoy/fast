<?php declare(strict_types = 1);

/**
 * Contract test: Shared Batch Bench.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

// Grouped-operation throughput. Fast's grouped/locked-walk API is each() (there
// is no batch() method on Fast; ArrayAccess + magic are the access API, and each()
// is the one-lock grouped walk). This measures: (a) bulk ArrayAccess writes, and
// (b) each() walk throughput (single lock for the whole walk).

$n = (int) ($argv[1] ?? 20000);

$name = 'fast-store-batch-bench-' . \bin2hex(\random_bytes(6));
try {
    (new \Fast(['name' => $name, 'capacity' => 1 << 16, 'size' => 134217728]))->destroy();
} catch (\Throwable) {
}

$f = new \Fast(['name' => $name, 'capacity' => 1 << 16, 'size' => 134217728]);

echo "shared grouped-operation bench (n={$n})\n\n";

$t = \hrtime(true);
for ($i = 0; $i < $n; $i++) {
    $f['k' . $i] = 'v' . $i;
}
$sec = (\hrtime(true) - $t) / 1e9;
\printf("%-26s %12s ops/sec  %8.3f us/op\n", 'bulk ArrayAccess writes', \number_format((int) ($n / $sec)), $sec / $n * 1e6);

// each(): single-lock grouped walk invoking a named callback per element.
$t = \hrtime(true);
$walked = $f->each([FastBatchBenchSum::class, 'add']);
$sec = (\hrtime(true) - $t) / 1e9;
\printf("%-26s %12s elem/sec  %8.3f us/elem  (walked %d)\n", 'each() grouped walk', \number_format((int) ($walked / $sec)), $sec / $walked * 1e6, $walked);

$f->destroy();

final class FastBatchBenchSum
{
    public static int $sum = 0;

    public static function add(Fast $store, int|string $key, mixed $value): void
    {
        self::$sum += \strlen((string) $value);
    }
}
