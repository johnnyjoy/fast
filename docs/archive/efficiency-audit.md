# Fast — Hostile Efficiency Audit (data format, segment sizing, grow/shrink)

> **Archived.** Pre-Flat rewrite. Current layout: [`../engine-architecture.md`](../engine-architecture.md).

**Date:** 2026-06-28
**Scope:** `Fast/Format.php`, `Fast/Shared.php`, `Fast/Journal.php`, `Fast.php`.
**Lens:** a high-efficiency systems programmer who treats every wasted byte and
every wasted syscall as a personal insult. Companion to the *structural* audit
(`docs/archive/structural-audit.md`); this one is about **bytes on the wire and memory
returned to the OS**, not method counts.

## Non-negotiables (the audit may not trade these away)

1. **Stability** — seqlock + lock + publish-last ordering stay. No torn reads.
2. **No data loss** — every change here must be crash-safe to the current standard.
3. **Ease of use** — the public face is `ArrayAccess` + magic + `foreach` + `count`.
   None of this changes.
4. **Multi-process** — cross-process visibility stays correct.

The goal is narrow and ruthless: **use only the memory we actually use, store it
in the tightest honest format, and give memory back when keys go away** — without
giving up any of the four things above.

This audit serves the one mission (specification §0): **Fast must use memory
honestly and perform well** on the canonical stress workload without trading away
correctness or ease of use.

---

## 0. The scoreboard (measured this session, PHP 8.1.2/Linux)

| Path | Insert @100k | Per-op | Behavior under churn |
|---|---|---|---|
| **Local** (Journal, post hash-index pass) | 229k ops/s | ~7 µs | flat, O(1)-expected |
| **Shared** (`Fast`) | 43–49k ops/s | ~22 µs | **collapsed**; arena grew until OOM |

Two hard facts fell out of the run:

- A shared stress run **leaked ~15 GB** of orphaned 24 MiB segments (629 of them).
- The shared engine **cannot survive sustained mixed-size churn** at a fixed budget,
  where the legacy engine can.

Both are efficiency failures, and both are fixable without touching the contract.

---

## 1. The headline: ~160 bytes of bookkeeping per entry

This is the single biggest inefficiency, and it is structural to the on-wire
format. For **one** live key/value, the engine maintains four separate records:

| Per-entry structure | Bytes | Source |
|---|---|---|
| Record frame header | **32** | `RECORD_HEADER_BYTES` (`Format.php:24`) |
| Directory slot | **40** | `DIRECTORY_SLOT_BYTES` (`Format.php:26`) |
| Order-list node | **24** | `ORDER_NODE_BYTES` (`Format.php:27`) |
| ID slot | **32** | `ID_SLOT_BYTES_LAYOUT` (`Format.php:28`) |
| Value-block header (shared) | **32** | `VALUE_BLOCK_HEADER_BYTES` (`Format.php:20`) |
| **Total fixed overhead / entry** | **~160 B** | — |

This is not an estimate — the research harness literally budgets it:
`baseline.php` sizes shared segments as `(valueBytes + 160) * n`. We are paying
**160 bytes of metadata to store, say, an 8-byte int**. For a 50-entry config
store that is fine; for the 50k–5M range the spec targets, it is the whole game.

### 1a. The record header is 75% air

`buildRecordFrameFromEncoded()` packs `a4V7` = **32 bytes** (`Format.php:30-43`):

```
magic(4) version(4) record_kind(4) key_kind(4) value_kind(4) flags(4) key_len(4) value_len(4)
```

- `magic` ("FXRF") and `version` (always 1) are **repeated on every record**. That
  is a per-entry tax to detect corruption the seqlock + CRC already guard.
- `record_kind` (live/tombstone), `key_kind` (0/1), `value_kind` (0–5), `flags`
  each take a full 32-bit word to hold **≤ 3 bits** of information.
- `key_len`/`value_len` are 32-bit even when the value is a 1-byte bool.

