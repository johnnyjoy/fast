<?php
/**
 * Fast library — array-like shared-memory map for PHP.
 *
 * @package   Fast
 * @copyright Copyright (c) 2026 johnnyjoy
 * @license   https://opensource.org/licenses/MIT MIT License
 * @link      https://github.com/johnnyjoy/fast
 */

declare(strict_types = 1);


use Fast\Engine\Flat;
use Fast\Engine\Striped;

/**
 * Fast — a shared-memory PHP map with array/object/iterator ergonomics.
 *
 * The facade is deliberately thin: it owns the array-like contract (ArrayAccess,
 * magic property access, Iterator, Countable, each(), lifecycle) and delegates ALL
 * storage to the {@see Flat} engine, which routes local (in-process) and shared
 * (named, multi-process shmop) modes behind one uniform get/set/delete/has/count
 * /iterate surface. There is intentionally no wildcard delegator, so engine
 * internals can never leak through the public surface.
 *
 * Engine/layout/format constants are NOT part of the Fast public surface; they
 * live on Flat. The public method surface is locked by test/public_surface.php.
 *
 * @package Fast
 * @license https://opensource.org/licenses/MIT MIT License
 * @link    https://github.com/johnnyjoy/fast
 */
final class Fast implements \ArrayAccess, \Iterator, \Countable
{
    /**
     * The storage engine: a monolithic {@see Flat} or a {@see Striped} coordinator.
     * Both implement {@see \Fast\Contract\Engine}, the method contract this facade
     * drives; the union (not the bare interface) is kept because the facade also
     * reads the engines' public geometry fields, which a PHP 8.1 interface cannot
     * declare.
     */
    private Flat|Striped $engine;

    // Set by close(): a closed handle refuses all data/lifecycle operations.
    private bool $closed = false;

    // ---- by-reference offsetGet pending writeback -----------------------------
    // &offsetGet hands back a reference into a single pending slot so nested
    // mutation ($fast['k']['n'] = v) and auto-vivification flush on the next
    // access. A bare read leaves value === originalValue and writes nothing.
    private bool $pendingOffsetDirty = false;
    private int|string|null $pendingOffsetKey = null;
    private mixed $pendingOffsetOriginalValue = null;
    private mixed $pendingOffsetValue = null;

    /**
     * Create a Fast store.
     *
     *   new Fast()                      local, in-process. No shared memory.
     *   new Fast([])                    same as new Fast(): local, in-process.
     *   new Fast('cache')               shared, named "cache" (created if absent).
     *   new Fast(['name' => 'cache'])   shared, named "cache" (created if absent).
     *
     * Honored config keys (all optional):
     *   name       string  opt into a named shared store (omit/empty => local mode)
     *   capacity   int     directory slot count (power of two); default Flat::DEFAULT_SLOTS
     *   size       int     shared segment byte size;            default Flat::DEFAULT_SIZE
     *   persistent bool    keep the store alive after the last process leaves
     *   stripes    int     OPT-IN write concurrency: partition into N independent
     *                      sub-stores (power of two >= 2). Default 1 = single strict-
     *                      order store. With stripes > 1, concurrent multi-process
     *                      writers scale (3-7x), and iteration is "approximately
     *                      insertion order" (strict per single writer; sub-us fuzz
     *                      only among concurrent cross-stripe inserts). capacity and
     *                      size are TOTALS split evenly: each stripe gets
     *                      capacity/stripes slots and size/stripes bytes (so the
     *                      configured budget is the whole-store budget either way).
     *                      Hash routing means the busiest stripe grows before the
     *                      store holds the full nominal capacity; size must be large
     *                      enough that size/stripes clears the per-stripe minimum
     *                      (validated at attach with the exact minimum reported).
     *
     * Persistence: named shared stores are NON-PERSISTENT by default — when the
     * last connected process closes, the store is reclaimed. persistent => true
     * keeps it alive by name until an explicit destroy() by its sole owner.
     *
     * Unknown config keys are rejected with \InvalidArgumentException — a typo or a
     * not-implemented option must fail loudly rather than silently do nothing.
     *
     * @param string|array{name?:string,capacity?:int,size?:int,persistent?:bool,stripes?:int} $config Store configuration.
     *
     * @return void
     *
     * @throws \InvalidArgumentException On an unsupported config key.
     */
    public function __construct(string|array $config = [])
    {
        Flat::requireIgbinary();

        if (\is_string($config)) {
            $this->engine = new Flat();
            $this->engine->attach($config, Flat::DEFAULT_SIZE, Flat::DEFAULT_SLOTS, false);
            return;
        }

        foreach ($config as $configKey => $_v) {
            if (
                $configKey !== 'name' && $configKey !== 'capacity'
                && $configKey !== 'size' && $configKey !== 'persistent'
                && $configKey !== 'stripes'
            ) {
                throw new \InvalidArgumentException('Unsupported Fast config key: ' . (string) $configKey);
            }
        }

        $slots = (int) ($config['capacity'] ?? Flat::DEFAULT_SLOTS);
        $stripes = (int) ($config['stripes'] ?? 1);

        if (isset($config['name']) && $config['name'] !== '') {
            $size = (int) ($config['size'] ?? Flat::DEFAULT_SIZE);
            $persistent = (bool) ($config['persistent'] ?? false);
            if ($stripes > 1) {
                $this->engine = new Striped();
                $this->engine->attach((string) $config['name'], $size, $slots, $persistent, $stripes);
            } else {
                $this->engine = new Flat();
                $this->engine->attach((string) $config['name'], $size, $slots, $persistent);
            }
            return;
        }

        if ($stripes > 1) {
            throw new \InvalidArgumentException('stripes requires a named shared store');
        }
        $this->engine = new Flat();
        $this->engine->initLocal();
    }

