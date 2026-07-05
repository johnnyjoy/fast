# Fast Design Law

Status: current approved **implementation direction** for `\Fast`.

This document is **not** the behavior contract and **not** public API. The behavior
contract lives in `docs/specification.md`. This document records how the
implementation currently intends to satisfy that contract.

```text
docs/specification.md = behavior law / user-facing contract
docs/design-law.md    = current approved implementation direction
```

If the design law and the specification ever disagree, **the specification wins**
and this document is corrected.

These are the current chosen internal mechanisms, each backed by a clean-room
experiment in `docs/design-study.md` (§9a–§9f). They exist to aid
development and are subject to specification §11 (no design-pattern religion) and
§2 (the priorities). They change **only when new evidence beats them, never on
preference**.

Every mechanism here ultimately serves one mission (specification §0): **Fast must
be correct, fast, and easy to use** on the canonical stress workload and the public
contract. A mechanism that does not move Fast toward that goal (or that protects a
non-negotiable so the goal can be met safely) does not earn its place.

---

## Economy of mechanism (the governing law)

```text
The most code we want is the least code we can use.
The most mechanism we want is the least mechanism we can get away with.
```

This is law, not a preference. It governs every mechanism in this document.

The safest, fastest, most maintainable mechanism is the **smallest** mechanism that
satisfies the contract. "Minimal code" is not decoration; it is the design target:
the least mechanism that still meets the specification.

What this **does not** mean:

```text
This does not mean stunt minimalism.
This does not mean unreadable cleverness.
This does not mean deleting code needed for correctness.
This does not mean refusing structure that earns its cost.

It means no extra machinery.
```

Specifically forbidden when unjustified:

```text
duplicate paths
ornamental classes
public diagnostics theater
hot-path counters that do not protect correctness
callbacks that do not earn their cost
adapter layers that do not earn their cost
helper classes that merely move code around
method families that exist only because they are conventional
```

The burden is on **every** mechanism to justify itself — not on the reviewer to
prove it is bad. A mechanism earns its place only when it does at least one of these:

- makes the public object easier to use
- makes the hot path faster
- makes multi-process behavior safer or more correct
- removes more complexity than it adds
- enables a required guarantee that cannot be achieved more simply

Otherwise, delete it.

## Class structure is scaffolding, not architecture

The current development split names these pieces:

```text
Fast.php
Fast/Shared.php
Fast/Journal.php
Fast/Format.php
```

These are **development tools**. They are not public contract. They are not permanent
architecture. They are allowed only while they serve ease of use, performance, or
stability (specification §11).

```text
Class boundaries are implementation details unless they serve ease of use,
performance, or stability.

Temporary separation for development is allowed.
Permanent indirection must earn its cost.
```

### Internal vocabulary is internal

```text
Shard, segment, layout, allocator, journal, and codec vocabulary is internal
unless explicitly documented as public.
```

The public lifecycle is exactly: construct (`new Fast('name')` opens or creates) →
use like an array → `close()` (disconnect this process) → `destroy()` (administrative
delete, sole-owner only). Open/create/attach/migrate mechanics, shards, segments,
and the attachment table are private implementation; they must never surface as
public methods or public concepts. There is no public `open()`, `openShared()`,
`attachShared()`, attach/shard/segment method, or public `compact()`/`stats()`.

`Journal`:

```text
Journal exists only if it earns its cost.
If Journal merely serves Fast and imposes overhead, indirection, or maintenance
cost without buying correctness or performance, it should be folded back into Fast
or eliminated.
```

`Shared` and `Format`:

```text
Shared and Format may remain separate while they clarify the implementation and
testing. They are not sacred.
If the final hot path is faster, clearer, and safer with fewer boundaries, fewer
boundaries win.
```

This pass does not order any merge. The shortest correct path wins; merges happen
only when the evidence shows fewer boundaries are faster, clearer, and safer.

## FFI is deferred — build on shmop/SysV alone

```text
The baseline is shmop/SysV only.
FFI is deferred because it is commonly treated as a security risk and commonly
disabled (notably under PHP-FPM).
FFI may be reconsidered later as an optional accelerator, but must not be required
for correctness, lifecycle, crash safety, growth, shrink, compaction, tests, or
normal operation.
```

`ext-ffi` is widely treated as a security risk and is commonly disabled. Fast is
therefore developed against `shmop`/SysV **only**. FFI (POSIX
`shm_open`/`ftruncate`/`mmap` resize, kernel `shm_nattch`) is a possible **future
optional accelerator** and must not be factored into development now or become a
dependency. Every mechanism below is achievable without FFI. (Contrast igbinary,
specification §8, which is genuinely mandatory.)

## 1. Index: cache-line bucket + fingerprint (study §9c)

Bucketized open addressing: ~8 entries packed per ~64-byte bucket, each carrying a
1-byte fingerprint tag. One bucket read yields all its candidates, scanned
in-memory; key bytes are touched only on a tag match. Linear bucket probing on
overflow.

