<?php declare(strict_types = 1);

/**
 * Test-only engine introspection helpers for contract tests.
 *
 * The public Fast surface deliberately exposes no engine internals. Tests that
 * must still assert on internal mechanism reach it here via reflection / engine
 * probes rather than forcing those capabilities back onto the public Fast class.
 * Behavioral assertions should prefer the public surface (count, isset, values,
 * foreach, close/destroy).
 *
 * The engine is Fast\Engine\Flat; segment existence/counting is probed through
 * Flat's own segment-key derivation so these helpers cannot drift from how Fast
 * actually keys its shared-memory segments.
 */

namespace Fast;

use \Fast;
use Fast\Engine\Flat;

/**
 * Test-only diagnostics snapshot.
 *
 * stats() is NOT part of the public Fast contract — it is private engine
 * introspection debt. Tests reach it here via reflection rather than forcing a
 * public diagnostics method onto Fast.
 *
 * @return array<string,mixed>
 */
function fast_test_stats(Fast $store): array
{
    static $method = null;
    if ($method === null) {
        $method = new \ReflectionMethod(\Fast::class, 'stats');
    }

    /** @var array<string,mixed> $stats */
    $stats = $method->invoke($store);
    return $stats;
}

/**
 * POSIX shm backing file for ext-fast native stores (Linux: /dev/shm + path).
 * Segment key matches Flat::segKey and flat_native.c fast_native_seg_key().
 */
function fast_test_native_shm_file(string $name): string
{
    return '/dev/shm/fast-native-' . \dechex(Flat::segKey($name, 0));
}

/**
 * Test-only attach-existing-only open.
 *
 * The public constructor (`new \Fast('name')`) is open-or-create. Some lifecycle
 * tests must instead prove a store is GONE (reclaimed or destroyed), which needs
 * fail-if-missing semantics — a test-only capability:
 *
 *   existing store -> returns an attached handle
 *   missing store  -> throws RuntimeException
 */
function fast_test_open_existing(string $name): Fast
{
    if (!fast_test_shared_segment_exists($name, 0)) {
        throw new \RuntimeException('cannot attach: shared Fast store "' . $name . '" does not exist');
    }

    return new \Fast($name);
}

/**
 * Test-only storage maintenance trigger. compact() is internal engine
 * maintenance, not public Fast contract; reached here via reflection.
 */
function fast_test_compact(Fast $store): void
{
    static $method = null;
    if ($method === null) {
        $method = new \ReflectionMethod(\Fast::class, 'compact');
    }

    $method->invoke($store);
}

/** Header offset H_FRONTIER (arena bump pointer). */
const FAST_TEST_H_FRONTIER = 24;

/**
 * Raw arena frontier from the backing store (test-only shrink / compaction gate).
 */
function fast_test_frontier(string $name): ?int
{
    if (\extension_loaded('fast')) {
        $path = fast_test_native_shm_file($name);
        if (!\is_readable($path)) {
            return null;
        }
        $raw = @\file_get_contents($path, false, null, FAST_TEST_H_FRONTIER, 8);
        if ($raw === false || \strlen($raw) < 8) {
            return null;
        }

        return (int) \unpack('P', $raw)[1];
    }

    $seg = @\shmop_open(Flat::segKey($name, 0), 'a', 0, 0);
    if ($seg === false) {
        return null;
    }

    return (int) \unpack('P', \shmop_read($seg, FAST_TEST_H_FRONTIER, 8))[1];
}

/**
 * Backing file size for ext-native mmap stores (Linux: /dev/shm + path).
 */
function fast_test_native_shm_size(string $name): ?int
{
    if (!\extension_loaded('fast')) {
        return null;
    }

    $path = fast_test_native_shm_file($name);
    if (!\is_readable($path)) {
        return null;
    }

    \clearstatcache(true, $path);
    $size = @\filesize($path);
    if ($size === false) {
        return null;
    }

    return $size;
}

/**
 * Test-only shared-segment existence probe, keyed exactly as the engine keys its
 * segments.
 */
function fast_test_shared_segment_exists(string $name, int $index): bool
{
    if (\extension_loaded('fast')) {
        /* ext-native uses a single mmap arena; growth is in-process, not extra shmop keys */
        if ($index !== 0) {
            return false;
        }

        return \is_file(fast_test_native_shm_file($name));
    }

    $seg = @\shmop_open(Flat::segKey($name, $index), 'a', 0600, 0);
    if ($seg === false) {
        return false;
    }
    // shmop handles are auto-closed at request end; no shmop_close needed here.
    return true;
}

