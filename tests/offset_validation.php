<?php declare(strict_types = 1);

/**
 * Contract test: Offset Validation.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

$s = new \Fast('fast-store-offset-' . \bin2hex(\random_bytes(6)));

// Only int|string offsets are valid. Everything else is a hard error on every
// access verb, and the bare-append form ($s[] = ...) is rejected too.
$badOffsets = [null, false, [], 1.5];

$expectThrow = static function (callable $fn, string $label): void {
    try {
        $fn();
        \fwrite(STDERR, 'expected InvalidArgumentException: ' . $label . PHP_EOL);
        exit(1);
    } catch (\InvalidArgumentException) {
        // expected
    }
};

foreach ($badOffsets as $offset) {
    $expectThrow(static fn () => ($s[$offset] = 'x'), 'offsetSet');
    $expectThrow(static fn () => isset($s[$offset]), 'offsetExists');
    $expectThrow(static function () use ($s, $offset): void {
        unset($s[$offset]);
    }, 'offsetUnset');
    $expectThrow(static fn () => $s[$offset], 'offsetGet');
}

$expectThrow(static fn () => ($s[] = 'x'), 'append offsetSet');

$s->destroy();

echo 'offset validation ok' . PHP_EOL;
