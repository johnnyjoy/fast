<?php declare(strict_types = 1);

/**
 * Contract test: No Public Engine Leaks.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

/**
 * P2.6 regression guard: the specific engine/codec leaks closed in this pass
 * must never come back as public methods on Fast.
 */

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

// Engine read / Journal log internals that previously leaked through Fast.
$forbiddenEngine = [
    'layoutTryGet',
    'appendRecordFrame',
    'recordLog',
    'directoryLog',
    'orderLog',
];

// Binary codec helpers (engine-internal); must never be re-exported on Fast.
$forbiddenCodec = [
    'buildRecordFrame',
    'buildRecordFrameFromEncoded',
    'readRecordFrame',
    'readRecordFrameMeta',
    'encodeU32', 'decodeU32',
    'encodeU64', 'decodeU64',
    'encodeF64', 'decodeF64',
    'zigZagEncode64', 'zigZagDecode64',
    'encodeValue', 'decodeValue',
    'encodeKey', 'decodeKey', 'normalizeKey',
];

// Method-style CRUD that the access API replaced.
$forbiddenCrud = [
    'set', 'get', 'has', 'delete', 'tryGet', 'setMany', 'deleteMany',
];

// Wildcard delegator must stay gone.
$forbiddenMagic = ['__call', '__callStatic'];

// Attach/open/shard/segment machinery and maintenance methods must never be
// public: the public lifecycle is construct -> use -> close()/destroy() only.
$forbiddenLifecycle = [
    'attachShared', 'openShared', 'attach', 'open',
    'attachShard', 'attachSegment',
    'detach', 'compact', 'stats',
    'supportsSharedMemory', 'supportsIgbinary',
];

$ref = new \ReflectionClass(\Fast::class);

$check = static function (array $names, string $bucket) use ($ref, $fail): void {
    foreach ($names as $name) {
        if ($ref->hasMethod($name)) {
            $method = $ref->getMethod($name);
            if ($method->isPublic()) {
                $fail("$bucket leak: Fast::$name() must not be public");
            }
        }
    }
};

$check($forbiddenEngine, 'engine');
$check($forbiddenCodec, 'codec');
$check($forbiddenCrud, 'crud');
$check($forbiddenMagic, 'wildcard');
$check($forbiddenLifecycle, 'lifecycle');

// A normal user must not be able to drive the engine through the facade.
$store = new \Fast();
if (\method_exists($store, 'layoutTryGet')) {
    $fail('Fast::layoutTryGet must not exist on instances');
}

echo 'no public engine leaks ok' . PHP_EOL;
