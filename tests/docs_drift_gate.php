<?php declare(strict_types = 1);

/**
 * docs/ contract drift gate — canonical spec must stay internally consistent.
 */

require __DIR__ . '/bootstrap.php';

$docsPath = \dirname(__DIR__) . '/docs/specification.md';

if (!\is_file($docsPath)) {
    \fwrite(\STDERR, "missing docs/specification.md\n");
    exit(1);
}

$docs = (string) \file_get_contents($docsPath);

$fail = static function (string $msg): never {
    \fwrite(\STDERR, $msg . PHP_EOL);
    exit(1);
};

foreach ([
    'ArrayAccess',
    'foreach',
    'igbinary',
    'shared memory',
] as $required) {
    if (!\str_contains($docs, $required)) {
        $fail("docs/specification.md missing required topic: {$required}");
    }
}

echo "docs drift gate ok\n";
exit(0);
