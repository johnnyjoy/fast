<?php
/**
 * Striped write-concurrent engine coordinator for Fast.
 *
 * @package   Fast
 * @copyright Copyright (c) 2026 johnnyjoy
 * @license   https://opensource.org/licenses/MIT MIT License
 * @link      https://github.com/johnnyjoy/fast
 */

declare(strict_types = 1);

namespace Fast\Engine;

use Fast\Contract\Engine;
use InvalidArgumentException;

/**
 * Striped — an opt-in, write-concurrent composition of S independent {@see Flat}
 * sub-stores, exposing the SAME engine surface the Fast facade drives.
 *
 * Why: a single Flat serializes every write behind one semaphore, so concurrent
 * multi-process writers collapse (~7x slower at 8 writers; see Fast/research
 * exp9). Partitioning the keyspace into S sub-stores — each its own segment,
 * semaphore, seqlock and allocator — lets up to S writers proceed in parallel
 * (measured 3-7x at 2-8 writers on the real engine; striped_proto.php). The cost
 * is ~8% single-writer routing overhead and a weaker iteration-order contract
 * (see below), which is why this is OPT-IN via Fast's `stripes` config key;
 * `stripes => 1` keeps the strict-order monolith unchanged.
 *
 * Routing: a key lands in stripe = HIGH bits of an independent xxh3 of the key.
 * Each sub-store's own directory mask uses the LOW bits of a different hash
 * (xxh128), so stripe selection never correlates with intra-stripe clustering.
 *
 * Insertion order: each sub-store stamps an 8-byte hrtime tag on every insert
 * (Flat's tagged order log). Iteration k-way-merges the S cursors by tag, so the
 * global order is "approximately insertion order": strict for a single writer,
 * with sub-microsecond fuzz only among genuinely concurrent inserts across
 * stripes (measured ~0.01%). Ties break deterministically by (tag, stripe).
 *
 * SCALING CONTRACT (read before enabling `stripes`): because routing is keyed,
 * every key has exactly one home stripe behind exactly one semaphore. Write
 * concurrency therefore scales ONLY when the write load spreads across many keys
 * in many stripes. It does NOT help — and is slightly WORSE than `stripes => 1`,
 * since you still pay ~8% routing overhead and split capacity S ways — when the
 * load concentrates on a few hot keys (Zipfian access, a single counter/leader
 * key, etc.): those keys hash to a fixed stripe and serialize there. This is a
 * property of keyed sharding, not a bug, and cannot be fixed by a routing trick
 * (spreading one key across stripes would break the single-home lookup). Choose
 * `stripes >= 2` for write-distributed, multi-writer workloads; keep the default
 * `stripes => 1` (strict-order monolith) for single-writer or hot-key workloads.
 *
 * The worst case still degrades gracefully: correctness is unaffected and each
 * sub-store grows independently, so a hot stripe never starves the others — it
 * only costs memory. To DETECT skew without any hot-path cost, install the M6
 * diagnostic sink (Flat::setDiagnostics / FAST_DIAG): on the cold paths
 * (close/compact) this coordinator emits a `striped.distribution` event with the
 * per-stripe live counts, an `imbalance` ratio (1.0 = perfectly even, up to
 * `stripes` = everything in one stripe), and the per-stripe geometry.
 *
 * CAPACITY / SIZE are TOTALS, split evenly (L4): each stripe gets `capacity/stripes`
 * slots and `size/stripes` bytes — NOT the configured totals. The configured value
 * means the same whole-store budget whether or not you stripe (striping is an
 * internal parallelism detail), so turning on `stripes` does not change how much
 * you asked for; it only partitions it. Two consequences to expect: (1) because
 * routing is by hash, the busiest stripe reaches its `capacity/stripes` limit (and
 * grows) before the store as a whole holds `capacity` entries — realized capacity
 * before the first growth is below the nominal total by the usual balls-in-bins
 * margin; growth absorbs the overflow. (2) `size` must be large enough that
 * `size/stripes` still clears the per-stripe minimum — attach() validates this up
 * front and reports the exact minimum if it does not.
 *
 * @package Fast
 * @license https://opensource.org/licenses/MIT MIT License
 * @link    https://github.com/johnnyjoy/fast
 */