    /**
     * Flush pending offset writeback and release the store connection on teardown.
     *
     * @return void
     */
    public function __destruct()
    {
        $this->flushPendingOffsetWriteback();
        $this->close();
    }

    /**
     * Redacted debug view for {@see var_dump()} and {@see print_r()}.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->stats();
    }

    // ===================== lifecycle =====================

    /**
     * Close this process's connection to the shared store.
     *
     * Drops this process's link, then closes the local shmop/semaphore handles.
     * A persistent store survives for other processes; a non-persistent store is
     * reclaimed when this is the last connected process (last one out turns off
     * the lights). Idempotent: a second close() is a safe no-op.
     *
     * @return void
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->flushPendingOffsetWriteback();
        $this->engine->close();
        $this->closed = true;
    }

    /**
     * Destroy the shared-memory store itself (all segments). Permitted ONLY when
     * this is the sole connected process; otherwise it throws and leaves the store
     * intact. After a successful destroy() the named store no longer exists.
     *
     * @throws \RuntimeException when other processes are still connected.
     *
     * @return void
     */
    public function destroy(): void
    {
        $this->assertOpen();
        $this->flushPendingOffsetWriteback();
        $this->engine->destroy();
        $this->closed = true;
    }

    /**
     * @throws \RuntimeException when this handle has been closed.
     */
    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new \RuntimeException(
                'Fast handle is closed: close() released this store connection; '
                . 'create a new Fast to open the store again'
            );
        }
    }

    // ===================== serialize / wake =====================

    /**
     * Serialize the handle for {@see sleep()} / {@see wakeup()} or session storage.
     *
     * Shared mode closes this connection and records reattach geometry; local mode
     * captures ordered key/value pairs.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $this->assertOpen();
        $this->flushPendingOffsetWriteback();

        if ($this->engine->sharedMode) {
            $payload = [
                'sharedMode' => true,
                'sharedName' => $this->engine->name,
                'sharedSize' => $this->engine->size,
                'directorySlots' => $this->engine->slotCount,
                'persistent' => $this->engine->persistent,
                'stripes' => $this->engine instanceof Striped ? $this->engine->stripes() : 1,
            ];
            // Sleep is a real close, following close() lifecycle rules.
            $this->close();
            return $payload;
        }

        // Local mode: capture the ordered key/value pairs.
        $entries = [];
        for ($this->engine->rewind(); $this->engine->valid(); $this->engine->next()) {
            $entries[] = [$this->engine->key(), $this->engine->current()];
        }
        return ['sharedMode' => false, 'entries' => $entries];
    }

    /**
     * Restore a handle serialized by {@see __serialize()}.
     *
     * @param array<string, mixed> $data Serialized payload.
     *
     * @return void
     *
     * @throws \InvalidArgumentException when the shared wakeup payload is invalid.
     */
    public function __unserialize(array $data): void
    {
        Flat::requireIgbinary();
        $this->closed = false;

        if (($data['sharedMode'] ?? false) === true) {
            $sharedName = (string) ($data['sharedName'] ?? '');
            $sharedSize = (int) ($data['sharedSize'] ?? 0);
            $slots = (int) ($data['directorySlots'] ?? Flat::DEFAULT_SLOTS);
            $persistent = (bool) ($data['persistent'] ?? false);
            $stripes = (int) ($data['stripes'] ?? 1);

            if ($sharedName === '' || $sharedSize < 1) {
                throw new \InvalidArgumentException('invalid shared Fast wakeup payload');
            }

            if ($stripes > 1) {
                $this->engine = new Striped();
                $this->engine->attach($sharedName, $sharedSize, $slots, $persistent, $stripes);
            } else {
                $this->engine = new Flat();
                $this->engine->attach($sharedName, $sharedSize, $slots, $persistent);
            }
            return;
        }

        $this->engine = new Flat();
        $this->engine->initLocal();
        foreach (($data['entries'] ?? []) as [$k, $v]) {
            $this->engine->set($k, $v);
        }
    }

    // ===================== ArrayAccess =====================

    /**
     * {@inheritDoc}
     *
     * @param mixed $offset Key to test.
     *
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        $this->assertOpen();
        $this->assertValidOffset($offset);
        return $this->hasValue($offset);
    }

    /**
     * {@inheritDoc}
     *
     * Returns a reference into a pending writeback slot for nested mutation.
     *
     * @param mixed $offset Key to read.
     *
     * @return mixed
     */
    public function &offsetGet(mixed $offset): mixed
    {
        $this->assertOpen();
        $this->assertValidOffset($offset);
        if ($this->pendingOffsetDirty) {
            $this->flushPendingOffsetWriteback();
        }

        $this->pendingOffsetKey = $offset;
        $this->pendingOffsetDirty = true;

        $current = null;
        if ($this->engine->get($offset, $current)) {
            $this->pendingOffsetOriginalValue = $current;
            $this->pendingOffsetValue = $current;
            return $this->pendingOffsetValue;
        }

        $this->pendingOffsetOriginalValue = null;
        $this->pendingOffsetValue = null;
        return $this->pendingOffsetValue;
    }

    /**
     * {@inheritDoc}
     *
     * @param mixed $offset Key to write.
     * @param mixed $value  Value to store.
     *
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->assertOpen();
        $this->assertValidOffset($offset);
        if ($this->pendingOffsetDirty) {
            $this->flushPendingOffsetWriteback();
        }
        $this->engine->set($offset, $value);
    }

    /**
     * {@inheritDoc}
     *
     * @param mixed $offset Key to remove.
     *
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->assertOpen();
        $this->assertValidOffset($offset);
        if ($this->pendingOffsetDirty) {
            $this->flushPendingOffsetWriteback();
        }
        $this->engine->delete($offset);
    }

    /**
     * Existence with PHP array `isset()` semantics: a key whose stored value is
     * null is reported as NOT set. Metadata-only — never decodes the value.
     */
    private function hasValue(int|string $key): bool
    {
        if ($this->pendingOffsetDirty) {
            $this->flushPendingOffsetWriteback();
        }
        $vtype = null;
        return $this->engine->has($key, $vtype) && !$this->engine->isNullType((int) $vtype);
    }

    private function flushPendingOffsetWriteback(): void
    {
        if (!$this->pendingOffsetDirty || $this->pendingOffsetKey === null) {
            return;
        }

        $changed = $this->pendingOffsetValue !== $this->pendingOffsetOriginalValue;
        $key = $this->pendingOffsetKey;
        $value = $this->pendingOffsetValue;

        $this->pendingOffsetDirty = false;
        $this->pendingOffsetKey = null;
        $this->pendingOffsetOriginalValue = null;
        $this->pendingOffsetValue = null;

        if ($changed) {
            $this->engine->set($key, $value);
        }
    }

    // ===================== magic property access =====================

    /**
     * Magic read — delegates to {@see offsetGet()}.
     *
     * @param string $name Property name.
     *
     * @return mixed
     */
    public function &__get(string $name): mixed
    {
        return $this->offsetGet($name);
    }

    /**
     * Magic write — delegates to {@see offsetSet()}.
     *
     * @param string $name  Property name.
     * @param mixed  $value Value to store.
     *
     * @return void
     */
    public function __set(string $name, mixed $value): void
    {
        $this->offsetSet($name, $value);
    }

    /**
     * Magic isset — delegates to {@see offsetExists()}.
     *
     * @param string $name Property name.
     *
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return $this->offsetExists($name);
    }

    /**
     * Magic unset — delegates to {@see offsetUnset()}.
     *
     * @param string $name Property name.
     *
     * @return void
     */
    public function __unset(string $name): void
    {
        $this->offsetUnset($name);
    }

    // ===================== Iterator / SeekableIterator =====================

    /**
     * {@inheritDoc}
     *
     * @return void
     */
    public function rewind(): void
    {
        $this->assertOpen();
        if ($this->pendingOffsetDirty) {
            $this->flushPendingOffsetWriteback();
        }
        $this->engine->rewind();
    }

    /**
     * {@inheritDoc}
     *
     * @return mixed
     */
    public function current(): mixed
    {
        $this->assertOpen();
        if ($this->pendingOffsetDirty) {
            $this->flushPendingOffsetWriteback();
        }
        return $this->engine->current();
    }

    /**
     * {@inheritDoc}
     *
     * @return mixed
     */
    public function key(): mixed
    {
        $this->assertOpen();
        if ($this->pendingOffsetDirty) {
            $this->flushPendingOffsetWriteback();
        }
        return $this->engine->key();
    }

    /**
     * {@inheritDoc}
     *
     * @return void
     */
    public function next(): void
    {
        $this->assertOpen();
        if ($this->pendingOffsetDirty) {
            $this->flushPendingOffsetWriteback();
        }
        $this->engine->next();
    }

    /**
     * {@inheritDoc}
     *
     * @return bool
     */
    public function valid(): bool
    {
        $this->assertOpen();
        if ($this->pendingOffsetDirty) {
            $this->flushPendingOffsetWriteback();
        }
        return $this->engine->valid();
    }

    /**
     * {@inheritDoc}
     *
     * @param int $position Zero-based insertion-order index.
     *
     * @return void
     */
    public function seek(int $position): void
    {
        $this->assertOpen();
        if ($this->pendingOffsetDirty) {
            $this->flushPendingOffsetWriteback();
        }
        $this->engine->seek($position);
    }

    /**
     * {@inheritDoc}
     *
     * @return int
     */
    public function count(): int
    {
        $this->assertOpen();
        if ($this->pendingOffsetDirty) {
            $this->flushPendingOffsetWriteback();
        }
        return $this->engine->count();
    }

    // ===================== grouped operation =====================

    /**
     * Walk each live key/value pair and invoke a named callable once per element.
     *
     * In shared mode each() takes a brief-lock snapshot of the current key set
     * (the writer lock is held only for that scan, never across the callback),
     * then invokes the callback per entry with the lock released, reading each
     * value live. It is therefore a snapshot of MEMBERSHIP at lock time: entries
     * the callback (or a peer) inserts afterward are not visited, and entries
     * deleted before they are reached are skipped and not counted. Callbacks run
     * lock-free, so a slow callback never stalls writers (and a Striped store is
     * not pinned across the walk). For a pure read traversal, foreach also works.
     *
     * Closures are deliberately NOT allowed: a named function, [class-string,
     * method], or [object, method] is required.
     *
     * @param string|array{0:class-string|object,1:string} $fn    Named callable.
     * @param mixed                                        ...$args Extra arguments passed to the callback.
     *
     * @return int Number of entries visited.
     *
     * @throws \TypeError if $fn is a closure or otherwise not a string|array.
     * @throws \InvalidArgumentException if $fn names a missing/invalid callable.
     */
    public function each(string|array $fn, mixed ...$args): int
    {
        $this->assertOpen();
        $this->flushPendingOffsetWriteback();
        $callback = $this->requireNamedCallable($fn);

        // Brief-lock snapshot, then lock-free callbacks. The walk holds the writer
        // lock ONLY long enough to copy the current key set — never across user
        // code — so a slow/blocking callback can no longer stall writers, and a
        // Striped store no longer pins all S semaphores for the whole walk (L1).
        //
        // Semantics: this is a snapshot of MEMBERSHIP at lock time, with values
        // read live when each entry is visited. Entries the callback (or a peer)
        // inserts after the snapshot are not visited; entries deleted before they
        // are reached are skipped and not counted. The Flat iterator is already
        // seqlock-safe, so the lock is purely for a consistent point-in-time key
        // set, not read safety.
        $keys = [];
        $locked = false;
        if ($this->engine->sharedMode) {
            $this->engine->lock();
            $locked = true;
        }
        try {
            for ($this->engine->rewind(); $this->engine->valid(); $this->engine->next()) {
                $keys[] = $this->engine->key();
            }
        } finally {
            if ($locked) {
                $this->engine->unlock(true);
            }
        }

        $count = 0;
        foreach ($keys as $key) {
            $value = null;
            if (!$this->engine->get($key, $value)) {
                continue; // deleted since the snapshot — skip
            }
            $callback($this, $key, $value, ...$args);
            $count++;
        }
        return $count;
    }

    // ===================== internal (test-only) introspection =====================

    /**
     * Internal diagnostics snapshot. NOT public contract — reachable only by
     * __debugInfo and the test-only fast_test_stats() helper (via reflection).
     *
     * @return array<string,mixed>
     */
    private function stats(): array
    {
        $this->flushPendingOffsetWriteback();
        $shared = $this->engine->sharedMode;
        return [
            'shared' => $shared,
            'name' => $shared ? $this->engine->name : null,
            'count' => $this->closed ? 0 : $this->engine->count(),
            'directory_slots' => $this->engine->slotCount,
            'persistent' => $this->engine->persistent,
            'shared_size' => $this->engine->size,
        ];
    }

    /**
     * Internal storage maintenance. NOT public contract — reachable only via the
     * test-only fast_test_compact() helper. Forces a relocating compaction: live
     * records are repacked densely, the value-arena frontier resets, and empty
     * trailing segments are returned to the OS when this is the sole owner. Every
     * live entry is preserved; iteration order is unchanged.
     */
    private function compact(): void
    {
        $this->flushPendingOffsetWriteback();
        $this->engine->compact();
    }

    // ===================== private helpers =====================

    private function assertValidOffset(mixed $offset): void
    {
        if (!\is_int($offset) && !\is_string($offset)) {
            throw new \InvalidArgumentException('array offset must be int|string');
        }
    }

    /**
     * @param string|array{0:class-string|object,1:string} $fn
     * @return string|array{0:class-string|object,1:string}
     */
    private function requireNamedCallable(string|array $fn): string|array
    {
        if (\is_string($fn)) {
            if (!\function_exists($fn)) {
                throw new \InvalidArgumentException('each callable function not found: ' . $fn);
            }
            return $fn;
        }

        if (!isset($fn[0], $fn[1]) || !\is_string($fn[1])) {
            throw new \InvalidArgumentException('each callable must be a named function or [class/object, method] pair');
        }

        if (!\is_string($fn[0]) && !\is_object($fn[0])) {
            throw new \InvalidArgumentException('each callable target must be a class-string or object');
        }

        if ($fn[0] instanceof \Closure) {
            throw new \InvalidArgumentException('closures are not allowed for each callables');
        }

        if (!\is_callable($fn)) {
            throw new \InvalidArgumentException('each callable is not callable');
        }

        return $fn;
    }
}
