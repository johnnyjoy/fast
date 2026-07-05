# Fast Rewrite Plan

> **Archived.** Pre-Flat rewrite plan. Current layout: [`../engine-architecture.md`](../engine-architecture.md).

Status: foundational plan — the original 8-phase rewrite sequence and its validation
gates. The **live** state and next-step priorities now live in `archive/continuity-2026-06.md`
and `archive/efficiency-audit.md` §7; this doc is retained for the phase definitions,
validation gates, and benchmark policy it established.

This plan maps the rewrite brief into a concrete sequence of work for `\Fast`.
It assumes the current implementation is a behavioral prototype only and that the
final engine must move canonical state into binary shared memory.

## Rewrite Goal

Build a real shared-memory storage engine for PHP that keeps the public product surface:

- `ArrayAccess`
- `Iterator`
- `Countable`
- direct methods
- batch operations
- shared attach/destroy lifecycle

The rewrite target is:

- no PHP-array canonical store
- no whole-store serialization
- no shared-memory-as-object-cache design
- lock-free normal reads where possible
- one writer semaphore
- binary-shared canonical state

## Architectural Decisions

### Keep

- Public API shape
- insertion-order iteration contract
- named-callable batch API
- scalar fast paths
- shared / attach / destroy lifecycle

### Replace

- PHP-array directory / id table / order list as canonical storage
- refresh/persist whole-object state
- any read path that always reloads everything
- local-only segment bookkeeping that is not reflected in shared memory

### Delete

- snapshot-style shared-state publication
- behavior that depends on PHP object graph serialization
- hidden stale local state as the source of truth

## Target Shared-Memory Layout

### Primary segment

The primary segment should contain:

- fixed header
- seqlock / revision fields
- geometry and offsets
- segment directory
- key directory
- id table
- order metadata
- allocator metadata

### Overflow segments

Overflow segments should contain:

- segment header
- value arena
- free-list heads
- live-block counters
- generation / flags

### Canonical storage rule

The canonical directory, ids, order, and values must live in shared memory.
PHP arrays may only be used for:

- attached `Shmop` handles
- local cached geometry
- pending reference buffers
- iterator cursors
- minimal per-process scratch state

## Phased Rewrite

### Phase 1: Binary header and geometry

Deliverables:

- fixed binary primary header
- shared revision / sequence fields
- stable geometry offsets
- attach validation against magic / version / size
- capability to open or reject the engine safely

Exit criteria:

- attach and destroy work
- header round-trips cleanly
- stale or invalid segments fail closed

### Phase 2: Shared directory and id table

Deliverables:

- compact binary directory slots
- binary id table slots
- generation-based stale-entry protection
- exact-key validation path
- `has()` does not decode values

Exit criteria:

- `set/get/tryGet/delete` work from shared memory
- missing versus stored-null behavior is correct
- stale-slot reuse is safe

### Phase 3: Binary value arena and allocator

Deliverables:

- value block headers
- size-class allocator
- free lists
- block reuse
- overflow segment growth

Exit criteria:

- scalar values avoid PHP serialize/unserialize
- arrays/objects use the best available encoding path
- shared growth works across segments

### Phase 4: Read path hardening

Deliverables:

- lock-free normal reads
- sequence validation before and after read
- fallback behavior only when contention requires it
- no semaphore on the normal read path

Exit criteria:

- read-heavy benchmarks stay stable
- concurrent readers do not corrupt values
- false positives from collisions are rejected

### Phase 5: Write path publication

Deliverables:

- one writer semaphore
- re-entrant batch locking
- short seqlock publish window
- no lost update under two writers

Exit criteria:

- concurrent write tests pass
- batch API remains re-entrant
- no stale publish clobbers a newer writer

### Phase 6: Order chain and iteration

Deliverables:

- insertion-order chain in shared memory
- delete unlinks safely
- iteration is best-effort under contention but never corrupt

Exit criteria:

- `Iterator` contract passes
- iteration order stays stable for normal use

### Phase 7: Compaction and segment GC

Deliverables:

- tombstone purge
- allocator sweep
- drained overflow segment deletion
- destroy removes all known live segments

Exit criteria:

- compaction is explicit and safe
- no orphaned SysV segments remain after destroy

### Phase 8: Stats and observability

Deliverables:

- `stats()`
- `__debugInfo()`
- capacity / live / free / segment metrics
- compact recommendation signal

Exit criteria:

- stats are useful and cheap
- they do not dump stored values

## Validation Gates

Each phase must satisfy:

1. `php Fast/test/run.php`
2. `php Fast/test/stress_100k_bench.php`
3. the multi-process parallel stress harness
4. no new orphan shared-memory segments

## Benchmark Policy

Do not accept a phase unless:

- correctness remains green
- throughput does not regress materially
- the new structure is measurably better or clearly safer

## Current Rewrite Risk

The highest-risk areas are:

- shared header layout
- allocator correctness
- stale generation handling
- concurrent publication
- destroy / GC behavior

Those should be rewritten and benchmarked before any cosmetic cleanup.