Why (measured against the old open-addressing 40-byte-slot directory): **~5× less
directory memory** (8 vs 40 B/entry) and **flat tails** — at load 0.9 the old design
hit p99 75 region reads on a hit and **403 on a miss/`isset`**; bucket-fp held p99
**9 / 43**. A miss rejects up to 8 candidates per single bucket read without ever
copying key bytes — and `isset` is a first-class operation here.

- **Serves:** performance (memory + tail latency) and ease of use (`isset` is first-class).
- **Rejects:** the old 40-byte open-addressing slot directory.

## 2. Index grow/shrink: foreground incremental rehash (study §9d)

Redis dual-table model: stand up the new table, migrate **K buckets per op**, reads
and writes consult both tables until the migration drains. **Use K = 8–16.**

Why: converts a single ~512k-entry stop-the-world rehash into bounded **≤~100-entry**
maintenance steps at the *same* total work, with readers never stalling (~1.0–1.05
bucket reads/lookup). Shrink has a tighter migration budget than grow — **K ≥ 4** is
required so the migrator keeps up.

- **Serves:** performance and stability (no reader stall during resize).
- **Rejects:** the single stop-the-world rehash.

## 3. Value allocator: slab + bounded incremental compaction (study §9b)

Size-class slab free-list. When utilization falls below a watermark, run **bounded
foreground compaction driven by a resumable arena cursor** (a fixed work budget per
trigger, no per-trigger O(live) snapshot).

Why: matches the best steady utilization (~0.83) **and** actually shrinks (15.7 MiB →
~2 MiB after a drain) while copying **~15× less** than log-structuring. Log-structured
arenas are **rejected**: they only reach high utilization by paying ~5× write
amplification, which is only affordable with background cleaner threads — and PHP has
none (§0.1 of the study).

- **Serves:** performance and stability (high steady utilization that actually shrinks).
- **Rejects:** log-structured arenas (≈5× write amplification; needs a background
  cleaner PHP does not have).

## 4. Arena grow/shrink without FFI (study §9f)

`shmop` segments are fixed-size, so footprint is managed at **segment granularity**:
compact live blocks toward the front and **drop fully-empty trailing segments** (the
specification §9 "release trailing segments" rule). A size change migrates blocks with
the same **incremental dual-region** technique as the directory (bounded per-op;
readers follow a `generation` published in a small fixed control segment) — never a
stop-the-world copy. Footprint stays bounded by live data + fragmentation + at most
one in-flight migration (~1.5–2× transient). No per-delete OS shrink is promised
(specification §9).

Segment migration is **internal and invisible**. Readers never observe the migration
as two stores; they observe one logical Fast store through the published
`generation`. The multi-segment layout and any in-flight migration are implementation
detail, not part of the access contract (specification §§3–4).

- **Serves:** stability (bounded footprint) and ease of use (migration is invisible).
- **Rejects:** stop-the-world copy, per-delete OS-shrink promises, and FFI
  `ftruncate`-style resize.

## 5. Lifecycle without FFI (study §9e)

Two distinct concerns, not to be conflated — one governs Fast's **logical ownership
rules**, the other governs **physical memory reclaim**:

- **Logical ownership — the PID table (specification §7).** The process link count is
  an in-segment per-process attachment table. It decides who may act: `destroy()` is
  allowed only when exactly one **live** connected PID remains. The count is derived
  by sweeping entries with `posix_kill($pid, 0)` and pruning dead PIDs, so a crashed
  holder never blocks `destroy()`. Swept only on open/close/destroy/sleep/wake
  (cold path), never on `get`/`set`.
- **Physical reclaim — kernel `IPC_RMID`, marked at reclaim across *all* segments.**
  Freeing the actual segment is the kernel's job once the segment is **marked for
  removal**. Reclaim (non-persistent final close, `destroy()`, or reopen of crash
  debris) **enumerates the store's whole segment key space** — segment 0 plus every
  growth segment — and `shmop_delete()`s each, setting SysV `IPC_RMID` so the kernel
  frees it at `nattch=0`. This is deliberately **not** done at *create* time:
  `IPC_RMID` removes the key, and Fast's growth segments are attached lazily **by
  key** by peers and by the owner itself (see §4 migration), so marking at create
  would break cross-process and same-process re-attach. Persistent stores are never
  marked except by `destroy()`.
- **Crash (`kill -9`) reclaim — the reopen-reaper.** A crash runs no cleanup, so a
  dead owner's PID lingers in the table and its segments persist. The next process to
  open the **same name** sweeps the table: if every recorded owner is dead and the
  store is non-persistent, it is crash debris — its segments (root + growth) are
  reclaimed and a fresh store is created in their place. Reclaim of a non-persistent
  store is therefore guaranteed at its final clean close and on any later reopen of a
  crashed store; the one residual is a crashed, **uniquely-named**, non-persistent
  store that is *never* reopened, which only an out-of-band reaper or an `IPC_RMID`
  -at-create policy (rejected above) could collect.