final class Striped implements Engine
{
    // Public surface mirrored from Flat for the facade (stats / serialize read these).
    public bool $sharedMode = true;
    public ?string $name = null;
    public bool $persistent = false;
    public int $size = 0;          // total requested segment bytes (sum across stripes)
    public int $slotCount = 0;     // total directory slots (sum across stripes)

    /** @var Flat[] */
    private array $sub = [];
    private int $stripes = 1;
    private int $routeShift = 40;  // route on high bits, clear of any per-stripe low-bit mask

    /** k-way-merge cursor: index of the sub-store providing the current element, or -1. */
    private int $curStripe = -1;

    // ===================== construction / routing =====================

    /**
     * Build the S sub-stores and adopt the resulting geometry. Each stripe is an
     * independent {@see Flat} created with a tagged order log (so iteration can
     * k-way-merge the S cursors by hrtime), holding capacity/stripes slots and
     * size/stripes bytes — capacity and size are whole-store TOTALS split evenly
     * (see the class docblock for why, and the consequences).
     *
     * @param string $name       Base store name; stripe i attaches "<name>#<i>".
     * @param int    $size       Total segment bytes across all stripes.
     * @param int    $slots      Total directory capacity across all stripes.
     * @param bool   $persistent Keep the stripes alive after the last process leaves.
     * @param int    $stripes    Number of sub-stores (power of two >= 2).
     *
     * @return void
     *
     * @throws InvalidArgumentException If $stripes is not a power of two >= 2.
     * @throws InvalidArgumentException If $slots is not a power of two >= $stripes.
     * @throws InvalidArgumentException If size/stripes is below the per-stripe minimum.
     */
    public function attach(string $name, int $size, int $slots, bool $persistent, int $stripes): void
    {
        if ($stripes < 2 || ($stripes & ($stripes - 1)) !== 0) {
            throw new InvalidArgumentException('stripes must be a power of two >= 2');
        }
        if (($slots & ($slots - 1)) !== 0 || $slots < $stripes) {
            throw new InvalidArgumentException('capacity must be a power of two >= stripes');
        }

        $perSlots = \intdiv($slots, $stripes);     // power of two (slots & stripes both are)
        $perSize  = \intdiv($size, $stripes);

        // capacity and size are TOTALS split evenly across the stripes (see class
        // docblock). Validate the per-stripe byte budget HERE so the error names the
        // split and the exact fix, instead of failing cryptically deep inside a
        // sub-store's attach() against a size the caller never typed.
        $minPerStripe = Flat::minSegmentSize($perSlots, true); // tagged order log
        if ($perSize < $minPerStripe) {
            throw new InvalidArgumentException(
                'size ' . $size . ' split across ' . $stripes . ' stripes is ' . $perSize
                . ' bytes/stripe, below the ' . $minPerStripe . ' minimum for '
                . $perSlots . ' slots/stripe (capacity ' . $slots . ' / ' . $stripes
                . '); increase size to at least ' . ($minPerStripe * $stripes)
                . ' or reduce stripes/capacity'
            );
        }

        $this->stripes = $stripes;
        $this->name = $name;
        $this->size = $size;
        $this->slotCount = $slots;

        for ($i = 0; $i < $stripes; $i++) {
            $e = new Flat();
            // Tagged order log (true) so the cross-stripe merge has a global clock.
            $e->attach($this->subName($name, $i), $perSize, $perSlots, $persistent, true);
            $this->sub[$i] = $e;
        }
        // All sub-stores share the requested persistence; report the live value.
        $this->persistent = $this->sub[0]->persistent;
    }

    /**
     * Number of independent sub-stores in this coordinator.
     *
     * @return int
     */
    public function stripes(): int { return $this->stripes; }

    private function subName(string $name, int $i): string { return $name . '#' . $i; }

