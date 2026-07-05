<?php declare(strict_types = 1);

/**
 * Contract test: Each Named Callable.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;
use InvalidArgumentException;

/**
 * P2.6 each() callable contract: named callables only (function, [class,
 * staticMethod], [object, method]); closures are rejected with a clear
 * InvalidArgumentException. The public signature must not advertise \Closure.
 */

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$GLOBALS['fast_each_named_seen'] = [];

function fastEachNamedCollector(Fast $store, int|string $key, mixed $value): void
{
    $GLOBALS['fast_each_named_seen'][$key] = $value;
}

final class FastEachStaticCollector
{
    /** @var array<int|string,mixed> */
    public static array $seen = [];

    public static function collect(Fast $store, int|string $key, mixed $value): void
    {
        self::$seen[$key] = $value;
    }
}

final class FastEachObjectCollector
{
    /** @var array<int|string,mixed> */
    public array $seen = [];

    public function collect(Fast $store, int|string $key, mixed $value): void
    {
        $this->seen[$key] = $value;
    }
}

// The signature must NOT advertise Closure support.
$param = (new \ReflectionMethod(\Fast::class, 'each'))->getParameters()[0];
$type = $param->getType();
$typeString = $type instanceof \ReflectionNamedType ? $type->getName() : (string) $type;
if (\stripos($typeString, 'Closure') !== false) {
    $fail('each() signature must not mention Closure; got: ' . $typeString);
}

/**
 * @param callable(string):void $assert
 */
function fast_each_run_mode(string $mode, callable $factory, callable $assert): void
{
    $store = $factory();
    $store['a'] = 1;
    $store['b'] = 2;
    $store['c'] = 3;
    $expected = ['a' => 1, 'b' => 2, 'c' => 3];

    // named function
    $GLOBALS['fast_each_named_seen'] = [];
    $n = $store->each('Fast\\fastEachNamedCollector');
    $assert($n === 3, "$mode: each() returns count for named function");
    $assert($GLOBALS['fast_each_named_seen'] === $expected, "$mode: named function walked all entries");

    // [class-string, staticMethod]
    FastEachStaticCollector::$seen = [];
    $store->each([FastEachStaticCollector::class, 'collect']);
    $assert(FastEachStaticCollector::$seen === $expected, "$mode: [class, staticMethod] walked all entries");

    // [object, method]
    $collector = new FastEachObjectCollector();
    $store->each([$collector, 'collect']);
    $assert($collector->seen === $expected, "$mode: [object, method] walked all entries");

    // closure rejection: the string|array signature rejects closures at the
    // PHP type boundary with a TypeError before the body runs.
    $rejected = false;
    try {
        $store->each(static function (Fast $s, $k, $v): void {});
    } catch (\TypeError) {
        $rejected = true;
    }
    $assert($rejected, "$mode: closure rejected with TypeError by the signature");

    // other non-string|array inputs are likewise rejected by the signature.
    $rejectedInt = false;
    try {
        $store->each(42);
    } catch (\TypeError) {
        $rejectedInt = true;
    }
    $assert($rejectedInt, "$mode: non-string|array scalar rejected with TypeError by the signature");

    // a named function that does not exist is a contract error from the body.
    $rejectedMissing = false;
    try {
        $store->each('Fast\\fast_each_no_such_function');
    } catch (InvalidArgumentException) {
        $rejectedMissing = true;
    }
    $assert($rejectedMissing, "$mode: missing named function rejected with InvalidArgumentException");

    if ($mode === 'shared') {
        $store->destroy();
    }
}

$assert = static function (bool $cond, string $message) use ($fail): void {
    if (!$cond) {
        $fail($message);
    }
};

fast_each_run_mode('local', static fn () => new \Fast(), $assert);
fast_each_run_mode('shared', static fn () => new \Fast(['name' => 'fast-p26-each-' . \getmypid()]), $assert);

echo 'each named callable ok' . PHP_EOL;
