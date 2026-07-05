<?php declare(strict_types = 1);

/**
 * Contract test: Missing Key Lifecycle.
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

$s = new \Fast('fast-store-missing-life-' . \bin2hex(\random_bytes(6)));

if (isset($s['missing'])) {
    $fail('missing key isset should be false');
}

if ($s['missing'] !== null) {
    $fail('bare missing read should return null');
}

if (isset($s['missing'])) {
    $fail('a missing read alone must not register a key');
}

if (($s['missing'] ?? 'default') !== 'default') {
    $fail('?? should return the default for a missing key');
}

// A stored null is a live entry whose value reads as null. Per PHP semantics
// (spec line: "isset is false when the stored value is null") isset() is false,
// but the key participates in count and iteration.
$s['nullable'] = null;
if (isset($s['nullable'])) {
    $fail('isset must be false when the stored value is null');
}
if ($s['nullable'] !== null) {
    $fail('a stored null must read back as null');
}
if (\count($s) !== 1) {
    $fail('a stored null is a live entry and must be counted, got ' . \count($s));
}
$sawNullable = false;
foreach ($s as $k => $v) {
    if ($k === 'nullable') {
        $sawNullable = true;
        if ($v !== null) {
            $fail('iterating the null entry must yield null');
        }
    }
}
if (!$sawNullable) {
    $fail('a stored null entry must appear during iteration');
}

// ++ on a missing key materialises it as 1.
$s['counter']++;
if ($s['counter'] !== 1) {
    $fail('missing key ++ must yield 1');
}

// Re-typing a key across stores is lossless and keeps a single entry.
$s['shift'] = 7;
$s['shift'] = ['now' => 'array'];
$s['shift'] = 'string';
$s['shift'] = null;
if ($s['shift'] !== null) {
    $fail('string->null transition failed');
}

$s->destroy();

echo 'missing key lifecycle ok' . PHP_EOL;
