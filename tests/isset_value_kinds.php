<?php declare(strict_types = 1);

/**
 * Contract test: Isset Value Kinds.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

/**
 * isset() is a first-class, metadata-only access operation (spec: "Access
 * contract"). It answers presence + null-ness from the stored value type without
 * deserializing the payload, and it matches PHP array semantics: a stored null is
 * NOT set, while stored false/0/0.0/"" ARE set. Probing a missing key must never
 * materialize an entry.
 *
 * Deserialization avoidance for complex values is proven separately in
 * has_metadata_only.php; this test pins the scalar value-type truth table for both
 * ArrayAccess isset() and magic __isset().
 */

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$name = 'fast-store-isset-kinds-' . \getmypid();
try {
    (new \Fast($name))->destroy();
} catch (\Throwable) {
    // best-effort pre-clean
}

$store = new \Fast(['name' => $name]);

// missing => not set (and must not create the entry)
if (isset($store['missing'])) {
    $fail('isset() on a missing key must be false');
}
if (\count($store) !== 0) {
    $fail('probing a missing key must not materialize an entry');
}
if (isset($store['missing'])) {
    $fail('a missing key must still be missing after an isset() probe');
}

// stored null => not set (PHP array parity), but the key still exists for reads
$store['nil'] = null;
if (isset($store['nil'])) {
    $fail('isset() on a stored null must be false');
}
if (isset($store->nil)) {
    $fail('magic __isset on a stored null must be false');
}

// falsy-but-present scalars => set
$present = [
    'bool_false' => false,
    'int_zero'   => 0,
    'float_zero' => 0.0,
    'empty_str'  => '',
];
foreach ($present as $key => $value) {
    $store[$key] = $value;
    if (!isset($store[$key])) {
        $fail("isset() must be true for a stored " . $key);
    }
    if (!isset($store->{$key})) {
        $fail("magic __isset must be true for a stored " . $key);
    }
}

$store->destroy();

echo 'isset value kinds ok' . PHP_EOL;