These compose cleanly: the PID table tells Fast *when it is allowed* to mark a store
for removal (logical rule); `IPC_RMID` across all enumerated segments then guarantees
the memory *actually goes back* (physical rule).

- **Crash consistency (specification §10):** seqlock (odd sequence = write in
  progress) + CRC backstop; readers fail closed. Validated: **0 torn reads** across
  300 `kill -9` crashes and ~924k live concurrent reads.

- **Serves:** stability and ease of use (crash-safe auto-reclaim; sole-owner `destroy()`).
- **Rejects:** FFI `shm_nattch`, and conflating logical ownership with physical reclaim.

---

## 6. Writer refresh is incremental — never arena-scale (audit §5, implemented 2026-06-28)

A shared write must not pay for the store's history. When a writer observes that a
peer bumped the revision, it must **not** rebuild its local view by rereading the
arena up to `arena_frontier` (the high-water frontier, not the live size). That made
an ordinary write cost O(arena high-water) and collapsed under churn.

The law:

- **Reads and writes both probe narrowly.** The reader already probed a single key
  from shared memory under seqlock validation (`probeSharedKey`). The writer now does
  the same: on a peer revision bump it adopts only the fixed-size header scalars
  (geometry, counts, frontier, id/order cursors, order head/tail, free-list heads),
  drops its per-record caches, and **faults in only the directory/id/order/value
  slots it actually touches** (`layoutLoadDirectorySlot`/`layoutLoadIdSlot`/
  `layoutLoadOrderNode`). Cost is O(touched slots), not O(arena).
- **Whole-store consumers may still do a full pass.** Iteration and serialize are
  inherently O(live); they keep the full refresh, gated by a `mirrorComplete` flag so
  a partial writer view is rebuilt before a whole-store walk. The full rebuild is the
  *exception* (attach, iterate, recover), not the ordinary write path.
- **One source of truth.** Shared memory is authoritative; the in-process arrays are
  a write-through cache that may be partial. Never trust a cached slot across a peer
  bump without re-reading it.

- **Serves:** performance (kills the churn cliff: ~139,000 µs → ~57 µs per peer-bump
  write at 1k live) and stability (seqlock/lock discipline and fail-closed reads are
  unchanged; the writer sync runs under the writer lock).
- **Rejects:** rereading `arena_frontier` on the write path; a separate writer-only
  copy of the reader's probe logic; any format change (this was achieved with the
  existing layout).

---

## 7. Allocation is size-classed — reuse is O(1) and bounded (audit §5b, implemented 2026-06-28)

A value update must not pay for the store's allocation history. The single-process
update collapse (~351/s, `reused_allocations = 0`, `free_block_count` climbing) was
not a refresh problem; it was the allocator handing out **exact-fit** blocks. A value
that grew one byte freed a block that was then exactly one byte too small for the
next request, so first-fit never reused it, the free list grew without bound, and
every allocation walked the whole chain.

The law:

- **Allocation rounds up to a size class.** Every non-oversize block in a class is
  exactly that class's size. Same-class reuse is therefore O(1) — the head of the
  matching class always fits, with no first-fit walk — and a value that grows or
  shrinks within its class keeps the same block, so the update is an **in-place
  overwrite** under the seqlock, not a free+allocate. Oversize requests (larger than
  the biggest class) stay exact.
- **Class-crossing updates realloc; the vacated block is reused, not stranded.**
  Growing or shrinking across a class boundary allocates a new block (reusing freed
  space of the new class when present), publishes the pointer, then frees the old
  block — allocate-before-free, so the new allocation can never reuse a still-live
  block. This is what makes reuse *meaningful* under churn while the free list stays
  **bounded by the live set**, never by total operations.
- **Free-list traversal is bounded.** A single allocation walks at most a small fixed
  number of free-list nodes (`ALLOC_FREE_SCAN_LIMIT`); non-oversize classes never trip
  it, and it caps the oversize chain so a run of too-small blocks can never become an
  O(N) scan. Bad allocator metadata fails closed (bounds, magic, cycle, class checks).
- **Shared metadata stays correct.** Allocation runs under writer exclusion; published
  records remain seqlock-safe; the stored block length is the granted **capacity** and
  value blocks are self-describing, so a peer reading a larger-capacity slice still
  parses correctly. Writer incremental refresh (§6) is unchanged.

- **Serves:** performance (kills the single-process update collapse: ~369/s → ~58,600/s,
  ~159×) and stability (no format change, no public surface change, seqlock/lock
  discipline preserved).
- **Rejects:** exact-fit allocation that cannot reuse near-size churn; unbounded
  free-list scans; keeping an oversized block forever (the "graveyard"); and conflating
  reuse (this law) with **shrink** (returning frontier/segments to the OS — still open,
  §4/study §9b).

---

This document records direction. Where it and the specification (`docs/specification.md`)
ever disagree, the specification wins and this document is corrected.
