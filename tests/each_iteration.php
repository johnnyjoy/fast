<?php declare(strict_types = 1);

/**
 * Contract test: Each Iteration.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

final class EachProbe
{
    /** @var array<int, array{0:int|string,1:mixed,2:string}> */
    public static array $calls = [];

    public static function collect(Fast $store, int|string $key, mixed $value, string $tag): void
    {
        self::$calls[] = [$key, $value, $tag];
    }
}

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$local = new \Fast([]);
$local['a'] = 1;
$local['b'] = ['x' => 2];
$local['c'] = null;

EachProbe::$calls = [];
$count = $local->each([EachProbe::class, 'collect'], 'local');

if ($count !== 3) {
    $fail('local each should visit three entries');
}

if (EachProbe::$calls !== [
    ['a', 1, 'local'],
    ['b', ['x' => 2], 'local'],
    ['c', null, 'local'],
]) {
    $fail('local each visitation order/value mismatch: ' . \json_encode(EachProbe::$calls));
}

$name = 'fast-store-each-' . \getmypid();
try {
    $cleanup = new \Fast($name);
    $cleanup->destroy();
} catch (\Throwable) {
    // best-effort cleanup only
}

// Non-persistent stores are reclaimed when the last process detaches, so a
// writer that hands off to a later reader across detach must be persistent.
$writer = new \Fast(['name' => $name, 'persistent' => true]);
$writer['x'] = 10;
$writer['y'] = 20;
$writer['z'] = 30;
$writer->close();

$reader = new \Fast($name);
EachProbe::$calls = [];
$count = $reader->each([EachProbe::class, 'collect'], 'shared');

if ($count !== 3) {
    $fail('shared each should visit three entries');
}

if (EachProbe::$calls !== [
    ['x', 10, 'shared'],
    ['y', 20, 'shared'],
    ['z', 30, 'shared'],
]) {
    $fail('shared each visitation order/value mismatch: ' . \json_encode(EachProbe::$calls));
}

$reader->destroy();

echo 'each iteration ok' . PHP_EOL;