/**
 * Test-only count of a store's contiguous shared-memory segments (segment 0 up to
 * the first absent key), bounded by the engine maximum. Returns 0 when absent.
 */
function fast_test_shared_segment_count(string $name): int
{
    if (\extension_loaded('fast')) {
        return fast_test_shared_segment_exists($name, 0) ? 1 : 0;
    }

    $count = 0;
    for ($i = 0; $i < Flat::MAX_SEGMENTS; $i++) {
        if (!fast_test_shared_segment_exists($name, $i)) {
            break;
        }
        $count++;
    }

    return $count;
}

/**
 * Raw seqlock read for crash-recovery tests (segment 0, offset H_SEQ).
 */
function fast_test_raw_seq(string $name): ?int
{
    if (\extension_loaded('fast')) {
        $path = fast_test_native_shm_file($name);
        if (!\is_readable($path)) {
            return null;
        }
        $raw = @\file_get_contents($path, false, null, 8, 4);
        if ($raw === false || \strlen($raw) < 4) {
            return null;
        }

        return \unpack('V', $raw)[1];
    }

    $seg = @\shmop_open(Flat::segKey($name, 0), 'a', 0, 0);
    if ($seg === false) {
        return null;
    }

    return \unpack('V', \shmop_read($seg, 8, 4))[1];
}

/** Header offset H_ORDER (order-log entry count). Layout: Flat.php / fast_layout.h */
const FAST_TEST_H_ORDER = 20;

/** Header offset H_SLOTS (directory slot count). */
const FAST_TEST_H_SLOTS = 32;

/**
 * Raw order-log length from segment 0 (test-only invariant gate).
 */
function fast_test_order_count(string $name): ?int
{
    if (\extension_loaded('fast')) {
        $path = fast_test_native_shm_file($name);
        if (!\is_readable($path)) {
            return null;
        }
        $raw = @\file_get_contents($path, false, null, FAST_TEST_H_ORDER, 4);
        if ($raw === false || \strlen($raw) < 4) {
            return null;
        }

        return \unpack('V', $raw)[1];
    }

    $seg = @\shmop_open(Flat::segKey($name, 0), 'a', 0, 0);
    if ($seg === false) {
        return null;
    }

    return \unpack('V', \shmop_read($seg, FAST_TEST_H_ORDER, 4))[1];
}

/**
 * Raw directory slot count from segment 0 header (H_SLOTS).
 */
function fast_test_directory_slots(string $name): ?int
{
    if (\extension_loaded('fast')) {
        $path = fast_test_native_shm_file($name);
        if (!\is_readable($path)) {
            return null;
        }
        $raw = @\file_get_contents($path, false, null, FAST_TEST_H_SLOTS, 4);
        if ($raw === false || \strlen($raw) < 4) {
            return null;
        }

        return \unpack('V', $raw)[1];
    }

    $seg = @\shmop_open(Flat::segKey($name, 0), 'a', 0, 0);
    if ($seg === false) {
        return null;
    }

    return \unpack('V', \shmop_read($seg, FAST_TEST_H_SLOTS, 4))[1];
}

/**
 * Assert H_ORDER <= directory slot count (order log never exceeds reserved region).
 *
 * @throws \RuntimeException when the invariant is violated or the header is unreadable
 */
function fast_test_assert_order_log_bounded(Fast $store, string $name): void
{
    $slots = fast_test_directory_slots($name);
    $oc = fast_test_order_count($name);

    if ($oc === null) {
        throw new \RuntimeException('could not read H_ORDER for store "' . $name . '"');
    }
    if ($slots === null || $slots < 1) {
        throw new \RuntimeException('could not read H_SLOTS for store "' . $name . '"');
    }
    if ($oc > $slots) {
        throw new \RuntimeException(
            'order log overflow: H_ORDER=' . $oc . ' exceeds directory_slots=' . $slots
        );
    }
}

/**
 * Test-only capability probe. Shared mode requires shmop + sysvsem; shared-mode
 * tests gate themselves on this instead of a public Fast capability method.
 */
function fast_test_supports_shared_memory(): bool
{
    if (\extension_loaded('fast')) {
        return true;
    }

    return \extension_loaded('shmop') && \extension_loaded('sysvsem');
}
