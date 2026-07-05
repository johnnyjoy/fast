# Fast Architecture Audit & Optimization Plan — 2026-06

> **Archived.** Pre-Flat rewrite. Current layout: [`../engine-architecture.md`](../engine-architecture.md).

Scope: `Fast.php`, `Fast/Shared.php`, `Fast/Journal.php`, `Fast/Format.php`.
Lens: the spec in `docs/specification.md` — ease of use, performance,
stability. Everything that does not serve those is on trial here.

This is a hostile audit. The standard is: **the least code and the least
indirection we can get away with.** Patterns are not a defense.

---

## 0. The numbers (why this is out of control)

| File | Lines |
|---|---|
| `Fast.php` (the "facade") | 1,130 |
| `Fast/Shared.php` (engine) | 2,543 |
| `Fast/Journal.php` (engine) | 1,362 |
| `Fast/Format.php` (codec) | 400 |
| **Total subsystem** | **~5,435** |
| Test files | 142 |

Five and a half thousand lines and 142 test files to make an array you can share
between processes. That is the headline problem. A user-facing "array that lives
in shared memory" should be a fraction of this.

---

## 1. Finding: the facade is a coat-rack of do-nothing methods

`Fast.php` contains **~22 private methods that are pure one-line pass-throughs**
to `$this->shared->X()`. They add no logic, no locking, no translation:

```text
openShared, closeSharedSegments, deleteSharedSegments, lock, unlock,
syncSharedStateIfStale, readSharedSequence, isSequenceValueStable,
sharedRegionLayout, layoutSet, layoutHas, layoutDelete,
layoutAdvanceIteratorToLive, layoutValid, layoutKey, layoutCurrent,
layoutCurrentBlock, layoutNext, layoutRewind, layoutSeek, layoutCount
```

Example (Fast.php):

```php
private function layoutSet($key, $value): void { $this->shared->layoutSet($key, $value); }
private function lock(): void { $this->shared->lock(); }
private function layoutNext(): void { $this->shared->layoutNext(); }
```

This is the "level of indirection that doesn't make sense." It exists because
`Fast` is being treated as a facade as a matter of religion. **Verdict: delete
all of them.** Call `$this->shared->lock()` / `$this->shared->layoutNext()` at the
call sites (already done for `layoutTryGet` in P2.6 with zero loss). Saves ~90
lines and removes a whole layer of name-chasing.

---

## 2. Finding: we read storage at least eight different ways

For a single concept — "get the value (or existence) of a key" — the subsystem
has all of these:

