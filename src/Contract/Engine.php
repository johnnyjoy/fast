<?php
/**
 * Engine storage contract for the Fast facade.
 *
 * @package   Fast
 * @copyright Copyright (c) 2026 johnnyjoy
 * @license   https://opensource.org/licenses/MIT MIT License
 * @link      https://github.com/johnnyjoy/fast
 */

declare(strict_types = 1);

namespace Fast\Contract;

/**
 * Uniform get/set/delete/has/count/iterate surface driven by the Fast facade.
 *
 * Implemented by {@see Flat} (monolithic store) and {@see Striped} (sharded
 * coordinator). Declaring the shared surface on an interface turns runtime drift
 * between engines into a load-time error.
 *
 * Geometry fields ({@see Flat::$sharedMode}, {@see Flat::$name}, etc.) are public
 * on concrete engines but cannot be declared on a PHP interface; the facade
 * holds a {@see Flat}|{@see Striped} union to read them.
 *
 * @package Fast
 */
interface Engine
{
    /**
     * Store a value under a key, creating or overwriting the entry.
     *
     * @param int|string $key   Key to write.
     * @param mixed      $value Value to store.
     *
     * @return void
     */
    public function set(int|string $key, mixed $value): void;

    /**
     * Read a value by key.
     *
     * @param int|string $key   Key to read.
     * @param mixed      $value Receives the decoded value on hit.
     *
     * @return bool True when the key exists, false when absent.
     */
    public function get(int|string $key, mixed &$value): bool;

    /**
     * Remove a key and reclaim its storage.
     *
     * @param int|string $key Key to delete.
     *
     * @return void
     */
    public function delete(int|string $key): void;

    /**
     * Test key existence with PHP {@see isset()} semantics on stored null.
     *
     * @param int|string $key   Key to probe.
     * @param int|null   $vtype When provided, receives the stored type id on hit.
     *
     * @return bool True when the key exists and is not a stored null.
     */
    public function has(int|string $key, ?int &$vtype = null): bool;

    /**
     * Whether a stored type id represents null.
     *
     * @param int $type Engine type constant.
     *
     * @return bool
     */
    public function isNullType(int $type): bool;

    /**
     * Count live entries in the store.
     *
     * @return int
     */
    public function count(): int;

    /**
     * Rewind iteration to the first insertion-order entry.
     *
     * @return void
     */
    public function rewind(): void;

    /**
     * Whether the iterator cursor points at a live entry.
     *
     * @return bool
     */
    public function valid(): bool;

    /**
     * Value at the current iterator position.
     *
     * @return mixed
     */
    public function current(): mixed;

    /**
     * Key at the current iterator position.
     *
     * @return mixed
     */
    public function key(): mixed;

    /**
     * Advance the iterator to the next insertion-order entry.
     *
     * @return void
     */
    public function next(): void;

    /**
     * Seek the iterator to a zero-based insertion-order position.
     *
     * @param int $position Target position.
     *
     * @return void
     */
    public function seek(int $position): void;

    /**
     * Acquire the writer lock (nested-safe in-process).
     *
     * @return void
     */
    public function lock(): void;

    /**
     * Release the writer lock acquired by {@see lock()}.
     *
     * @param bool $silent When true, suppress release errors on imbalance.
     *
     * @return void
     */
    public function unlock(bool $silent = false): void;

    /**
     * Relocate-compact the value arena to reclaim fragmented space.
     *
     * @return void
     */
    public function compact(): void;

    /**
     * Close this process connection to the store.
     *
     * @return void
     */
    public function close(): void;

    /**
     * Destroy the store and all segments when sole owner.
     *
     * @return void
     */
    public function destroy(): void;
}