    /** stripe = high bits of an independent key hash (decoupled from each sub's low-bit mask). */
    private function route(int|string $key): int
    {
        $h = \is_int($key)
            ? \hash('xxh3', "\0" . \pack('q', $key), true)
            : \hash('xxh3', "\1" . $key, true);
        return (\unpack('P', $h)[1] >> $this->routeShift) & ($this->stripes - 1);
    }

    // ===================== data ops (routed) =====================

    /**
     * {@inheritDoc}
     *
     * @param int|string $key   Key to write.
     * @param mixed      $value Value to store.
     *
     * @return void
     */
    #[\Override]
    public function set(int|string $key, mixed $value): void { $this->sub[$this->route($key)]->set($key, $value); }

    /**
     * {@inheritDoc}
     *
     * @param int|string $key   Key to read.
     * @param mixed      $value Receives the decoded value on hit.
     *
     * @return bool
     */
    #[\Override]
    public function get(int|string $key, mixed &$value): bool { return $this->sub[$this->route($key)]->get($key, $value); }

    /**
     * {@inheritDoc}
     *
     * @param int|string $key Key to delete.
     *
     * @return void
     */
    #[\Override]
    public function delete(int|string $key): void { $this->sub[$this->route($key)]->delete($key); }

    /**
     * {@inheritDoc}
     *
     * @param int|string $key   Key to probe.
     * @param int|null   $vtype Receives stored type id on hit when provided.
     *
     * @return bool
     */
    #[\Override]
    public function has(int|string $key, ?int &$vtype = null): bool { return $this->sub[$this->route($key)]->has($key, $vtype); }

    /**
     * {@inheritDoc}
     *
     * @param int $type Engine type constant.
     *
     * @return bool
     */
    #[\Override]
    public function isNullType(int $type): bool { return $this->sub[0]->isNullType($type); }

    /**
     * {@inheritDoc}
     *
     * @return int Sum of live entries across all stripes.
     */
    #[\Override]
    public function count(): int
    {
        $n = 0;
        foreach ($this->sub as $e) { $n += $e->count(); }
        return $n;
    }

    // ===================== iteration (k-way merge by hrtime tag) =====================

    /**
     * {@inheritDoc}
     *
     * @return void
     */
    #[\Override]
    public function rewind(): void
    {
        foreach ($this->sub as $e) { $e->rewind(); }
        $this->pick();
    }

    /** Choose the valid sub-store with the smallest tag; ties -> lowest stripe index (deterministic). */
    private function pick(): void
    {
        $best = -1;
        $bestTag = 0;
        foreach ($this->sub as $i => $e) {
            if (!$e->valid()) { continue; }
            $tag = $e->currentTag();
            if ($best < 0 || $tag < $bestTag) { $best = $i; $bestTag = $tag; }
        }
        $this->curStripe = $best;
    }

    /**
     * {@inheritDoc}
     *
     * @return bool
     */
    #[\Override]
    public function valid(): bool { return $this->curStripe >= 0; }

    /**
     * {@inheritDoc}
     *
     * @return mixed
     */
    #[\Override]
    public function current(): mixed { return $this->curStripe >= 0 ? $this->sub[$this->curStripe]->current() : null; }

    /**
     * {@inheritDoc}
     *
     * @return mixed
     */
    #[\Override]
    public function key(): mixed { return $this->curStripe >= 0 ? $this->sub[$this->curStripe]->key() : null; }

    /**
     * {@inheritDoc}
     *
     * @return void
     */
    #[\Override]
    public function next(): void
    {
        if ($this->curStripe >= 0) { $this->sub[$this->curStripe]->next(); }
        $this->pick();
    }

    /**
     * {@inheritDoc}
     *
     * @param int $position Zero-based insertion-order index.
     *
     * @return void
     */
    #[\Override]
    public function seek(int $position): void
    {
        if ($position < 0) { throw new \OutOfBoundsException('seek position must be >= 0'); }
        $this->rewind();
        for ($i = 0; $i < $position; $i++) {
            if (!$this->valid()) { throw new \OutOfBoundsException('seek position ' . $position . ' out of range'); }
            $this->next();
        }
        if (!$this->valid()) { throw new \OutOfBoundsException('seek position ' . $position . ' out of range'); }
    }

