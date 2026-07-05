<?php declare(strict_types = 1);

/**
 * Contract test: Missing.
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

$s = new \Fast('fast-store-missing-' . \bin2hex(\random_bytes(6)));

if (isset($s['missing'])) {
    $fail('isset on a missing key should be false');
}

// Reading a missing key returns null and must NOT create an entry (spec: array
// semantics Fast must match).
if ($s['missing'] !== null) {
    $fail('reading a missing key should return null');
}

if (isset($s['missing'])) {
    $fail('a read-only miss must not register a key');
}

if (\count($s) !== 0) {
    $fail('a missing read must not change count');
}

if (($s['missing'] ?? 'Hello') !== 'Hello') {
    $fail('?? on a missing offset should return the default');
}

// Non int|string offsets are a hard error.
foreach ([1.5, true] as $badOffset) {
    try {
        $ignored = $s[$badOffset];
        $fail('invalid offset type should throw');
    } catch (\InvalidArgumentException) {
        // expected
    }
}

// ++ on a missing key materialises it as 1, exactly like a native array.
$s['counter']++;
if ($s['counter'] !== 1) {
    $fail('missing key ++ expected 1 got ' . \var_export($s['counter'], true));
}
if (\count($s) !== 1) {
    $fail('++ on a missing key should create exactly one entry');
}

$s->destroy();

echo 'missing key ok' . PHP_EOL;