**Honest minimum:** 1 status byte (kinds + flags packed) + varint key_len + varint
value_len ≈ **3–8 bytes**. That is a **4–10× reduction** in header overhead.
Prior art: Protobuf/LevelDB/RocksDB **varint** length prefixes; Btrfs **item
headers** keep type+offset+size in a few bytes, not a word each.

### 1b. The directory slot is a 40-byte answer to an 8-byte question

`buildDirectorySlot()` = `PPPPCCvV` = **40 bytes** (`Format.php:263-266`):

```
key_hash(8) record_off(8) order_off(8) generation(8) state(1) key_kind(1) flags(2) key_len(4)
```

- Four **64-bit** fields. `generation` at 8 bytes is overkill; `record_off`/
  `order_off` are byte offsets into an arena that will never exceed a few GB —
  **32-bit (or 40-bit) offsets at 8-byte granularity** cover 32 GB.
- Storing the **full 64-bit hash** per slot is what Memcached, Folly **F14**, and
  Abseil **Swiss tables** explicitly avoid: they keep a **1-byte fingerprint/tag**
  per slot and compare the full key only on a tag hit. This is exactly the
  `bucket-fp` design your own study already chose (`design-study.md` §9c:
  *8 entries / 64-byte cache line, 5× less directory memory, flat tails*).

A directory of 262,144 slots × 40 B = **10.5 MB** to index 100k entries. The
bucket-fp form is ~2 MB **and** turns linear-probe tail latency into one
cache-line load.

### 1c. The order list is the worst possible iteration structure

Insertion order is a **singly-linked list of 24-byte nodes** (`Format.php:268`),
one per entry, chained by 8-byte offsets (`Shared.php` order region). Two costs:

1. **24 B/entry** = 2.4 MB per 100k just to remember order.
2. **Pointer-chasing** on `foreach`: every `next()` reads a node, then jumps to a
   directory slot at an unrelated offset (`Journal::advanceIteratorToLive`). This
   is cache-hostile — the opposite of how LMDB/LFS iterate (sequential scan of an
   append log or a B+tree leaf chain).

**Cheaper:** insertion order is already implied by **arena append order** for live
records. A compaction pass (see §4) can keep the arena in iteration order, making
`foreach` a linear arena walk with **zero** dedicated order structure — or, if a
side index is kept, a packed 32-bit-offset array (4 B/entry, contiguous, prefetch-
friendly) instead of a linked list.

### 1d. Int keys are always 8 bytes

`normalizeKey()` zig-zags then `pack('P')` → **8 bytes for every int key**
(`Format.php:159-177`), so key `7` costs the same as `9223372036854775807`. A
varint would make small int keys 1–2 bytes. Minor next to 1a–1c, but it is free
once a varint codec exists for the header.

> **Net of §1:** the format was designed for clarity, not density. A format diet
> (pack the header, fingerprint the directory, kill the linked list, 32-bit
> offsets, varint small ints) plausibly cuts per-entry overhead from **~160 B to
> ~30–50 B** — a 3–5× footprint win and a large cache-locality win, before any
> allocator change. This is the highest-leverage work available.

---

## 2. Segment sizing — we chew memory we are not using

### 2a. A 16 MB floor for an empty store

`DEFAULT_SHARED_SIZE = 16,777,216` (`Shared.php:34`). The comment claims *"an empty
store should be cheap … reserved address space, lazily faulted"* (`Shared.php:28-32`).
That is half-true: shmop reserves and the kernel lazily faults **pages**, so RSS
may stay low — but the SysV segment still counts against `shmall`/`shmmax`
accounting, shows up in `ipcs`, and is address space no peer can use. A store you
spun up for 50 config keys advertises a 16 MB segment.

**Cheaper:** start at the real fixed-region size (directory + id + order for the
chosen capacity, ~384 KB at 4096 slots) plus a small arena (tens of KB), and grow
**geometrically**. This is the dynamic-array / Redis listpack→hashtable threshold
pattern: pay for capacity when you reach it, not up front.