1. `Fast::offsetGet` → `Fast::sharedFetchTracked` (3-tier seqlock) → `Shared::layoutTryGet` / `Shared::probeSharedKey`
2. `Fast::offsetExists` → `Fast::hasValue` (3-tier seqlock — **copy of #1**) → `Shared::layoutHas` / `Shared::probeSharedKey`
3. `Fast::each` → `Shared::layoutTryGet` loop under a held lock
4. `Fast::current`/`Fast::key` → `Fast::sharedIteratorCurrentBlock` (cached) → `Shared::layoutCurrentBlock`
5. `Shared::layoutTryGet` (same-sequence fast read)
6. `Shared::probeSharedKey` (normalized full read)
7. `Shared::layoutHas` (existence-only)
8. `Shared::layoutFindSlotNormalized` (slot find)
   plus `Journal::tryGet` for local mode.

The worst offender: **`sharedFetchTracked` and `hasValue` are the same three-tier
seqlock retry ladder, pasted twice** (fast-path same-sequence read, spin-retry
loop, lock fallback). One returns the value, the other returns a presence bool.
That is one algorithm written twice (Fast.php ~423–479 and ~493–548).

**Verdict:** collapse to **one** stable-read primitive:

```php
// one ladder, parameterized by what the probe extracts
private function readStable(callable $probe): mixed
```

`offsetGet`, `offsetExists`, and `each` all go through it. Existence becomes "read
the value-kind via the probe"; value becomes "read the value via the probe." One
ladder, one consistency model, one place to get the seqlock right.

---

## 3. Finding: iteration is implemented twice

`foreach` (the `Iterator` methods) and `each()` are two separate traversal loops
over the same cursor, differing only in lock policy (release-after-rewind vs
hold-throughout). They also diverge in read path (`each` calls `layoutKey/
layoutCurrent` raw; the iterator uses a per-element block cache) and in stats
(`each` skips the iterator counters).

**Verdict:** one internal walk, parameterized by lock policy. `foreach` passes
"lock once, release"; `each` passes "hold the lock." Same cursor, same read path,
same counters. Removes the third/fourth read paths above as a side effect.

---

## 4. Finding: stats() is a 60-key internal dump, and tests are welded to it

`Fast::stats()` returns ~60 keys, most of them raw mechanism counters:
`directory_slot_patches`, `direct_value_publishes`, `value_block_patches`,
`id_slot_writes`, `order_node_patches`, `iterator_block_cache_hits`,
`allocatorSmallerOverwrites`, … This is an implementation x-ray, not a health
readout. ~30 test files assert on specific keys of it.

This is the "poisoned tests" case from the spec. The tests assert *how the
machine works internally* instead of *what Fast guarantees*. That freezes the
implementation: you cannot simplify the write path without breaking tests that
count `direct_value_publishes`.

**Verdict:**
- Public `stats()` shrinks to a **health readout** (mode, name, live count, size,
  growing?, reused?, bounded?). That is contract.
- Internal counters move behind a test-only introspection hook (like the
  `fast_test_journal()` helper added in P2.6) so mechanism tests can still assert
  on mechanism **without it being public API or contract**.
- Rewrite the ~30 tests: behavioral guarantees stay as `stats()` contract;
  mechanism assertions move to the introspection hook. Tests describe usage, not
  internals.

---

## 5. Finding: four classes and three hops for a write

A `$fast['k'] = $v` travels: `Fast::offsetSet` → `Fast::storeValue` →
`Fast::layoutSet` (pass-through) → `Shared::layoutSet` (290+ lines, multiple
publish strategies) → `Format::*`. Reads add the seqlock ladder on top.

`Shared::layoutSet` alone branches across direct-insert / full-insert /
direct-value / full-publish paths, each with its own counters (section 4). That
is "eight ways to write" hiding inside one method.

**Verdict:** keep the engine in `Shared`, but (a) drop the `Fast` pass-through
layer (section 1), and (b) consolidate the publish strategies to the minimum the
benchmarks actually justify. Every publish variant must earn its place with a
benchmark delta; otherwise it collapses into the general path.

---

## 6. Finding: symbol names describe HOW, and they are too long

A method/property name should say **what it does**, short and intuitive. Current
names say how, or restate their class prefix, or both:

| Current | Proposed |
|---|---|
| `layoutAdvanceIteratorToLive` | `skipToLive` |
| `sharedIteratorCurrentBlock` | `currentBlock` |
| `invalidateSharedIteratorBlockCache` | `clearBlockCache` (or delete) |
| `flushPendingOffsetWriteback` | `commitPending` |
| `syncSharedStateIfStale` | `refresh` |
| `isSequenceValueStable` / `readSharedSequence` | `seqStable` / `seq` |
| `decorateIteratorAccessFailure` | delete (inline) |
| `layoutFindSlotNormalized` | `findSlot` |
| `layoutReadValueBlockMetaById` | `blockMeta` |
| `allocatorClassForFree` | `freeClass` |
| `writeSharedBytesAtPayloadOffset` | `writeAt` |
| `buildSharedGeometry` | `geometry` |

Inside `Shared`, the `layout*` / `shared*` / `allocator*` prefixes are noise —
the class already says it is the shared layout/allocator. Drop the prefixes.

### Properties (the pending-write slot is six fields for one thing)

```text
pendingOffsetKey, pendingOffsetExists, pendingOffsetOriginalExists,
pendingOffsetOriginalValue, pendingOffsetValue, pendingOffsetDirty
```

Six properties to track one pending nested write. **Verdict:** one small value
object / array `$pending = ['key'=>…, 'value'=>…, 'had'=>…, 'orig'=>…]`, or a
2-field dirty-flag + struct. Same for the sprawling `allocator*Overwrites` /
`*Patches` counter fields — they collapse with section 4.

---

## 7. Finding: the spec'd lifecycle does not exist

The spec (section 7) requires link-counted ownership. The model (corrected by the
owner):

- `Fast` is **non-persistent by default**; persistence is opt-in (`persistent`
  config key).
- **Non-persistent:** when the last holder detaches (link counter → 0) the store
  auto-destroys — last one out turns off the lights (like an open file freed when
  the final handle closes).
- **Persistent:** survives link counter 0; removed only by explicit `destroy()`.
- `destroy()` succeeds **only when link counter == 1** (sole owner) in *both*
  modes; link counter > 1 → error. Persistence does not change the destroy rule,
  only the last-detach auto-reclaim behavior.

**None of this exists.** `grep` for link/refcount in `Shared.php` finds only
free-list node "unlinking," not process link counting. Worse, a P2.5 change
mislabeled named stores as "persistent-only" — that was wrong and contradicts the
intended default (non-persistent). Today `detach()` just drops local handles,
`destroy()` nukes the store with no ownership check, and there is no last-detach
auto-reclaim.

**Verdict:** this is net-new work, not cleanup. It needs:
- a link counter in the shared header (atomic inc/dec under the writer lock),
- construct/attach/wake increment; detach/destructor/sleep decrement,
- a `persistent` flag in the header (default off),
- non-persistent last-detach auto-destroy/reclaim,
- `destroy()` sole-owner (count == 1) check in both modes,
- crash tolerance (stale link counts must not wedge a store forever — needs a
  recovery/staleness story).

This is the one area where we *add* code. Everything else shrinks.

---

## 8. Finding: no shrink

The store grows (segments append) but shared memory never shrinks: `compact()`
throws in shared mode, deletes reuse blocks but never release segments, and the
arena frontier only moves forward (Fast.php compact() docblock admits this). The
spec requires grow **and** shrink.

**Verdict:** add bounded reclaim for shared mode — release trailing segments when
live data drops below a threshold; make `compact()` actually compact (or fold it
into automatic bounded maintenance off the hot path, per the guiding principles).

---

## Optimization plan (phased, benchmark-gated)

Ordered so each phase is independently shippable and keeps the **behavioral**
contract green. Mechanism tests are expected to churn (section 4, and spec
section 13 on test discipline).

This is in-development code. There is **no legacy to support and no backwards
compatibility.** Do not preserve old shapes, old method names, old config keys,
or old `stats()` keys "for compatibility." If the spec says change it, change it
outright.

**Phase A — kill dead indirection (pure shrink, no behavior change)**
- Delete the ~22 `Fast` pass-through methods (section 1); inline to `$this->shared->…`.
- Collapse `sharedFetchTracked` + `hasValue` into one `readStable` ladder (section 2).
- Unify `foreach`/`each` onto one internal walk (section 3).
- Expected: ~150–250 lines out of `Fast.php`, zero behavioral change, full suite
  green except mechanism-counter tests.

**Phase B — de-poison diagnostics**
- Shrink public `stats()` to the health readout; move mechanism counters behind a
  test-only hook (section 4).
- Rewrite the ~30 stats tests: behavior → `stats()`; mechanism → hook.

**Phase C — names**
- Rename per the table (section 6); drop class-prefix noise; collapse the 6-field
  pending slot and the counter sprawl.
- Mechanical, do last so earlier diffs stay readable.

**Phase D — lifecycle (net-new, per spec section 7)**
- Implement link-counted ownership, non-persistent (default) vs persistent,
  sole-owner `destroy()`, non-persistent last-detach auto-reclaim, crash tolerance.
- New tests written **first** as the contract, then code to satisfy them.

**Phase E — shrink memory (per spec section 9)**
- Bounded reclaim / real shared compaction.

Success metric: a user-facing "shared array" whose facade is a few hundred lines,
with one read path, one write path, one iteration path, one diagnostics readout,
and a real lifecycle — at equal or better benchmark numbers.
