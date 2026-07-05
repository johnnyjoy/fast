<?php declare(strict_types = 1);

/**
 * Contract test: Basic.
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

$s = new \Fast('fast-store-basic-' . \bin2hex(\random_bytes(6)));

$s->a = 1;
if ($s->a !== 1) {
    $fail('scalar set/get: expected 1, got ' . \var_export($s->a, true));
}

$s->a++;
if ($s->a !== 2) {
    $fail('increment: expected 2, got ' . \var_export($s->a, true));
}

$s->b = [1, 2, 3];
if ($s->b[2] !== 3) {
    $fail('array value: expected 3, got ' . \var_export($s->b[2] ?? null, true));
}

$s->nullval = null;
if ($s->nullval !== null) {
    $fail('null value not preserved');
}
if (!isset($s->b)) {
    $fail('isset on a live key should be true');
}

$s->destroy();

echo 'basic ok' . PHP_EOL;
