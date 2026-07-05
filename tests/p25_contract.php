<?php declare(strict_types = 1);

/**
 * Contract test: P25 Contract.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

/**
 * P2.5 public-contract audit tests.
 *
 * Verifies the governing API law: the access API is ArrayAccess, magic property
 * access is thin secondary syntax that delegates to it, isset() follows PHP
 * array semantics (null => not set), nested magic writeback works, foreach is
 * natural, and each() accepts named callables only (rejecting closures).
 */

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$assert = static function (bool $cond, string $message) use ($fail): void {
    if (!$cond) {
        $fail($message);
    }
};

// --- named callables for each() (closures are intentionally not used) ---------

$GLOBALS['p25_seen'] = [];

function p25_collect(Fast $store, int|string $key, mixed $value): void
{
    $GLOBALS['p25_seen'][$key] = $value;
}

function p25_throw(Fast $store, int|string $key, mixed $value): void
{
    throw new \RuntimeException('boom in callback');
}

final class P25StaticCollector
{
    /** @var array<int|string,mixed> */
    public static array $seen = [];

    public static function collect(Fast $store, int|string $key, mixed $value): void
    {
        self::$seen[$key] = $value;
    }
}

final class P25ObjectCollector
{
    /** @var array<int|string,mixed> */
    public array $seen = [];

    public function collect(Fast $store, int|string $key, mixed $value): void
    {
        $this->seen[$key] = $value;
    }
}

/**
 * @param callable(string):void $assert
 */
function p25_run_mode(string $mode, callable $factory, callable $assert): void
{
    $f = $factory();

    // ---- Part 2: magic <=> ArrayAccess equivalence -------------------------
    $f['x'] = 1;
    $assert($f->x === 1, "$mode: \$f['x']=1 visible via \$f->x");

    $f->x = 2;
    $assert($f['x'] === 2, "$mode: \$f->x=2 visible via \$f['x']");

    unset($f->x);
    $assert(isset($f['x']) === false, "$mode: unset(\$f->x) removes key");

    $f['arr_only'] = 10;
    $assert(isset($f->arr_only) === true, "$mode: isset(\$f->arr_only) for live key");
    unset($f['arr_only']);
    $assert(isset($f->arr_only) === false, "$mode: unset(\$f['arr_only']) seen by magic");

    // ---- Part 4: PHP-like isset() semantics --------------------------------
    $f['nul'] = null;
    $assert(isset($f['nul']) === false, "$mode: isset(null) === false (arrayaccess)");
    $assert(isset($f->nul) === false, "$mode: isset(null) === false (magic)");
    $assert($f['nul'] === null, "$mode: stored null reads back as null");

    $f['zero'] = 0;
    $assert(isset($f['zero']) === true, "$mode: isset(0) === true");

    $f['flag'] = false;
    $assert(isset($f['flag']) === true, "$mode: isset(false) === true");

    $f['empty'] = '';
    $assert(isset($f['empty']) === true, "$mode: isset('') === true");

    $assert(isset($f['never_set']) === false, "$mode: isset(absent) === false");

    // a bare read of a missing offset must NOT create a phantom entry
    $countBefore = count($f);
    $readMissingArr = $f['phantom_a'];
    $readMissingMagic = $f->phantom_m;
    $assert($readMissingArr === null && $readMissingMagic === null, "$mode: missing read returns null");
    $assert(count($f) === $countBefore, "$mode: reading a missing offset creates no phantom entry");

    // ---- Part 3: nested writeback (ArrayAccess and magic) ------------------
    $f['user'] = ['name' => 'Ada'];
    $f['user']['name'] = 'Grace';
    $assert($f['user']['name'] === 'Grace', "$mode: nested ArrayAccess writeback");

    $f->config = ['debug' => false];
    $f->config['debug'] = true;
    $assert(($f->config['debug'] ?? null) === true, "$mode: nested magic writeback (read via magic)");
    $assert(($f['config']['debug'] ?? null) === true, "$mode: nested magic writeback (read via ArrayAccess)");

    // cross: ArrayAccess set, magic nested mutate
    $f['cross'] = ['debug' => false];
    $f->cross['debug'] = true;
    $assert(($f['cross']['debug'] ?? null) === true, "$mode: arrayaccess-set + magic-mutate");

    // increment through magic
    $f->hits = 1;
    $f->hits++;
    $assert($f->hits === 2, "$mode: \$f->hits++ via magic");
    $assert($f['hits'] === 2, "$mode: magic ++ visible via ArrayAccess");

    // ---- Part 5: foreach is natural and complete --------------------------
    $g = $factory();
    for ($i = 0; $i < 1000; $i++) {
        $g["k$i"] = "v$i";
    }
    $seen = [];
    foreach ($g as $key => $value) {
        $seen[$key] = $value;
    }
    $assert(count($seen) === 1000, "$mode: foreach visits all 1000 entries");
    $assert(($seen['k10'] ?? null) === 'v10', "$mode: foreach value correct");

    // ---- Part 6: each() named-callable contract ---------------------------
    $h = $factory();
    $h['a'] = 1;
    $h['b'] = 2;
    $h['c'] = 3;

    $GLOBALS['p25_seen'] = [];
    $n = $h->each('Fast\\p25_collect');
    $assert($n === 3, "$mode: each() returns element count (function)");
    $assert($GLOBALS['p25_seen'] === ['a' => 1, 'b' => 2, 'c' => 3], "$mode: each() function callable walked all");

    P25StaticCollector::$seen = [];
    $h->each([P25StaticCollector::class, 'collect']);
    $assert(P25StaticCollector::$seen === ['a' => 1, 'b' => 2, 'c' => 3], "$mode: each() [class,staticMethod]");

    $obj = new P25ObjectCollector();
    $h->each([$obj, 'collect']);
    $assert($obj->seen === ['a' => 1, 'b' => 2, 'c' => 3], "$mode: each() [object,method]");

    // closures are rejected: the string|array signature throws TypeError at the
    // type boundary before each()'s body runs.
    $rejected = false;
    try {
        $h->each(static function (Fast $store, $key, $value): void {});
    } catch (\TypeError) {
        $rejected = true;
    }
    $assert($rejected, "$mode: each() rejects closures with TypeError");

    // a throwing callback must release the writer lock so later writes still work
    $threw = false;
    try {
        $h->each('Fast\\p25_throw');
    } catch (\RuntimeException) {
        $threw = true;
    }
    $assert($threw, "$mode: each() propagates callback exception");
    $h['after_throw'] = 'ok';
    $assert($h['after_throw'] === 'ok', "$mode: write works after each() callback threw (lock released)");

    if ($mode === 'shared') {
        $f->destroy();
        $g->destroy();
        $h->destroy();
    }
}

p25_run_mode('local', static fn () => new \Fast(), $assert);

$seq = 0;
p25_run_mode('shared', static function () use (&$seq) {
    $seq++;
    return new \Fast(['name' => 'fast-p25-' . \getmypid() . '-' . $seq]);
}, $assert);

echo 'p25 contract ok' . PHP_EOL;