    // ===================== lock / lifecycle =====================

    /**
     * Acquire every sub-store lock in ascending order (consistent order => no deadlock).
     *
     * {@inheritDoc}
     *
     * @return void
     */
    #[\Override]
    public function lock(): void
    {
        foreach ($this->sub as $e) { $e->lock(); }
    }

    /**
     * {@inheritDoc}
     *
     * @param bool $silent When true, suppress release errors on imbalance.
     *
     * @return void
     */
    #[\Override]
    public function unlock(bool $silent = false): void
    {
        // Release in reverse acquisition order.
        for ($i = $this->stripes - 1; $i >= 0; $i--) { $this->sub[$i]->unlock($silent); }
    }

    /**
     * {@inheritDoc}
     *
     * @return void
     */
    #[\Override]
    public function compact(): void
    {
        foreach ($this->sub as $e) { $e->compact(); }
        $this->reportDistribution('compact');
    }

    /**
     * Cold-path skew observability (L2): when the M6 diagnostic sink is installed,
     * emit the per-stripe live distribution so an operator can tell whether
     * striping is actually buying concurrency or the load is collapsing onto a few
     * stripes. Gated on a cheap null check — zero cost and zero work when no sink
     * is installed — and never allowed to throw out of a teardown/maintenance path.
     */
    private function reportDistribution(string $phase): void
    {
        if (!Flat::diagnosticsActive()) { return; }
        try {
            $counts = [];
            $total = 0;
            $max = 0;
            foreach ($this->sub as $i => $e) {
                $n = $e->count();
                $counts[$i] = $n;
                $total += $n;
                if ($n > $max) { $max = $n; }
            }
            // imbalance: max stripe load / mean load. 1.0 = perfectly even; up to
            // $stripes when every live entry has collapsed into one stripe.
            $imbalance = $total > 0 ? ($max * $this->stripes) / $total : 0.0;
            Flat::emitDiag('striped.distribution', [
                'store'            => $this->name,
                'phase'            => $phase,
                'stripes'          => $this->stripes,
                'counts'           => $counts,
                'total'            => $total,
                'max'              => $max,
                'imbalance'        => $imbalance,
                // Per-stripe geometry so the total-vs-split semantics (L4) are
                // observable: each stripe gets these, not the configured totals.
                'per_stripe_slots' => \intdiv($this->slotCount, $this->stripes),
                'per_stripe_size'  => \intdiv($this->size, $this->stripes),
            ]);
        } catch (\Throwable) {
            // Diagnostics must never break a cold maintenance/teardown path.
        }
    }

    /**
     * Best-effort: detach EVERY stripe even if one fails, so a single stripe's
     * error never strands the others' links/handles. The first error is surfaced
     * after all stripes have been closed.
     *
     * @return void
     */
    #[\Override]
    public function close(): void
    {
        $this->reportDistribution('close'); // emit while sub-stores are still attached
        $err = null;
        foreach ($this->sub as $e) {
            try { $e->close(); } catch (\Throwable $t) { $err ??= $t; }
        }
        if ($err !== null) { throw $err; }
    }

    /**
     * Atomic across stripes: verify EVERY sub-store is solely owned before deleting
     * ANY (phase 1), then force the deletes (phase 2). A refused destroy therefore
     * leaves the whole store intact instead of half-torn, and the commit phase
     * cannot throw part-way (it has already been verified).
     *
     * @return void
     *
     * @throws \RuntimeException when any stripe still has peer connections.
     */
    #[\Override]
    public function destroy(): void
    {
        foreach ($this->sub as $i => $e) {
            if (!$e->isSoleConnection()) {
                throw new \RuntimeException('cannot destroy striped Fast "' . $this->name
                    . '": another process is still connected to stripe ' . $i);
            }
        }
        foreach ($this->sub as $e) { $e->destroy(true); }
    }
}
