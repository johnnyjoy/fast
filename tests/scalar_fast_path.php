<?php declare(strict_types = 1);

/**
 * Contract test: Scalar Fast Path.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

$fail = static function (string $message): never {
    \fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$s = new \Fast('fast-store-scalar-' . \bin2hex(\random_bytes(6)));

// Exact round-trip fidelity for every scalar type, mixed with arrays/objects.
$cases = [
    'null'       => null,
    'true'       => true,
    'false'      => false,
    'zero'       => 0,
    'one'        => 1,
    'neg'        => -1,
    'int_max'    => PHP_INT_MAX,
    'int_min'    => PHP_INT_MIN,
    'big_neg'    => -9223372036854775807,
    'float'      => 3.14159,
    'float_neg'  => -2.5,
    'float_zero' => 0.0,
    'empty_str'  => '',
    'short_str'  => 'hello',
    'bin_str'    => "\x00\x01\xFF binary\x00tail",
    'long_str'   => \str_repeat('x', 9000),
    'arr'        => ['a' => 1, 'b' => [2, 3], 'c' => null],
    'obj'        => (object) ['x' => 1, 'y' => 'z'],
];

foreach ($cases as $k => $v) {
    $s[$k] = $v;
}

foreach ($cases as $k => $v) {
    $got = $s[$k];
    if ($got !== $v && !(\is_object($v) && $got == $v)) {
        $fail("round-trip mismatch for {$k}: " . \var_export($got, true));
    }
}

// Types preserved precisely (no int/string/bool coercion).
if ($s['zero'] !== 0 || $s['one'] !== 1) {
    $fail('int identity lost');
}
if ($s['false'] !== false || $s['true'] !== true) {
    $fail('bool identity lost');
}
if (\gettype($s['float']) !== 'double') {
    $fail('float type lost');
}
if ($s['long_str'] !== \str_repeat('x', 9000)) {
    $fail('long string truncated');
}

// A stored null reads as null and is a live, counted entry (isset is false on
// null per PHP semantics, so existence is proved via count, not isset).
if ($s['null'] !== null) {
    $fail('stored null must read back as null');
}
$nullPresent = false;
foreach ($s as $k => $v) {
    if ($k === 'null') {
        $nullPresent = true;
    }
}
if (!$nullPresent) {
    $fail('stored null must be a live entry');
}

// Re-typing the same key (scalar -> array -> string -> null) is lossless.
$s['shift'] = 42;
if ($s['shift'] !== 42) {
    $fail('int store failed');
}
$s['shift'] = ['now' => 'array'];
if ($s['shift'] !== ['now' => 'array']) {
    $fail('int->array transition failed');
}
$s['shift'] = 'now string';
if ($s['shift'] !== 'now string') {
    $fail('array->string transition failed');
}
$s['shift'] = null;
if ($s['shift'] !== null) {
    $fail('string->null transition failed');
}

// Compound assignment on a scalar int.
$s['ctr'] = 10;
$s['ctr']++;
if ($s['ctr'] !== 11) {
    $fail('compound assign on scalar int failed: ' . \var_export($s['ctr'], true));
}

$s->destroy();
echo 'scalar fast path ok' . PHP_EOL;