### 2b. Growth segments are full-size and over-provisioned

`allocatorGrow()` opens **one new `$sharedSize` segment per step** (`Shared.php:2358-2374`).
If the store was created at 24 MB and needs 1 KB more, it maps **another 24 MB**.
Combined with §2a there is no relationship between *bytes needed* and *bytes mapped*.

**Cheaper:** geometric arena growth with a sane cap (e.g., double up to N MB then
linear), the way jemalloc/tcmalloc grow runs, so the number of OS segments stays
small and proportional to live data.

### 2c. The directory must be over-sized at birth

The directory **cannot grow** (`Shared.php:1358-1362`, *"directory growth/rehash is
not supported; create the store with a larger capacity"*). So to hold 100k keys you
must pre-allocate ≥ 131,072 slots = directory + id + order **fixed regions sized for
the worst case forever**. Either you over-provision (waste) or you hit a hard wall.

**Cheaper:** a growable index — **Litwin linear hashing** or **extendible hashing**,
or Redis-style **incremental rehash** (your study §9d already validated incremental
rehash with bounded per-op work). Then capacity tracks live count instead of being
a guess frozen at `new Fast()`.

---

## 3. The growth-segment leak (reliability + ~15 GB observed)

This is the most urgent item because it borders on a data/resource-integrity bug.

- Non-persistent reclaim deletes only the segments **this process currently holds
  open**: `deleteSharedSegments()` loops `$this->sharedSegments` (`Shared.php:996-1000`).
- Growth segments 1…N are created on demand by whichever process happened to grow
  the arena (`Shared.php:2369`). A **different** process that only ever opened
  segment 0 will, on last-out reclaim, delete **segment 0 only** — segments 1…N
  are orphaned in the kernel forever.
- **`kill -9`** skips reclaim entirely: nothing is deleted, including segment 0.
- There is **no segment count in the header**, so no attached process can even
  enumerate the growth segments to clean them.

Observed: 629 × 24 MiB ≈ **15 GB** leaked after a killed stress run.

**Fix (no FFI, crash-safe, standard SysV idiom):**

1. **`IPC_RMID`-at-create for every segment** (`shmctl(id, IPC_RMID)` right after
   create+attach). The kernel then frees each segment automatically when its
   `nattch` hits 0 — *including after `kill -9`*. This is the textbook
   "mark-for-deletion, reclaim on last detach" pattern and is exactly what the
   study (§9e) claims for non-persistent stores; it is currently applied to the
   **primary** segment story but **not** to growth segments.
2. **Record the live segment count in the shared header** so any attached process
   can open + `IPC_RMID` all of them on explicit `destroy()` (persistent stores,
   where IPC_RMID-at-create is not wanted).

This is low-risk, ~localized to `openSharedSegment` + the header, and it directly
prevents the 15 GB class of leak.

> **RESOLVED (2026-06-28, reclaim pass).** The leak is fixed, but recommendation #1
> as written — `IPC_RMID`-at-*create* for **every** segment — was **rejected** after
> empirical testing: `IPC_RMID` removes the key from the namespace, and Fast's growth
> segments are attached lazily **by key** both by peer processes and by the owner
> itself after migration. Marking at create breaks cross-process and same-process
> re-attach (`mp_parallel_stress`, `shared_segments`). Implemented instead:
> - **Full-keyspace enumeration on reclaim** — `deleteSharedSegments()` now probes
>   segment 0 + every growth key (bounded by `MAX_SHARED_SEGMENTS`) and
>   `shmop_delete()`s each, so a process that only opened segment 0 still reclaims
>   segments 1…N. Replaces the old "loop `$this->sharedSegments` only" bug.
> - **Durable PID table in the header** (layout bumped to v2, header 176→512 B) plus a
>   `posix_kill($pid,0)` sweep, so liveness/ownership is derivable across processes.
> - **Reopen-reaper** — opening a non-persistent store whose every recorded owner is
>   dead reclaims its whole keyspace and recreates it fresh, collecting `kill -9`
>   debris on next use. The header carries `arena_frontier`/`arena_bytes`, so segment
>   count is *derived* durably — no separate count field was needed.
> - Fail-closed: foreign magic, layout mismatch, truncated header, or out-of-range
>   geometry all reject at attach time.
> Residual: a crashed, uniquely-named, non-persistent store that is *never* reopened
> still lingers until an out-of-band reaper or a future opt-in `IPC_RMID`-at-create
> for genuinely single-attach segments. See `docs/design-law.md` §5.

---

## 4. Grow/shrink — the missing half (give memory back)

`arena_frontier` is a **monotonic high-water mark**: it only increases
(`Shared.php:2350, 1568, 1696`) and is never lowered after deletes
(`Shared.php:2020-2062` frees blocks to the free-list but does not move the
frontier). `closeUnusedSharedAttachments()` explicitly *"does NOT reclaim or delete
any shared/OS memory"* (`Shared.php:969-975`). Delete half your keys and the
footprint does not budge — the §4 finding the design study already flagged.

The slab free-list (`SIZE_CLASSES`) **does** reuse freed value blocks within the
frontier, and as of the reuse pass (§5b) allocation rounds to size classes so reuse
is O(1) and the free list is bounded by the live set — but:

- Remainders **< 32 B** after a split are **permanently leaked**.
  Classic slab internal fragmentation; Memcached has the same "slab calcification"
  problem and added rebalancing to fix it.
- Nothing ever **compacts** live data toward the front and **drops trailing empty
  segments**, so the OS footprint never shrinks (the frontier is still a monotonic
  high-water mark — the shrink problem, distinct from the now-fixed reuse problem).

**Fix (the study's §9b decision): slab + bounded incremental compaction.**
Repack live value blocks forward a little each op (bounded work, no stop-the-world),
and when a trailing segment becomes empty, **release it** (with §3's IPC_RMID this
is just closing the handle). Prior art is deep and directly applicable:

- **RAMCloud log-structured memory + parallel cleaning** (Rumble/Ousterhout) — the
  canonical "reclaim free space in a log without stalling" design.
- **LFS** (Rosenblum & Ousterhout, 1991) — segment cleaning.
- **Redis `activedefrag`** — incremental, jitter-bounded defragmentation in a
  single-threaded engine (our exact constraint).
- **jemalloc `background_thread` + `madvise(MADV_DONTNEED)`** — return pages to the
  OS after purge; the FFI-gated `ftruncate`/`madvise` path (deferred per §9f) makes
  shrink page-granular, but **segment-drop already works with no FFI**.

---

## 5. The writer "materialize the entire store" trap

This is why shared **churn collapses**, and it compounds the leak.

Every shared **write** calls `syncSharedStateIfStale()` (`Fast.php:389`). If any
peer bumped the revision (`endPublish`, `Shared.php:444-446`), the next writer runs
`refreshLayoutState()` which reads and parses **the whole store** into PHP arrays:
directory + id table + order log + **the entire arena up to `arena_frontier`**
(`Shared.php:592-727`).

With a 15 GB high-water arena, a single writer after a peer mutation re-reads
**15 GB** before doing O(1) work. Under multi-writer churn this is quadratic-ish
and is the real collapse mechanism — not the per-op constant.

Reads were already fixed to be incremental (`probeSharedKey`, O(probe), `Shared.php:1858-1869`,
used by `Fast::sharedFetchTracked`, which does **not** call the stale-sync). Writers
must get the same treatment:

**Fix:** make writer refresh **incremental** — replay only records appended since
the last-seen revision (a log tail), or version per region/bucket so only changed
slots are re-read. Cite seqlock + per-bucket version (Linux seqcount per object),
RCU-style "read your own writes, replay others' deltas," and LMDB's MVCC where a
writer never re-reads the whole DB.

### ✅ RESOLVED (2026-06-28, Incremental Writer Refresh pass)

The writer "materialize the entire store" trap is fixed. Two clarifications the
fix established about the original framing:

1. **The trap is real in the multi-process peer-bump path, not in the
   single-process benchmark.** Direct measurement (`fullRefreshes` counter) shows
   a single-process update loop triggers **zero** full refreshes — the
   single-process update collapse seen in some harnesses (~351/s) is a *separate*
   allocator defect (free-list grows unbounded, `reused_allocations` stays 0; see
   §8/roadmap "slab/free-list"), explicitly **out of scope** for this pass. The
   genuine writer-refresh cliff appears when a *peer* bumps the revision: the next
   writer rebuilt the whole mirror. Measured before the fix: **~139,000 µs per
   write at 1,000 live entries, timing out (quadratic) by 10,000** as the arena
   high-water grew.

2. **The fix.** Writes (`storeValue`/`removeValue`) now call a cheap
   `Shared::syncWriterStateIfStale()` instead of the full `refreshLayoutState()`.
   On a peer revision bump it adopts only the fixed-size header scalars
   (geometry, counts, frontier, id/order cursors, order head/tail, free-list
   heads) and **invalidates the per-record caches**; the mutator then faults in
   only the directory/id/order/value slots it actually touches through
   read-through loaders (`layoutLoadDirectorySlot` / `layoutLoadIdSlot` /
   `layoutLoadOrderNode`) — the same narrow shared-memory probing the reader path
   already used (`probeSharedKey`). The arena is never reread up to
   `arena_frontier` on the write path. A new `incrementalRefreshes` counter
   (test-only, via `fast_test_refresh_counters`) and the
   `shared_writer_incremental_refresh` regression test assert `full_refreshes`
   stays **0** across peer-churn writes.

   Whole-store consumers (iteration, serialize) still take the full refresh, now
   guarded by a `layoutMirrorComplete` flag so they rebuild after a partial writer
   sync even when the revision already matches. A latent pre-existing iterator
   defect surfaced and was fixed in the same edit: `layoutAdvanceIteratorToLive`
   now **skips** an order node whose id no longer maps to a directory slot (an
   orphan left by delete+reinsert reusing the slot under a new id) instead of
   throwing; this also matches PHP array semantics (a reinserted key moves to the
   end of insertion order).

**Before / after (multi-process peer-bump write, this machine):**

| live entries | A-write/op before | A-write/op after | full_refresh/cycle after |
|--------------|-------------------|------------------|--------------------------|
| 1,000        | ~139,000 µs       | ~57 µs           | 0.00                     |
| 5,000        | ~158,000 µs       | ~63 µs           | 0.00                     |
| 10,000       | timed out         | ~69 µs           | 0.00                     |
| 20,000       | timed out         | ~84 µs           | 0.00                     |
| 40,000       | timed out         | ~109 µs          | 0.00                     |

(`Fast/test/shared_writer_refresh_bench.php`.) Standard single-process
`stress_100k_bench` is unchanged (insert ~123k/s, update same-size ~298k/s,
update larger ~98k/s, errors 0).

**Remaining writer debt:** `adoptScalarHeader` still calls
`allocatorRecountFreeList()` on every peer bump (walks the shared free-list);
cheap because the free list is now bounded (see §5b), so this is no longer a
collapse risk.

---

## 5b. The single-process update collapse — allocator reuse (✅ RESOLVED 2026-06-28)

### The defect

`§3`/`§5` proved the single-process update collapse (~351/s in harnesses) was
**not** a writer-refresh problem (`full_refreshes = 0` throughout). It was an
**allocator-reuse** defect, and the smoking gun was `reused_allocations = 0` with
`free_block_count` climbing without bound.

Mechanism, confirmed by reproduction (50k keys, value updated so its igbinary
size grows one byte once `i*2` crosses the 2-byte integer boundary):

```text
update 50000 ... => 369 ops/sec
reused=0 released=17360 free_block_count=17360 (climbs) larger=17360 appended=67360
```

1. Allocation was **exact** (`allocatorGrant` returned the precise byte count).
2. A value that grew by one byte was a **larger replace**: it allocated a new
   block and freed the old one.
3. The freed block was therefore **exactly one byte too small** for the next
   request, so first-fit (`cap >= need`) never matched it → `reused = 0`.
4. Every larger replace pushed one more never-reusable block, so the class chain
   grew unbounded, and `allocatorPopFirstFit` walked the **entire** growing chain
   on every allocation → O(N) per op → O(N²) total → collapse.

### The fix — size-class rounding + bounded scan

Least mechanism, no format change, no public surface change (`Shared.php`):

- **`allocatorGrant` rounds non-oversize requests up to the enclosing size class.**
  Every block in a class is now exactly that class's size, so (a) same-class reuse
  is O(1) — the head always fits, no walk — and (b) a value that grows/shrinks a
  few bytes but stays in its class keeps the same capacity, so the update is an
  **in-place overwrite** instead of free+allocate. The pathological monotonic-growth
  pattern becomes a stream of same-size overwrites: `free_block_count = 0`.
- **Updates that cross a class boundary realloc** (allocate-before-free): the
  vacated block re-enters the free list and is reused by the class it now belongs
  to, instead of being stranded oversized. This is what makes `reused_allocations`
  meaningful under class-crossing churn while keeping the free list bounded by the
  live set.
- **`allocatorPopFirstFit` is bounded** by `ALLOC_FREE_SCAN_LIMIT = 32`. Non-oversize
  classes never trip it (head always fits); it only caps the oversize chain so a
  long run of too-small oversize blocks can never become an O(N) scan.
- Oversize requests (> 65536) stay exact and use the bounded first-fit, as before.
- The stored `block_len` is now the granted **capacity** (≥ content); value blocks
  are self-describing (`Format::readLayoutValueBlockMeta` reads by header lengths),
  so a larger-capacity slice parses correctly and no reader changed.

### Before/after (same machine, 50k updates)

| metric | before | after |
|--------|--------|-------|
| update (collapse pattern) | **369 ops/sec** | **~58,600 ops/sec** (~159×) |
| `reused_allocations` (collapse pattern) | 0 | 0 (now all in-place same-class) |
| `free_block_count` (collapse pattern) | 17,360 (climbing) | **0** |
| class-crossing churn (`reinsert`) reuse | n/a | **100%** (`appended` flat after warmup) |
| mixed write-heavy `free_block_count` | unbounded | **bounded = live set** |

Proof: `Fast/test/shared_allocator_update_reuse.php` (7 cases — same-size,
larger+reuse, delete/reinsert reuse, class-crossing churn reuse + bounded free
list, traversal-bound, peer-after-churn, order integrity) and
`Fast/test/shared_allocator_update_bench.php`.

**Remaining allocator debt:** shrink-to-a-smaller-class reallocs (one free + one
alloc) rather than splitting in place — fine for reuse and boundedness, but it does
not lower the frontier. Sub-`ALLOC_MIN_BLOCK` split remainders are still dropped
(§4). The frontier is still a monotonic high-water mark — that is the **shrink**
problem (slab + bounded compaction + segment drop, §4/study §9b), deliberately out
of scope for this reuse pass.

---

## 6. Per-op publish cost (~22 µs vs ~7 µs local)

Each shared mutation flips the seqlock by **rewriting the full 176-byte header
twice** (`beginPublish`/`endPublish`, `Shared.php:414-446`), and an insert writes
header-scale data **three** times (`Shared.php:1722-1856`), plus a `sem_acquire`/
`sem_release` (`Shared.php:358-398`) and 2–3 targeted region writes.

A seqlock only needs the **sequence counter** to flip — 8 bytes, not 176. Rewriting
all 13 free-list heads + geometry on every begin/end is pure overhead.

**Fix:** write only the 8-byte sequence word for begin/end; persist geometry/free
heads only when they actually change. Expect a meaningful cut in the ~22 µs.

---

## 7. Priority roadmap (impact × risk, stability preserved throughout)

| # | Change | Why | Risk | Touches format? |
|---|---|---|---|---|
| 1 | ~~**Growth-segment reclaim**~~ ✅ **DONE (2026-06-28)**: full-keyspace enumeration on reclaim + PID table (layout v2) + reopen-reaper; `IPC_RMID`-at-create rejected (breaks attach-by-key) — see §3 RESOLVED | Stopped the 15 GB leak; crash-safe | **Low** | layout v2 (176→512 B) |
| 2 | ~~**Incremental writer refresh**~~ ✅ **DONE (2026-06-28)**: writer path adopts header scalars + read-through slot loaders instead of rereading the arena; `full_refreshes` stays 0 under peer churn; ~139,000 µs → ~57 µs per peer-bump write at 1k live — see §5 RESOLVED | Kills churn collapse; the real shared bottleneck | Med | no |
| 2b | ~~**Allocator reuse / single-process update collapse**~~ ✅ **DONE (2026-06-28)**: size-class rounding (in-place same-class updates, O(1) reuse) + class-crossing realloc + bounded free-list scan; ~369/s → ~58,600/s (~159×), `free_block_count` bounded by live set — see §5b RESOLVED | Kills the single-process update collapse; freed blocks actually reused | Low–Med | no |
| 3 | **Seqlock writes 8 B not 176 B** (§6) | Trims ~22 µs/op | Low | no |
| 4 | **Format diet**: pack record header (§1a), bucket-fp directory (§1b/§9c), drop linked-list order (§1c), 32-bit offsets, varint small ints | 3–5× footprint, cache locality, throughput | **High** (on-wire change) | yes — gated by full test suite |
| 5 | **Slab + incremental compaction + segment drop** (§4/§9b) | Actually shrinks; returns memory | Med-High | arena layout |
| 6 | **Right-size initial + geometric growth** (§2) | Empty/small stores stop reserving 16 MB | Low-Med | no |
| 7 | **Growable directory** (linear/extendible hashing or incremental rehash, §2c/§9d) | Capacity tracks live count; no over-provision | High | yes |

**Sequencing rationale:** 1–3 are low-risk stability/throughput wins that need no
format change — do them first and the shared engine stops leaking and stops
collapsing. 4 is the big footprint/throughput lever but it is an on-wire change, so
it lands behind the full gate as a deliberate format version bump. 5–7 finish the
"free memory we don't use" mandate.

Every item above keeps the seqlock/lock/publish-last invariants (stability), the
append-then-publish ordering (no data loss), the `ArrayAccess` face (ease of use),
and cross-process visibility (multi-process). Nothing here trades a non-negotiable
for speed.

---

## Appendix — corroborating line references

- Format constants: `Fast/Format.php:20-28`.
- Record header pack/unpack: `Fast/Format.php:30-82`.
- Directory slot / order node / int-key encoding: `Fast/Format.php:263-271, 159-177`.
- Segment size + "cheap empty store" comment: `Fast/Shared.php:28-34`.
- Growth = full-size segment per step: `Fast/Shared.php:2358-2374`.
- No shrink / monotonic frontier: `Fast/Shared.php:969-975, 2020-2062, 2350`.
- Size classes / min block / fragment leak: `Fast/Shared.php:61-74, 2392-2397`.
- Per-op seqlock full-header writes: `Fast/Shared.php:414-446, 1722-1856`.
- Writer full-store refresh: `Fast/Shared.php:528-542, 592-727`; reads incremental: `1858-1869`.
- Reclaim only of open handles / no IPC_RMID-at-create / no segment count: `Fast/Shared.php:996-1000, 1081-1103`.
- Directory fixed, cannot grow: `Fast/Shared.php:1358-1362, 1868-1869`.
- Prior decisions: `docs/design-study.md` §9b (slab+compaction), §9c (bucket-fp), §9d (incremental rehash), §9e (crash reclaim), §9f (no-FFI baseline), §9g (local index + lazy mirror).
