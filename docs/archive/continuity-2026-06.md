# Fast — Continuity handoff

> **Archived.** June 2026 snapshot. Describes `Shared.php` / `Journal.php`, not the current `Flat` engine. See [`../engine-architecture.md`](../engine-architecture.md).

**Date:** 2026-06-29 (updated for standalone repo)
**Scope:** `src/Fast.php`, `src/Fast/` engine.
**Purpose:** self-contained handoff so a fresh agent (or a later session) can resume
engineering work cold, without re-discovering anything.

---

## 0. Mission

`\Fast` is an array-like shared-memory store for PHP. The public contract is
`docs/specification.md`. Validation is `php tests/run.php` (full gate) and, for
perf tracking, `php tests/stress_100k_bench.php` + the pinned baseline at
`research/baselines/stress-100k-baseline.json`.

### Pinned stress baseline (100k workload, PHP 8.1.2 / Linux capture)

See `research/baselines/stress-100k-baseline.json` for per-phase tx/sec. Integrity
requirements: records lost / orphans / errors / warnings all `0`.

---

## 1. Historical note (2026-06-29 session snapshot)

The table below is a **time-stamped engineering snapshot** from mid-rewrite — not
current standings. It is kept only as context for what changed in that session.

| metric | at session start | after session |
|---|---|---|
| TOTAL tx/sec | 43,383 | 53,854 (+24%) |
| php peak mem | 881 MB | 147 MB (6× reduction) |

The remaining gap at that time was **structural** (see §5). Re-measure with
`php tests/stress_100k_bench.php` for current numbers.

---

## 2. What Fast IS — architecture (current state)

- **Files:** `Fast.php` (1176 L, public facade) · `Fast/Shared.php` (~3200 L, the
  shared-memory engine — the hot code) · `Fast/Journal.php` (1362 L, the LOCAL/
  non-shared engine) · `Fast/Format.php` (414 L, binary codecs + header layout).
- **Two modes:** *shared* mode (named store, lives in SysV shmop — what the bench/
  product uses) and *local* mode (no name → `Journal`, PHP-array store). **They are
  separate engines.** In shared mode the `Journal` is constructed but unused except
  for a wasteful 52 MB directory preallocation (see §6).
- **On-segment layout (shared mode):** primary segment 0 has a fixed 512-byte
  header, then fixed regions: **directory** (open-addressing linear-probe hash,
  40-byte slots) → **id table** (32-byte slots: `id → block_off/len/state/
  generation`) → **order nodes** (24-byte singly-linked insertion-order list) →
  **value arena** (size-class slab allocator with free lists). Growth segments 1…N
  hold more arena.
- **Triple indirection per read:** directory slot (match key_hash) → id slot (block
  ptr + generation) → value block (32-byte self-describing header + key bytes +
  igbinary value bytes).
- **Header field offsets** (`Format::writeSharedHeader`/`readSharedHeader`): magic@0,
  version@4, layout@8, **revision@12 (V/4B)**, **sequence@16 (V/4B)**, lifecycle@20,
  … then geometry, counts, 13 free-list heads, header_bytes.
  `SHARED_HEADER_BYTES_LAYOUT = 512`, layout version 2 (carries durable PID table for
  crash reclaim).
- **Concurrency model:** ONE writer semaphore; lock-free reads validated by a
  **seqlock** (odd sequence = write in progress; read seq before+after, accept only
  if even+equal). **x86/TSO only** — no memory barriers exist in PHP/shmop
  (documented caveat). Publish-last ordering + CRC/length guard against torn reads.
  Crash safety = fail-closed.
- **Reads (`Fast::sharedFetchTracked`, 3 tiers):** (1) cache-current fast path —
  only when `layoutMirrorComplete && cached seq == shared seq`; (2) lock-free narrow
  probe (`Shared::probeSharedKey`, O(probe distance), reads shmop directly);
  (3) locked narrow probe under contention. `count()` reads only the header live
  field. `foreach` takes one lock at `rewind()` then snapshots.
- **Codec:** igbinary is **mandatory** (no serialize fallback). Scalars have fast
  paths (int via zigzag, bool, float, string raw). ZigZag decode uses
  `(($v>>1)&PHP_INT_MAX)^(-($v&1))` (logical-shift fix for PHP_INT_MAX).

---

## 3. What the 2026-06-29 session changed

All 4 changes are in `Fast/Shared.php` and were verified by the **full gate (92
tests + 180s parallel stress, exit 0)**.

