<?php declare(strict_types = 1);

/**
 * Contract test: Shared Update Same Size Visibility.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$name = 'fast-store-upd-same-' . \getmypid();
try {
    $cleanup = new \Fast($name);
    $cleanup->destroy();
} catch (\Throwable) {
    // best-effort cleanup only
}

// Writer creates the named store and inserts the key once.
$writer = new \Fast($name);
$writer['x'] = 'aaaa';

// Reader attaches to the *same* segment as a separate handle (separate cached
// layout state / revision), modelling an already-attached peer process.
$reader = new \Fast($name);
if ($reader['x'] !== 'aaaa') {
    $fail('reader should see initial inserted value');
}

// Same-size overwrite — must NOT rely on an insert/delete to bump the revision.
$writer['x'] = 'bbbb';
if ($writer['x'] !== 'bbbb') {
    $fail('writer should observe its own same-size overwrite');
}
if ($reader['x'] !== 'bbbb') {
    $fail('already-attached reader should see same-size overwrite (cross-process publication)');
}

// A second same-size overwrite to prove publication is repeatable.
$writer['x'] = 'cccc';
if ($reader['x'] !== 'cccc') {
    $fail('already-attached reader should see subsequent same-size overwrite');
}

$reader->destroy();

echo 'shared update same-size visibility ok' . PHP_EOL;
