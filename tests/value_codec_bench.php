<?php declare(strict_types = 1);

/**
 * Compare value storage codecs (in-memory, no shm).
 *
 * Strategies:
 *   native      — return value as-is (models shm_put_var with typed slots)
 *   serialize   — always serialize()/unserialize()
 *   hybrid      — scalars as-is; array/object/resource via serialize
 *   binary      — type-tagged binary blob string (scalars raw; complex serialized)
 *   json        — scalars as-is; array/object via json_encode/json_decode
 *
 * Run: php tests/value_codec_bench.php
 */

const VAL_NULL = 0;
const VAL_BOOL = 1;
const VAL_INT = 2;
const VAL_FLOAT = 3;
const VAL_STR = 4;
const VAL_COMPLEX = 5;

function isScalarValue(mixed $value): bool
{
    return $value === null
        || \is_bool($value)
        || \is_int($value)
        || \is_float($value)
        || \is_string($value);
}

function encodeNative(mixed $value): mixed
{
    return $value;
}

function decodeNative(mixed $stored): mixed
{
    return $stored;
}

function encodeSerialize(mixed $value): string
{
    return \serialize($value);
}

function decodeSerialize(string $stored): mixed
{
    return \unserialize($stored, ['allowed_classes' => true]);
}

function encodeHybrid(mixed $value): mixed
{
    if (isScalarValue($value)) {
        return $value;
    }

    return 'C:' . \serialize($value);
}

function decodeHybrid(mixed $stored): mixed
{
    if (\is_string($stored) && \str_starts_with($stored, 'C:')) {
        return \unserialize(\substr($stored, 2), ['allowed_classes' => true]);
    }

    return $stored;
}

function encodeJson(mixed $value): mixed
{
    if (isScalarValue($value)) {
        return $value;
    }

    return 'J:' . \json_encode($value, \JSON_THROW_ON_ERROR);
}

function decodeJson(mixed $stored): mixed
{
    if (\is_string($stored) && \str_starts_with($stored, 'J:')) {
        return \json_decode(\substr($stored, 2), true, 512, \JSON_THROW_ON_ERROR);
    }

    return $stored;
}

function packInt64BE(int $value): string
{
    if (\PHP_INT_SIZE >= 8) {
        $hi = ($value >> 32) & 0xFFFFFFFF;
        $lo = $value & 0xFFFFFFFF;

        return \pack('NN', $hi, $lo);
    }

    return \pack('N', $value & 0xFFFFFFFF);
}

function unpackInt64BE(string $bytes): int
{
    if (\strlen($bytes) === 8) {
        $parts = \unpack('N2', $bytes);

        return ($parts[1] << 32) | $parts[2];
    }

    return \unpack('N', $bytes)[1];
}

function encodeBinary(mixed $value): string
{
    if ($value === null) {
        return \pack('C', VAL_NULL);
    }

    if (\is_bool($value)) {
        return \pack('CC', VAL_BOOL, $value ? 1 : 0);
    }

    if (\is_int($value)) {
        return \pack('C', VAL_INT) . packInt64BE($value);
    }

    if (\is_float($value)) {
        return \pack('Cd', VAL_FLOAT, $value);
    }

    if (\is_string($value)) {
        return \pack('CN', VAL_STR, \strlen($value)) . $value;
    }

    return \pack('CN', VAL_COMPLEX, \strlen($payload = \serialize($value))) . $payload;
}

function decodeBinary(string $stored): mixed
{
    if ($stored === '') {
        throw new \RuntimeException('empty binary payload');
    }

    $type = \ord($stored[0]);
    $offset = 1;

    switch ($type) {
        case VAL_NULL:
            return null;
        case VAL_BOOL:
            return (bool) \ord($stored[$offset]);
        case VAL_INT:
            $size = \PHP_INT_SIZE >= 8 ? 8 : 4;

            return unpackInt64BE(\substr($stored, $offset, $size));
        case VAL_FLOAT:
            return \unpack('d', \substr($stored, $offset, 8))[1];
        case VAL_STR:
            $len = \unpack('N', $stored, $offset)[1];
            $offset += 4;

            return \substr($stored, $offset, $len);
        case VAL_COMPLEX:
            $len = \unpack('N', $stored, $offset)[1];
            $offset += 4;

            return \unserialize(\substr($stored, $offset, $len), ['allowed_classes' => true]);
        default:
            throw new \RuntimeException('unknown binary type ' . $type);
    }
}