1. **Bounded the PHP-heap store mirror (memory: 881 MB → 147 MB).** Root cause of the
   memory blowup: in shared mode Fast kept an *unbounded* read-through/write-through
   mirror of the whole store in PHP arrays (`layoutValueBlocksData` = 215 MB of value
   bytes alone, plus `layoutDirectory/Id/OrderSlotsData`). Shmop is the source of
   truth (every `layoutLoad*` faults from shmop on miss). Added
   `const LAYOUT_CACHE_SOFT_LIMIT = 2048` and `trimRecordCachesIfOverBudget()` which
   calls the **existing** safe primitive `invalidateRecordCaches()` at the top of
   `layoutSet`/`layoutDelete` (under the writer lock, before any slot is loaded).
   - **Side effect / landmine:** `invalidateRecordCaches()` sets
     `layoutMirrorComplete = false`, which **disables the tier-1 warm read fast
     path**, pushing reads to the lock-free probe. Memory/read-speed tension.

2. **Lightweight seqlock publish (TOTAL +21%, update same-size +55%).** Every write
   previously rebuilt+repacked the whole ~38-field 512-byte header **twice per op**
   (`beginPublish`+`endPublish`). Added `beginPublishSeq()` (writes only the 4-byte
   sequence@16 to open the window) and `commitPublishSeq()` (writes only revision@12
   + sequence@16 = 8 contiguous bytes). Added
   `const SHARED_HEADER_REVISION_OFFSET = 12`. Inserts/deletes/reallocs still use the
   full `endPublish`/`layoutWriteHeader` at commit (counts/frontier/free-heads
   change) but open the window with `beginPublishSeq`. **Note:** `beginPublishSeq`
   advances `sharedSequence` by 1, so the insert commit's `layoutWriteHeader`
   sequence was changed from `sharedSequence + 2` to `+ 1`. This implements roadmap
   item #3 of the efficiency audit (§6 of that doc).

