<?php declare(strict_types = 1);

/**
 * Contract test: Pending Writeback.
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

$store = new \Fast();

$store['user'] = ['name' => 'Ada'];
$store['user']['name'] = 'Grace';

if ($store['user']['name'] !== 'Grace') {
    $fail('nested array mutation should write back');
}

$store->flags = 1;
$store->flags++;

if ($store->flags !== 2) {
    $fail('post-increment mutation should write back');
}

$store['counter'] = 1;
$store['counter']++;

if ($store['counter'] !== 2) {
    $fail('array post-increment mutation should write back');
}

echo 'pending writeback ok' . PHP_EOL;