/** @return array<string, array{encode: callable, decode: callable, storage: string}> */
function strategies(): array
{
    return [
        'native' => [
            'encode' => 'encodeNative',
            'decode' => 'decodeNative',
            'storage' => 'mixed',
        ],
        'serialize' => [
            'encode' => 'encodeSerialize',
            'decode' => 'decodeSerialize',
            'storage' => 'string',
        ],
        'hybrid' => [
            'encode' => 'encodeHybrid',
            'decode' => 'decodeHybrid',
            'storage' => 'mixed',
        ],
        'json' => [
            'encode' => 'encodeJson',
            'decode' => 'decodeJson',
            'storage' => 'mixed',
        ],
        'binary' => [
            'encode' => 'encodeBinary',
            'decode' => 'decodeBinary',
            'storage' => 'string',
        ],
    ];
}

function payloadSize(mixed $stored): int
{
    if (\is_string($stored)) {
        return \strlen($stored);
    }

    if (\is_int($stored)) {
        return \strlen(\serialize($stored));
    }

    if (\is_bool($stored)) {
        return \strlen(\serialize($stored));
    }

    if ($stored === null) {
        return 1;
    }

    if (\is_float($stored)) {
        return \strlen(\serialize($stored));
    }

    return \strlen(\serialize($stored));
}

function benchRoundTrip(string $name, mixed $value, int $iterations): void
{
    echo PHP_EOL . '=== ' . $name . ' ===' . PHP_EOL;
    echo \sprintf("%-10s %8s %12s %12s %6s\n", 'strategy', 'bytes', 'encode', 'decode', 'ok');
    echo \str_repeat('-', 56) . PHP_EOL;

    foreach (strategies() as $label => $def) {
        $encode = $def['encode'];
        $decode = $def['decode'];

        $stored = $encode($value);
        $round = $decode($stored);

        $ok = ($round == $value) || ($round === $value);
        if ($value instanceof \stdClass) {
            $ok = ($round == $value);
        }

        if (!$ok) {
            echo \sprintf("%-10s round-trip FAILED\n", $label);
            continue;
        }

        $encode($value);
        $t0 = \hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $encode($value);
        }
        $encodeNs = (\hrtime(true) - $t0) / $iterations;

        $stored = $encode($value);
        $decode($stored);
        $t0 = \hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $decode($stored);
        }
        $decodeNs = (\hrtime(true) - $t0) / $iterations;

        echo \sprintf(
            "%-10s %8d %10.0f ns %10.0f ns %5s\n",
            $label,
            payloadSize($stored),
            $encodeNs,
            $decodeNs,
            'yes'
        );
    }
}

function fmtBenchSummary(float $encodeNs, float $decodeNs): string
{
    return \sprintf('enc %.0f ns dec %.0f ns', $encodeNs, $decodeNs);
}

$longString = \str_repeat('x', 4096);
$smallArray = ['a' => 1, 'b' => 2, 'nested' => ['c' => 3]];
$largeArray = [];
for ($i = 0; $i < 500; $i++) {
    $largeArray['k' . $i] = $i;
}
$object = (object) ['id' => 99, 'name' => 'widget', 'tags' => ['a', 'b']];

if (\realpath($_SERVER['argv'][0] ?? '') === __FILE__) {
    echo 'PHP ' . PHP_VERSION . ' — value codec bench (in-memory)' . PHP_EOL;
    echo 'native = pass-through (models shm typed slots; not realistic alone for arrays)' . PHP_EOL;
    echo 'For production-like numbers, run value_shm_bench.php' . PHP_EOL;

    benchRoundTrip('int 42', 42, 500_000);
    benchRoundTrip('bool true', true, 500_000);
    benchRoundTrip('null', null, 500_000);
    benchRoundTrip('float pi', 3.141592653589793, 500_000);
    benchRoundTrip('string short', 'hello', 500_000);
    benchRoundTrip('string 4KiB', $longString, 50_000);
    benchRoundTrip('array small', $smallArray, 100_000);
    benchRoundTrip('array 500 keys', $largeArray, 10_000);
    benchRoundTrip('object stdClass', $object, 100_000);
}