3. **Same-size in-place update: 5 shmop writes → 3.** On a same-size overwrite, only
   value bytes change. The code now skips the id-slot AND directory-slot rewrites and
   does **not** bump generation — the reader's `idSlot.generation ===
   dirSlot.generation` consistency check still holds (both unchanged), and
   cross-process visibility rides on the `commitPublishSeq` revision bump + seqlock.

4. **Insert: one directory probe instead of two.** Added `&$insertIndex` out-param to
   `layoutFindSlotNormalized()` (captures first tombstone, else the terminating empty
   slot during the presence walk); the insert path reuses it.
   **Removed `layoutFindInsertSlot()`** (now unreachable).

---

## 4. The diagnosis (why Fast is slow/fat — measured, not guessed)

- **Memory:** was a full PHP-heap duplicate of the store. Now bounded to 147 MB. The
  residual 147 MB is: working-set caches (bounded) + **52 MB Journal directory
  preallocated but unused in shared mode** (`Journal::initializeRuntime` loops
  `directorySlots` entries → 41.6 MB `directorySlotsData` + ~10 MB `directoryBinary`)
  + the bench's own ~256 MB segment (that is shmop, not PHP heap).
- **Speed (write amplification, measured via counters):** insert = ~7 shmop
  writes/op (value + 2 order nodes + id slot + dir slot + full header + window-open);
  same-size update now 3. The remaining per-op cost is dominated by **igbinary
  encode/decode, the no-op read (reads full current block to compare bytes), the
  directory probe, and triple indirection** — not the small slot writes (proven:
  removing 2 slot writes barely moved same-size update).
- **Read (2.5× slower):** per get = ~3 `readSharedSequence` (shmop_read+unpack each)
  + `probeSharedKey` (dir slot read+unpack → id slot read+unpack → value block
  read+unpack + 2 substr + decode). Structural overhead on the read path.

---

## 5. THE PATH TO BETTER PERFORMANCE — structural, not micro-opt

Micro-optimization is at diminishing returns. The residual ~2.2× gap is structural.
The authoritative roadmap is **`docs/archive/efficiency-audit.md` §7** (priority
table). Status of that roadmap:

- ✅ #1 Growth-segment reclaim leak (15 GB → fixed; PID table, layout v2, reopen-reaper)
- ✅ #2 Incremental writer refresh (killed the peer-bump churn collapse; `full_refreshes` stays 0)
- ✅ #2b Allocator reuse / single-process update collapse (size-class rounding; 369/s → 58,600/s)
- ✅ **#3 Seqlock writes 8B not 176B — DONE 2026-06-29** (`beginPublishSeq`/`commitPublishSeq`)
- ⏭️ **#4 FORMAT DIET (the big lever, HIGH risk, on-wire change → version bump):**
  pack the 32-byte record/value header to ~3–8 B (status byte + varint lengths);
  **bucket-fp directory** (8 entries / 64-byte cache line + 1-byte fingerprint tags —
  already chosen in design study §9c, ~5× less directory memory, flat probe tails);
  **drop the singly-linked order list** (24 B/entry, cache-hostile) in favor of
  arena-append order or a packed 32-bit offset array; 32-bit (40-bit) arena offsets;
  varint small int keys. Projected: per-entry overhead ~160 B → ~30–50 B (3–5×
  footprint + big cache-locality/throughput win). **Highest-leverage remaining work.**
- ⏭️ #5 Slab + bounded incremental compaction + trailing segment drop (give memory
  back; the frontier is currently a monotonic high-water mark — Fast never shrinks).
  Prior art: RAMCloud log cleaning, LFS, Redis activedefrag.
- ⏭️ #6 Right-size initial segment + geometric growth (empty store reserves 16 MB floor today).
- ⏭️ #7 Growable directory (linear/extendible hashing or incremental rehash; today
  the directory cannot grow and must be over-provisioned at `new Fast()`).

**Recommendation:** the read-path and update-path gaps are dominated by indirection +
per-entry byte tax, so **#4 (format diet, esp. bucket-fp directory + packed headers +
killing the order linked-list)** is the change most likely to close the gap on
insert/read/update simultaneously. It is an on-wire format change → bump the layout
version, land behind the full gate.

---

## 6. Contained wins still on the table (lower risk, won't fully close the gap)

- **Reclaim the 52 MB Journal preallocation in shared mode** (→ ~95 MB). Tried and
  DEFERRED on 2026-06-29: making `Journal::directorySlotsData` lazy/sparse risks the
  local-mode binary-mirror persistence path (`readDirectorySlotBinary`,
  `buildDirectoryLogLocal`), and making `Fast::$journal` nullable breaks the test
  fixture `fast_test_journal(): Journal` (typed non-null, read via reflection on the
  `journal` property). A safe version: lazily construct the Journal only on first
  non-shared use AND make the fixture tolerate/trigger it. Validate the full gate.
- **Read path:** when `layoutMirrorComplete === false`, skip the wasted tier-1
  `readSharedSequence` (reorder the check before the seq read). Tiny.
- **No-op check cost:** on update it reads the *entire* current block to compare
  bytes; for large values that is a full read per update just to detect no-ops.
  Consider comparing only value_bytes length+hash, or skipping when the caller can't
  be a no-op.

---

## 7. INVARIANTS THAT MUST NOT BREAK (the four non-negotiables)

From `docs/archive/efficiency-audit.md` and `docs/design-law.md`:

1. **Stability** — seqlock + single-writer lock + publish-last ordering; no torn
   reads. (x86/TSO-only is the accepted caveat.)
2. **No data loss** — append-then-publish; crash-safe to current standard
   (fail-closed on corruption; non-persistent reclaim on last detach; persistent
   survives, destroy only when sole owner / linkcount == 1).
3. **Ease of use** — public face is `ArrayAccess` + magic (`__get`/`__set`/
   `__isset`/`__unset`) + `foreach` + `count` + `each` (named callable only) +
   construction + `close`/`destroy`. `stats()`, `compact()`, and `detach()` are
   **NOT** public contract (test-only via reflection; `compact()` is internal
   maintenance, the store maintains itself). The exact allow-list is enforced by
   `Fast/test/public_surface.php` and specified in `specification.md` §3.
   Don't leak engine/codec/allocator methods onto the facade.
4. **Multi-process** — cross-process visibility stays correct.

Other locked contracts: igbinary mandatory; non-persistent by default (`persistent`
opt-in honored config key; only `name`/`capacity`/`size`/`persistent` accepted, all
else throws); stored `null` reads back as `null` but `isset()` is `false`; missing
key reads `null` with no warning; directory cannot grow (capacity frozen at
construction); link counter counts connected OS processes, not handles. **Do NOT
re-attempt lock elision via link count** (proven unsound in pure PHP — Dekker/
StoreLoad hazard).

---

## 8. All documents & research (read these first when resuming)

**Docs (`docs/`):**

- `archive/efficiency-audit.md` — **START HERE.** The byte/syscall audit + the
  §7 priority roadmap. Most actionable. (Item #3 now done.)
- `archive/structural-audit.md` — structural audit (method counts, facade bloat, phased
  plan A–E).
- `design-study.md` — design study; **§9 decisions**: §9b slab+
  compaction, §9c **bucket-fp directory** (8 entries/64 B line, 1-byte fingerprint),
  §9d incremental rehash, §9e crash reclaim, §9f no-FFI baseline, §9g local index +
  lazy mirror.
- `specification.md` — authoritative behavioral contract (14 sections; public
  surface, lifecycle, stats-not-contract, each() semantics).
- `design-law.md` — the design law (crash safety §5, memory law, etc.).
- `archive/rewrite-plan.md` — the original 8-phase rewrite plan + validation gates +
  benchmark policy.
- `guiding-principles.md` — guiding principles.
- `archive/continuity-2026-06.md` — **this document.**

**Research harnesses (`Fast/research/`):**

- `baseline.php` — sizes segments as `(valueBytes + 160) * n` (the 160 B/entry
  overhead budget).
- `exp1_allocator.php`, `exp2_index.php` (bucket-fp vs open-linear-40 sim),
  `exp3_rehash.php`, `exp4_crash.php` (real `kill -9` seqlock + nattch validation),
  `ffi_shm_spike.php`.

---

## 9. Tests & how to validate (REQUIRED after every engine change)

- **Full gate:** `php Fast/test/run.php` — 92 tests, must exit 0. Includes the
  **180s `mp_parallel_stress`** (the concurrency guard — non-negotiable for any
  seqlock/generation/publish change).
- **Stress benchmark (the scoreboard):** `php tests/stress_100k_bench.php` —
  canonical stress benchmark. Reports per-phase tx/sec + peak memory
  + records-lost/orphans/errors/warnings (all must be 0).
- **Quick concurrency sanity subset:** `fork_new_key_visibility`,
  `fork_stale_positive_cache`, `fork_unset_stale_cache`, `o1_count_fork`,
  `fork_alloc`, `read_stale_coherence`, `shared_update_no_torn_reads`,
  `shared_allocator_parallel_churn`.
- **Test-only introspection** via `Fast/test/fixtures/engine_access.php`:
  `fast_test_journal()`, `fast_test_stats()`, `fast_test_refresh_counters()` (reads
  counters without triggering a sync), `fast_test_open_existing()`,
  `fast_test_compact()`. Engine counters of interest: `valueBlockWrites/Patches`,
  `idSlotWrites`, `directorySlotPatches`, `orderNodePatches`, `headerPatches`,
  `directInsertPublishes`, `fullRefreshes` (must stay 0 under peer churn),
  `incrementalRefreshes`, `allocatorReusedAllocs/ReleasedBlocks/SameSizeOverwrites`,
  `allocatorFreeBlockCount` (bounded by live set).
- **Benchmark policy:** accept a phase only if correctness stays green, throughput
  does not regress materially, and the structure is measurably better or clearly
  safer.

---

## 10. Landmines / gotchas (learned the hard way)

- **Arena offsets are recycled** by the allocator → a value-block cache that stops
  being written but is still read produces stale-offset wrong-value reads. The
  current bounded-cache trim avoids this by *wholesale clear* (never partial-stop),
  so write-through stays coherent between clears.
- **Generation is a dir↔id consistency cross-check**, read fresh on every probe (not
  a value-version compared against a cached gen). Not bumping it on a same-size
  in-place update is safe *because both stay equal*; bumping only one side would make
  readers treat the key as absent.
- **`layoutMirrorComplete = false`** (set by any cache invalidation/trim) disables
  the tier-1 warm read path — expected, but means read-heavy workloads pay the probe.
  A bounded-cache-friendly tier-1 (miss falls through to probe instead of being
  authoritative) would help but needs care.
- **`shmop_open` probes use `@`** intentionally (expected failures on not-yet-created
  segments). Test error handlers must respect `error_reporting()` or they
  false-positive on suppressed warnings (a prior bench-porting bug).
- **Null read-back:** never test a stored null with `?? 'sentinel'` (the `??` returns
  the default for a null LHS). Read directly.
- `tests/` is self-contained. Do not add external store dependencies there.

---

## 11. Memory continuity (Pluribus)

This work is recorded in Pluribus. On resume, run `recall_context` with a task
description mentioning "phprax Fast stress baseline" to pull: the
bounded-mirror + seqlock pass (ids ~`9ff88256` / `034c6982`), the allocator-reuse
fix, the incremental-writer-refresh fix, the P2 read-path pass (`probeSharedKey`),
and the spec/lifecycle constraints. Record new outcomes with `record_experience`.

---

## 12. First moves when resuming

1. `recall_context` (Pluribus) + read `docs/archive/efficiency-audit.md` §7 and
   `docs/design-study.md` §9c.
2. Re-run `php tests/stress_100k_bench.php` to confirm current numbers against
   `research/baselines/stress-100k-baseline.json`.
3. Decide direction: **(A) format diet / bucket-fp directory v2** (recommended),
   **(B) reclaim the 52 MB Journal + read-path tweaks** (contained), or **(C) slab
   compaction + segment shrink** (memory). Format diet is an on-wire change → version
   bump → land behind the full gate.
4. Whatever you touch: keep the four non-negotiables and run the full gate (incl.
   180s parallel stress) before declaring victory.
