# Fast Design Study — Clean-Room Evaluation (2026-06)

Status: research companion to `docs/specification.md`. Not a contract.

This is a **clean-room evaluation**: assume the code does not exist and that the
only description of the system is how the tests use the class. From that blank
slate, what does the research in filesystems, memory management, caches, and
database engines tell us to build — and, just as important, what does it tell us
*not* to build once filtered through **PHP 8.1+** and the priorities?

Priorities, in order: **ease of use, performance, stability.** The justification
rule applies here too — a technique earns a place only if it serves those. Most
of the famous machinery does not, and this study says so explicitly.

How to read each section: **the question → the canon to study → what to steal →
what to reject (and why, usually PHP) → the PHP-shaped decision → the experiment
that would prove it.**

---

## 0. The binding constraint: the PHP 8.1+ execution model

Every design choice below is filtered through this first. PHP is not C, and most
of the cited systems are C/C++ with threads and hardware atomics. The single
most important act of this study is to separate the *ideas* in that literature
from the *concurrency scaffolding* PHP cannot run.

### 0.1 What PHP 8.1+ cannot do

| Constraint | Reality in PHP 8.1+ | Consequence for Fast |
|---|---|---|
| **No threads in a request** | Standard PHP is process-per-request/per-attach. No `std::thread`, no thread pool. (`Fiber` is cooperative, not parallel; `ext-parallel`/Swoole are non-standard.) | **No background cleaner, no async resize, no background reclamation.** All maintenance must run in the foreground of the writer, amortized. |
| **No userspace atomics / CAS / fences** | No `compare_exchange`, no `atomic<T>`, no memory barriers exposed to PHP. | **No lock-free / latch-free structures.** Concurrency must be a single writer under a semaphore + a sequence counter readers validate against. |
| **`shmop` is SysV shared memory** | `shmop_open()` → `shmget`/`shmat` (System V). Segments are **fixed-size at creation** and **cannot be resized**. `SHMMAX` bounds a segment. Keys are `ftok`-style ints. | **"Grow/shrink" cannot mean resizing one segment.** Grow = allocate a new (larger) segment and migrate, or manage free space inside a pre-sized arena. This is the hardest limitation. |
| **Reads are copies** | `shmop_read()` returns a **new PHP string** (a `memcpy` out of the mapping). There is no pointer/`mmap` view into the segment from PHP land. | **No zero-copy reads.** Every read pays a copy + a decode. Layout should minimize *how many bytes* must be copied to answer a query (metadata before payload). |
| **No attach-count visibility** | `shmop` does not expose `shm_nattch`. `shmctl(IPC_STAT)` is reachable only via FFI. | The kernel *has* a crash-safe attach count, but PHP cannot read it without FFI. A self-maintained link counter is therefore needed — and must be validated/repaired on attach (fail-closed), because `SIGKILL` leaves it stale. |
| **Manual alignment** | Data is assembled with `pack()`/`unpack()` into byte strings written at chosen offsets. No struct alignment, no guaranteed cache-line placement. | Cache-line bucket layouts (RAMCloud/F14) are *advisory* — we choose offsets by hand and cannot rely on the CPU treating them as aligned the way C would. |
| **igbinary is optional** | `igbinary` is a PECL extension, not bundled with PHP. `serialize()` is the only always-present codec. | The spec mandates igbinary; the study must treat **codec availability as a runtime capability check**, detected once per process. |
| **Signals are limited** | `pcntl` signal handling is CLI-mostly; under FPM/Apache it is unreliable. Clean shutdown rides on object destructors and `register_shutdown_function`. | Graceful detach works on normal shutdown; **`SIGKILL`/fatal bypasses it**. Correctness cannot depend on the destructor running — reinforcing kernel-style refcount semantics + attach-time validation. |
| **64-bit assumptions** | 64-bit ints only on 64-bit builds; `pack('P'/'Q')` for 64-bit. | Offsets/lengths in headers are 64-bit; document the 64-bit-build assumption rather than defend 32-bit. |

### 0.2 What PHP 8.1+ *does* give us

- **`Fast` can be a real object, not a facade** — `readonly` props, enums,
  first-class callable syntax, `never`. The language is expressive enough that
  indirection layers must justify themselves (see the audit).
- **`ArrayAccess`, `Iterator`, `Countable`** make "behaves like an array" a true
  interface, not a metaphor. The public surface is these, plus magic accessors.
- **FFI (`ext-ffi`, stable since 7.4)** is the escape hatch: it can call
  `mmap`/`ftruncate` (POSIX shm → *resizable* segments), `shmctl(IPC_STAT)` (read
  the kernel attach count), and even pthread/atomic primitives. **This is the one
  lever that could lift several constraints above** — at the cost of a non-default
  extension and C-level risk. The study flags every place FFI would change the
  answer; adopting it is a separate, deliberate decision.

> **Spike result (2026-06-27, PHP 8.1.2, Linux x86-64, glibc).** Both gating FFI
> capabilities were proven on this environment (`research/spikes/ffi-shm/run.php`):
> **(A)** POSIX `shm_open` + `ftruncate` + `mmap` + remap grows a segment
> 4096→8192 with data surviving and the new tail writable — **dynamic resize is
> liftable** (§4). **(B)** `shmget` + `shmctl(IPC_STAT)` reads `shm_nattch` (=1)
> and `shm_segsz` (=4096) off a `shmop`-created SysV segment with the documented
> glibc struct layout — **crash-safe attach count is readable** (§1). Gotcha for
> implementers: get a pointer's address via `cast('intptr_t', $ptr)->cdata`;
> casting to `unsigned long` silently yields 0 (and `mmap` failure is `-1`, not
> `0`). Conclusion: **FFI/POSIX-shm can resolve both hard constraints on Linux.**

### 0.3 The filter, stated once

> Read the literature for **layouts, encodings, and failure modes**. Discard the
> **threads, atomics, and background workers**. What remains is a single-writer,
> foreground-maintained, copy-on-read store — and that is dramatically *smaller*
> than the papers, because most of their code fights concurrency problems PHP's
> model does not have.

---

## 1. Lifecycle & refcounting

**Question.** Non-persistent by default (last process out reclaims), persistent
opt-in, `destroy()` only at link-count == 1, crash must not wedge the segment.

**Canon.** SysV shm semantics (`shmget`/`shmat`/`shmdt`/`shmctl(IPC_RMID)` +
`shm_nattch`); POSIX shm (`shm_open`/`shm_unlink` + `mmap` refcount); UNIX
unlink-while-open (inode `nlink` + open-fd count) — Stevens & Rago, *APUE*.

**What to steal.** The semantics are *exactly* the contract: the kernel keeps a
crash-safe attach count and frees the segment when it reaches zero
(`IPC_RMID`-marked). Non-persistent = mark-for-removal so last-detach reclaims;
persistent = survive; `destroy()` = `IPC_RMID` gated on attach-count == 1.

**What to reject / PHP reality.** PHP's `shmop` **does not expose `shm_nattch`**
and does not let you set `IPC_RMID`-on-create cleanly. So either: (a) use FFI to
drive `shmctl` and lean on the kernel count (crash-safe, best), or (b) maintain
the link counter in the segment ourselves — which is stale after `SIGKILL` and
must be validated/repaired on attach.

**PHP-shaped decision.** Self-maintained link counter **plus** an attach-time
validation/repair pass (heartbeat or pid-liveness check) so a crashed holder's
slot can be reclaimed — the fail-closed rule. Revisit (a) if FFI is on the table:
it makes the whole problem disappear and is the strongest argument for FFI.

**Experiment.** `kill -9` a holder mid-write; confirm a non-persistent store is
still reclaimable by the next attach and a persistent one survives, with no
permanently-stuck link count.

---

## 2. Reader / writer concurrency

**Question.** Many readers, one writer, reads never torn, readers validate and
retry.

**Canon.** Linux **seqlocks**; Lamport, *Concurrent Reading and Writing* (CACM
1977) — the correctness proof for version-counter retry. For contrast: RCU
(McKenney), epoch-based reclamation (Fraser 2004), FASTER's epoch framework
(SIGMOD'18).

**What to steal.** The seqlock: writer bumps an odd sequence, writes, bumps to
even; readers snapshot the sequence, read, re-check, retry on change. This is the
honest model and matches the contract precisely.

**What to reject / PHP reality.** RCU / epochs / hazard pointers solve *safe
reclamation under lock-free concurrent readers* — a problem that **requires
threads and atomics PHP lacks**, and that Fast structurally avoids because
readers **copy out** under validation and never retain pointers into the segment
(reads are copies anyway — §0.1). So the hardest problem in the concurrency
literature is a non-problem here. Do not import it.

**PHP-shaped decision.** Single writer via `sysvsem`; readers use a
sequence-counter seqlock with bounded retry. No epochs, no RCU, no hazard
pointers.

**Experiment.** Hammer a value with one writer + N reader processes; assert zero
torn reads and bounded retry counts under churn.

---

## 3. The index (key → location)

**Question.** Fast hash lookup, cheap `isset`/existence, grow and shrink without
stalls.

**Canon.** RAMCloud hash table (64-byte buckets, 8 entries, 48-bit ptr + 16-bit
tag); **MemC3 / libcuckoo** optimistic cuckoo + fingerprints (NSDI'13 /
EuroSys'14); **CCEH** (FAST'19) and **Dash** (VLDB'20) extendible hashing;
**Clevel** (ATC'20) background resize; Swiss tables / Folly **F14** tag arrays;
**Redis `dict`** incremental rehashing; linear hashing (Litwin).

**What to steal.** Cache-line bucket shape + **fingerprint tags** so most probes
and `isset` checks never touch the key bytes (cheap existence = fewer copies,
which matters doubly in PHP — §0.1). For grow/shrink, **Redis-style incremental
rehash**: keep two tables, migrate a few buckets per operation.

**What to reject / PHP reality.** Cuckoo's concurrent-writer machinery (we have
one writer; its value is multi-writer throughput we can't use). Clevel's whole
contribution is **background** async resize — **impossible without threads**
(§0.1). CCEH/Dash directory-doubling under a global lock and PMem tuning are
baggage. SIMD tag scanning (F14/Swiss) is unavailable from PHP; only the *layout*
transfers, and "cache-line alignment" is advisory (§0.1).

**PHP-shaped decision.** Cache-line bucket + fingerprint layout, grown/shrunk by
**foreground incremental rehash** (Redis model) — no threads, no stop-the-world,
bounded per-op work.

**Experiment.** Read-hit / read-miss / `isset` microbench against the current
directory; rehash 1e3→1e6 with concurrent readers and confirm no read stalls and
bounded per-op migration cost. **Result in §9c:** bucket-fp confirmed — 5× less
directory memory and flat tails where open-linear-40 blows up at load (rehash =
Experiment 3).

---

## 4. Value storage, allocation, and the shrink problem

**Question.** Store values compactly; grow to ~5M entries; **shrink** so freed
space returns and there is no permanent high-water-mark — all with no background
thread and no resizable segment (§0.1).

**Canon.** Wilson/Johnstone/Neely/Boles, *Dynamic Storage Allocation: A Survey*
(1995) and Johnstone & Wilson, *The Memory Fragmentation Problem: Solved?*
(ISMM'98); **Bonwick slab allocator** (USENIX 1994) and **memcached slab
classes**; **RAMCloud log-structured memory** + two-level cleaning (FAST'14);
**FASTER HybridLog** in-place-vs-copy (SIGMOD'18); jemalloc/tcmalloc size classes
and decay/purge; Redis `activedefrag`.

**What to steal.** Size-class segregated allocation (O(1), no in-class external
fragmentation) for the common path; FASTER's insight that **same-size updates go
in-place** (only size changes pay relocation); RAMCloud's evidence that **copying
compaction beats free-list fragmentation at high utilization**; Redis's
*amortized, incremental, foreground* maintenance philosophy.

**What to reject / PHP reality.** RAMCloud's **parallel background cleaner** —
**no threads** (§0.1); compaction must be foreground and incremental. memcached's
**slab calcification** (memory trapped in the wrong size class when value sizes
shift) is the cautionary tale that pure slab breaks "shrink." And the segment
itself **cannot be resized** (SysV, §0.1): growth means *new segment + migrate*
(or a pre-sized arena), and shrink means *compact within the arena* and only
release space by creating a smaller segment and copying — unless FFI/POSIX
`ftruncate` is adopted, which is the second strong argument for FFI.

**PHP-shaped decision (now evidence-backed — see §9b).** Size-class allocator +
in-place same-size updates + a **bounded incremental foreground compaction**
triggered by a fragmentation watermark. Experiment 1 showed this matches
log-structuring's best utilization (~0.83) while shrinking well and copying ~15×
less; log-structuring's high utilization costs ~5× write amplification that PHP
**cannot** background away (no cleaner threads, §0.1). Grow via new-segment
migration or FFI/POSIX `ftruncate`; shrink via compaction + trailing release.
This was the **least-solved part of the current code and the highest-value dive.**

**Experiment.** Allocator bake-off on a 50→5M insert/delete/replace churn:
size-class slab + foreground compaction **vs** log-structured arena + incremental
foreground cleaning. Measure live-data utilization (RAMCloud's 80–90% bar) and
writer tail latency when compaction fires.

---

## 5. Ordering & iteration

**Question.** Insertion-ordered `foreach`; snapshot-ish (never torn; concurrent
writes may or may not appear).

**Canon.** Reed's MVCC (1978); Bernstein & Goodman concurrency-control survey
(1981); Masstree (EuroSys'12) and Bw-tree (ICDE'13) for ordered structures.

**What to steal.** Snapshot-isolation's *guarantee*: a reader pinned to a version
sees a consistent cut. Implement it as an **insertion-order chain read under a
start-of-loop seqlock snapshot**, copying each value out under validation.

**What to reject / PHP reality.** Full multi-versioning (keep old copies) is too
heavy for a store meant to start tiny — MVCC's *machinery* buys nothing the
contract asks for. Trees (Masstree/Bw-tree) earn nothing while only insertion
order is required (justification rule); revisit only if range scans become a
contract.

**Experiment.** Iterate while a writer mutates; assert no torn elements and that
the snapshot is internally consistent.

---

## 6. Encoding

**Question.** Answer `isset`/existence/`count` without deserializing; decode full
values only on read.

**Canon.** **SQLite** file format + record/serial-type encoding (metadata
readable before payload); zero-copy formats **FlatBuffers / Cap'n Proto**;
Protocol Buffers; **igbinary**.

**What to steal.** Metadata-before-payload: a value-kind byte + length in the
block header, answerable without touching payload (cheap `isset`/`count`, fewer
copies — §0.1). Scalars stored raw (no decode); complex values via igbinary.

**What to reject / PHP reality.** True zero-copy field access (FlatBuffers/Cap'n
Proto) is moot — **PHP copies on read regardless** (§0.1), so the win is limited
to *minimizing bytes copied*, not avoiding the copy. igbinary is **optional**
(§0.1): detect once per process; the codec is a capability, and the spec's
mandate must degrade honestly if absent.

**PHP-shaped decision.** Header carries kind + length; scalars raw; complex via
detected igbinary. Existence/`count` never decode payload.

**Experiment.** `isset`/`count` cost with payload present but undecoded vs full
read, across scalar and complex values.

---

## 7. Memory bounding & eviction

**Question.** Stay bounded by live data; no runaway high-water-mark.

**Canon.** memcached slab + LRU; Redis `maxmemory` + approximated LRU/LFU +
incremental expiry; Nishtala et al., *Scaling Memcached at Facebook* (NSDI'13).

**What to steal.** Redis's *execution philosophy* — single-threaded, incremental,
amortized maintenance with no stalls — is the **closest production analog to
PHP's constraints** (§0.1), more applicable than RAMCloud or FASTER.

**What to reject / PHP reality.** Eviction itself (LRU/LFU/TTL) is **out of
scope**: Fast is a store, not a cache (justification rule). Import the
incremental-maintenance *discipline*, not the eviction *features*.

---

## 8. Crash consistency

**Question.** Fail closed on corruption; survive a holder crash without wedging
or silently serving torn data.

**Canon.** Pillai et al., **ALICE** / *All File Systems Are Not Created Equal*
(OSDI'14); failure-atomic `msync`; PMem durability — **PMDK**, NV-Heaps
(ASPLOS'11), Mnemosyne (ASPLOS'11).

**What to steal.** Publish-last ordering (payload → directory entry → bump
sequence) so a crash leaves a detectably-incomplete record, not a live torn one;
**validate-or-refuse on attach** (magic + version + checksum + sequence parity).

**What to reject / PHP reality.** Full PMem logging/transactions are overkill for
DRAM-backed SysV shm, and PHP has no `pmem_persist`/barrier control anyway
(§0.1). Take the *discipline* (ordering + attach-time validation), not the
transactional apparatus.

**Experiment.** Crash a writer between payload and publish; confirm a survivor
attaches and **throws** on the half-published record rather than serving it.

---

## 9. Where the blank slate converges

Filtered through PHP 8.1+ and the priorities, the research points to a design
**smaller** than the current one:

1. **Lifecycle = OS refcount semantics** (kernel attach count via FFI if adopted;
   otherwise self-counter + attach-time validation). Crash-safe by construction.
2. **Single writer + seqlock readers** that copy out — which deletes the entire
   epoch/RCU/hazard-pointer problem the papers spend their pages on.
3. **One index**: cache-line buckets + fingerprint tags, grown/shrunk by
   foreground incremental rehash (Redis model). No threads, no stalls.
4. **One value store**: in-place same-size updates + size-class allocation +
   bounded foreground compaction for shrink. Growth via new-segment migration
   (SysV cannot resize) — or POSIX/FFI `ftruncate` if FFI is adopted.
5. **One encoding**: metadata-before-payload (SQLite), scalars raw, complex via
   detected igbinary. Existence/`count` never decode.
6. **One iteration model**: insertion-order chain under a start-of-loop seqlock
   snapshot. MVCC's guarantee without MVCC's machinery.

The recurring theme: **single-writer + no-background-threads makes most of the
famous machinery unnecessary.** The papers are largely about beating concurrency
problems Fast does not have. That is the clean-room win.

The two places PHP genuinely hurts, and where **FFI is the deciding lever**:
- **Segment resize** (SysV `shmop` cannot; POSIX `mmap`+`ftruncate` can) — §4.
- **Crash-safe attach count** (`shm_nattch` via `shmctl`) — §1.

A focused decision on FFI/POSIX-shm would simplify both. That is the single most
consequential architectural choice the clean-room raises.

---

## 9a. Baseline captured (the prior-Fast control) — 2026-06-27

> **Two distinct baselines — do not conflate them.** This section captures the
> *prior Fast* state as the engineering **control** (to measure each change against
> for regression). The **acceptance bar** is integrity on the canonical stress
> benchmark plus no regression beyond policy vs the pinned baseline (specification §0).

Harness (retired): `research/harness/baseline.php` — quick matrix on shipping
`\Fast` (n∈{1e3,1e4}, value∈{0,64,1024}B), PHP 8.1.2/Linux. Reproduce
similar coverage today with `tests/index_matrix_lib.php` and
`benchmarks/compare-engines.php`.
Artifact: `research/baselines/harness-current-baseline.json`.

Findings that shape the rewrite targets:

1. **No-shrink high-water mark is real and measured (§4).** After deleting half
   the keys, `arena_frontier` and `segment_bytes` **do not move at all** —
   utilization drops 1.0 → 0.50 and stays there; the segment floor is 64 MiB
   regardless of live data. Example (n=10k, 1 KiB values): frontier 10,618,922 B
   before *and* after delete½. Freed space is tracked (`free_bytes`) but never
   returned. **This is the single clearest mandate for the rewrite.**
2. **Local (Journal) insert scales badly; shared is faster at 10k.** Local insert
   p95 blows up with N (≈17 µs @1k → ≈205–243 µs @10k; ~5k ops/s), while shared
   insert stays ≈20–34 µs p95 (~40–48k ops/s). The in-process engine is the worse
   scaler — a counter-intuitive result worth confirming at higher N (smells like a
   linear `findInsertSlot`).
   **RESOLVED (2026-06-28, §9g):** confirmed at 100k (the old local engine did not
   finish 100k inserts in 240 s) and fixed for local mode. The cause was twofold —
   an O(n) directory scan per lookup/insert *and* an O(directory) `substr_replace`
   binary-mirror writeback per write. A private hash-map index plus a lazy binary
   mirror make local insert flat (O(1)-expected): 7,225 → 323,853 ops/s at 10k.
3. **Reads/isset are already cheap** (~2–5 µs p95 both engines); the hot read path
   is not where the wins are. **Writes and footprint are.**
4. **Same-size update is ~3–5× costlier in shared** (≈17–33 µs) than local
   (≈4–8 µs) — the shared write/publish path overhead is a real target.

Implication: the rewrite's burden of proof is concentrated in **footprint/shrink
(§4)** and **write-path cost (§4 in-place + §3 index)**, not reads.

---

## 9b. Experiment 1 result — allocator / shrink bake-off (2026-06-27)

> **Implementation status (2026-06-28): REUSE HALF SHIPPED; SHRINK STILL OPEN.** This
> section records the clean-room experiment that *chose* the allocator/shrink approach
> (size-class slab + bounded incremental foreground compaction).
>
> The **size-class allocation half is now shipped** (Allocator Reuse pass, audit §5b /
> design-law §7): `allocatorGrant` rounds to size classes, so same-class updates are
> in-place O(1) overwrites, class-crossing updates realloc and reuse the vacated block,
> and the free-list scan is bounded. This killed the single-process update collapse the
> Incremental Writer Refresh pass had surfaced (under larger-replace churn the free
> list grew unbounded with `reused_allocations` stuck at 0); `free_block_count` is now
> bounded by the live set and reuse is meaningful (~369/s → ~58,600/s on the collapse
> pattern).
>
> The **shrink half remains open**: the live arena still keeps a high-water-mark
> footprint — deletes/smaller-replaces return space to the in-arena free list but never
> to the OS, no live data is compacted toward the front, and trailing growth segments
> are never dropped. The bounded incremental foreground **compactor + segment drop**
> from this bake-off is **not** shipped. It is deferred — see the audit roadmap
> (`docs/archive/efficiency-audit.md` §7, item "slab + incremental compaction +
> segment drop").

Harness: `research/experiments/01-allocator/run.php` (clean-room, algorithm-level: integer
arena bookkeeping; utilization, shrink, op cost, and bytes-copied are portable
properties). Workload: ramp to 50k live (mixed 8 B–8 KiB sizes), 300k churn ops
holding live ~constant, then drain to 5k. 1 MiB segments for `log`.

| allocator | steady util | post-drain MiB | post util | copied MiB | worst op (max) | shrinks? |
|---|---|---|---|---|---|---|
| slab (free-list only) | 0.83 | **15.7** (unchanged) | 0.09 | 0 | (noise) | **no** |
| slab + **full** compaction | 0.83 | **1.5** | 0.89 | 26 | **~26 ms** | yes |
| slab + **incremental** compaction | **0.83** | **2.2** | 0.63 | **25** | **~9.8 ms\*** | yes |
| log @30% dead | 0.69 | 2.0 | 0.68 | 71 | (noise) | yes |
| log @15% dead | 0.82 | 2.0 | 0.68 | 190 | (noise) | yes |
| log @8% dead | 0.87 | 2.0 | 0.68 | **401** | (noise) | yes |

(Total workload bytes written ≈ 85 MiB, so `log@8%` copied ≈ **4.7× write
amplification** to reach 87% utilization; slab+compaction reached 83% at **0.3×**.
"noise" = no maintenance copies; max is PHP/GC jitter ~5 ms. \*incremental's residual
spike is a *simulator* artifact — see finding 6.)

**Findings (these revise the study's earlier neutral lean):**

1. **`slab` (no compaction) reproduces the current-code failure exactly**: good
   steady utilization (0.83) but it **cannot shrink** — post-drain footprint is
   unchanged (0.09 utilization). This is the baseline behavior from §9a.
2. **`slab + incremental compaction` dominates for this workload**: it matches the
   best utilization (0.83) **and** shrinks (15.7 → 1.5 MiB) while copying ~15× less
   than `log` at comparable utilization (26 MiB vs 190–401 MiB).
3. **Log-structuring only reaches RAMCloud's 80–90% by paying large write
   amplification** (≈5× at 87%). RAMCloud hides that cost with *parallel background
   cleaner threads* — **which PHP cannot run (§0.1)**. On a single foreground
   writer, that copy cost is unhidable. So RAMCloud's headline result does **not**
   transfer to PHP's execution model.
4. **Methodology note:** log utilization is meaningless unless `segment_size ≪
   live_data`; at 8 MiB segments vs ~14 MiB live, granularity pinned utilization at
   0.54 regardless of cleaning. Recorded so the result isn't misread.
5. **Caveat:** wall-clock tails (~5 ms p-max) are PHP/GC noise in the simulator;
   the deterministic maintenance signal is **copied bytes**.
6. **Incremental compaction confirmed (refinement run).** Bounding compaction to
   256 blocks/trigger preserves utilization (0.83, identical to full) and shrink
   (→2.2 MiB) at the same copy cost (~25 MiB), while cutting the worst single-op
   spike from **~26 ms (full) to ~9.8 ms**. The residual ~9.8 ms is a **simulator
   artifact**: each job start does an O(live) `array_keys`/sum snapshot, and during
   the drain a fresh job restarts each time utilization re-crosses the watermark. A
   real implementation compacts by walking the arena with an O(1)-to-resume
   **cursor** (no per-job snapshot), which removes that spike entirely. Net: the
   tail is bounded by the per-trigger budget, exactly as intended. (p99 rises
   modestly — ~320 µs → ~410 µs — the expected cost of spreading work across ops.)

**§4 decision (evidence-backed):** **size-class slab + bounded incremental
foreground compaction with a resumable arena cursor**, not log-structuring. Log
buys nothing here that slab+compaction doesn't, and costs multiples in write
amplification PHP can't background away. Incremental compaction smooths the tail
without sacrificing utilization or shrink; implement the compactor as a resumable
cursor (no per-trigger O(live) snapshot) to avoid the simulator's residual spike.

---

## 9c. Experiment 2 result — index bake-off (2026-06-27)

Harness: `research/experiments/02-index/run.php` (clean-room, algorithm-level). In PHP the
index cost that transfers is **region reads per lookup** (each `shmop`/`mmap` read
is a memcpy out of the segment) and **directory bytes per entry** — not cache lines
or SIMD (§0.1). N=100k keys; reads/bytes measured for hits, misses (`isset`), and
inserts at exact load factors.

The current directory modelled faithfully (`Shared::probeSharedKey`): **open
addressing, linear probe, 40-byte slots**, one entry/slot, full-64-bit-hash
short-circuit. `bucket-fp` = hash→bucket of 8 entries packed in one 64-byte line; a
single bucket read yields 8 `(fingerprint,id)` pairs scanned in-memory; only a tag
match triggers a key read; linear bucket probing on overflow.

| index | load α | dir B/entry | hit reads avg / p99 | miss(`isset`) reads avg / p99 | miss bytes avg | insert p99 |
|---|---|---|---|---|---|---|
| open-linear-40 (current) | 0.50 | 80.0 | 1.49 / 7 | 2.50 / 14 | 100 B | 7 |
| **bucket-fp** | 0.50 | **16.0** | **1.01 / 1** | **1.06 / 2** | **69 B** | **1** |
| open-linear-40 (current) | 0.70 | 57.1 | 2.17 / 15 | 6.04 / 40 | 242 B | 15 |
| **bucket-fp** | 0.70 | **11.4** | **1.06 / 3** | **1.44 / 6** | **94 B** | **3** |
| open-linear-40 (current) | 0.90 | 44.4 | 5.47 / **75** | 50.36 / **403** | **2014 B** | 75 |
| **bucket-fp** | 0.90 | **8.9** | **1.41 / 9** | **6.41 / 43** | **422 B** | **9** |

(Reads = region copies per lookup: 40 B/slot for current, 64 B/bucket for bucket-fp.
Fingerprint false-positive cost is **negligible**: 1.01–1.03 key reads per hit.)

**Findings:**

1. **Directory memory: 5× smaller, at every load.** 64 B per 8 entries (8 B/entry)
   vs 40 B per 1 entry. Structural, not workload-dependent. The current 40-byte slot
   is the single biggest index overhead.
2. **Linear probing collapses at high load — exactly where you want to run to save
   memory.** At α=0.9 the current directory does p99 **75** reads on a hit and p99
   **403** on a miss (~2 KB copied per failed lookup, classic primary clustering).
   bucket-fp stays flat: p99 **9** hit / **43** miss — ~8–9× fewer copies at the tail.
3. **`isset`/miss is the current design's worst case, and `isset` is first-class.**
   Miss avg reads climb 2.5 → 6.0 → **50.4** as load rises; bucket-fp holds
   1.06 → 1.44 → **6.4**. Fingerprints let a miss reject 8 candidates per single
   bucket read without ever touching key bytes.
4. **Fingerprints are nearly free.** ~1.01 key reads per hit (the +0.01 is the 1/256
   tag collision), so the bucket scan costs one region read and an in-memory tag
   compare — the copy-avoidance §0.1 demands.
5. **Honest caveat:** at *low* load (α=0.5) bucket-fp copies marginally more bytes on
   a single hit (one 64 B bucket read > one 40 B slot read; the 64 B key read
   dominates either way). The win is **memory + tail + high-load**, i.e. it lets Fast
   run *dense* (fewer bytes, fewer segment grows) without the probe blow-up — which is
   the whole point.

**§3 decision (evidence-backed):** adopt **cache-line bucket + fingerprint tags**
over the current open-linear-40 directory. It cuts directory memory 5×, keeps `isset`
and lookups flat-tailed under load (where the current design degrades super-linearly),
and makes existence checks reject candidates without key copies. Grow/shrink remains
**foreground incremental rehash** (Redis model, §3) — Experiment 3 stresses that path.

---

## 9d. Experiment 3 result — rehash-under-load (2026-06-27)

Harness: `research/experiments/03-rehash/run.php` (clean-room, algorithm-level: the §3
bucket-fp index modelled as bucket counts + a migration cursor; per-op moved work
and reader dual-table cost are exact, only key identity is abstracted). Workload:
grow 1e3 → 1e6 with `reads/op=4` live readers interleaved; optional drain back to
1e3 to exercise **shrink**. `stop-world` rehashes everything in the triggering op;
`incremental-K` migrates K buckets/op (Redis dict: reads/writes consult both tables
until the migration drains). Metrics are copy/work counts — wall-clock would be GC
noise (§0.1).

**Grow 1e3 → 1e6:**

| strategy | max moved / op | p99 moved | total moved | rehash amp | reads / lookup | peak table |
|---|---|---|---|---|---|---|
| stop-world | **512,410** | 0 | 1.02M | 1.02× | 1.00 | 8.7 MiB |
| incremental-K1 | **8** | 8 | 1.02M | 1.02× | 1.14 | 13.0 MiB |
| incremental-K4 | **29** | 29 | 1.02M | 1.02× | 1.04 | 13.0 MiB |
| incremental-K16 | **116** | 0 | 1.02M | 1.02× | 1.01 | 13.0 MiB |

**Grow then drain 1e6 → 1e3 (adds shrink rehash):**

| strategy | max moved / op | total moved | rehash amp | reads / lookup |
|---|---|---|---|---|
| stop-world | 512,410 | 1.48M | 1.48× | 1.00 |
| incremental-K1 | **45,548** | 1.48M | 1.48× | 1.18 |
| incremental-K4 | **29** | 1.48M | 1.48× | 1.05 |
| incremental-K16 | **116** | 1.48M | 1.48× | 1.01 |

**Findings:**

1. **Incremental eliminates the stall at zero extra total work.** Stop-world pays a
   **single op that rehashes 512k entries** near the top doubling; incremental caps
   per-op work at **K·(load·B) ≈ 8/29/116** entries (K=1/4/16). Total moved is
   identical (1.02× amp) — incremental *spreads* the same work, it doesn't add work.
2. **Readers are essentially never blocked.** Avg bucket reads per lookup stays
   **1.01–1.14** (1.0 = single table, 2.0 = always dual-table). Migrations complete
   fast relative to the gap between doublings, so only a thin slice of reads pay the
   dual-table cost — and even those just read one extra bucket, never stall.
3. **Transient memory overshoot ~1.5×** (13.0 vs 8.7 MiB): old+new tables coexist
   during a migration (new = 2× old ⇒ 3× old transiently vs 2× old final). Expected
   Redis cost; bounded and brief.
4. **Shrink has a tighter migration budget than grow — pick K ≥ 4.** Drain exposes
   it: **K=1 force-finishes once (45,548-entry spike)** because after a halving the
   load only has to fall from ~0.4 to 0.2 before the next shrink (~0.8·buckets
   deletes), which is less runway than the `buckets/K` ops a K=1 migration needs.
   K ≥ 2 closes the gap; **K=4/16 stay bounded (≤116)** on both grow and shrink.

**§3 decision (confirmed):** **foreground incremental rehash**, migrating a small
fixed budget of buckets per op (**K = 8–16** for ample margin on both grow and
shrink), with readers consulting both tables during migration. This turns Fast's
1e3→1e6 growth from a half-million-entry stop-the-world stall into bounded ≤~100-entry
maintenance steps with no reader stalls, at ~1.5× transient memory and no extra total
work. No threads required — the migrator runs on the writer's own ops (§0.1).

---

## 9e. Experiment 4 result — lifecycle / crash safety (2026-06-27)

Harness: `research/experiments/04-crash/run.php` — **not** a simulation but a real OS-level
spike on this box (PHP 8.1.2 / Linux / glibc), in the spirit of the FFI spike. Real
processes are `kill -9`'d; it validates the two crash-safety mechanisms §1/§8 depend
on. Tools present: `pcntl`, `posix`, `shmop`, `FFI`, `sysvshm`.

**(A) Torn-write fail-closed (§8).** A forked writer guards one record with a
seqlock (odd seq = write in progress) + length + CRC32, writing payloads in 256 B
chunks to widen the torn window. The parent SIGKILLs it at a random point mid-write,
then a fresh reader applies the seqlock protocol.

| | result |
|---|---|
| trials | 300 |
| proven killed mid-write (seq left **odd**) | 54 |
| reader verdict: in-progress → **fail-closed** | 54 |
| reader verdict: ok (consistent snapshot) | 246 |
| **torn records accepted as valid** | **0** |
| live concurrent reads (0.5 s, writer running) | 923,921 |
| → accepted / retried-in-progress / **leaks** | 486,365 / 437,556 / **0** |

A `kill -9` between the two seq increments leaves the seqlock **odd**, which every
reader treats as "writer died mid-update" and refuses — across 300 crashes and ~924k
live concurrent reads, **not one torn record was ever handed back as valid**.

**(B) Lifecycle reclaim under crash (§1).** SysV attach-count (`shm_nattch`, read via
FFI `shmctl(IPC_STAT)`). Parent attaches, forks 3 holders (each inherits the
attachment across `fork`, so nattch→4), then all holders are `kill -9`'d with **no
userspace cleanup running**.

| stage | non-persistent (IPC_RMID at create) | persistent (no IPC_RMID) |
|---|---|---|
| created + parent attached | nattch 1 | 1 |
| forked 3 holders | 4 | 4 |
| parent detached | 3 | 3 |
| all holders `kill -9` reaped | **gone (reclaimed)** | **0 (survives)** |

The kernel decrements `nattch` on `SIGKILL` (no cleanup code needed). With
**`IPC_RMID` set at create** the segment is freed automatically the instant the last
(crashed) holder detaches — the non-persistent "last one out turns off the lights"
rule, crash-proof. **Without** `IPC_RMID` the segment survives at `nattch=0` —
persistent. Post-run `ipcs` shows **0 leaked test segments**.

**Findings:**

1. **Seqlock fails closed under `kill -9`** — torn detection is structural (odd seq),
   not probabilistic. 0/300 crash trials and 0/924k live reads leaked torn data. CRC
   is the backstop for non-seqlock corruption; the seqlock alone caught every crash.
2. **Non-persistent reclaim needs no daemon, no `__destruct`, no refcount file.** The
   kernel's `IPC_RMID`-at-create idiom does it — survives `kill -9`, power-loss of the
   process, everything short of kernel death. This is the §1 lifecycle, for free.
3. **Persistent survival is just the absence of `IPC_RMID`** — symmetric, trivial.
4. **`shm_nattch` is the link counter the spec wants** (§1 = connected PIDs): `fork`
   increments it, process death (any cause) decrements it. Authoritative and
   maintained by the kernel, not by us.
5. **Caveat:** validated on glibc/Linux x86-64. The `struct shmid_ds` offset for
   `shm_nattch` is glibc-specific (same caveat as the FFI spike); portability beyond
   this box is an open question, not a feasibility one.

**§1/§8 decision (confirmed):** lifecycle = **SysV `shm_nattch` as the PID link
count + `IPC_RMID`-at-create for non-persistent auto-reclaim**, no `IPC_RMID` for
persistent. Crash consistency = **seqlock (odd = in-flight) + CRC backstop**, readers
fail closed. Both mechanisms proven crash-proof here; no background threads or cleanup
processes required (§0.1). **(Link-count source revised by §9f: see below — without
FFI we cannot read `shm_nattch`, so the count comes from a PID-liveness table. The
`IPC_RMID` reclaim and the seqlock/CRC consistency need no FFI and stand unchanged.)**

---

## 9f. Decision — FFI deferred; no-FFI is the development baseline (2026-06-28)

**Decision (project owner):** `ext-ffi` is widely treated as a **security risk** and
is commonly disabled (notably under PHP-FPM). FFI is therefore **deferred to a
possible future optional accelerator** and **not factored into development**. Fast is
built on `shmop`/SysV alone. This overrides the FFI-leaning notes in §1/§4 wherever
FFI was assumed; those capabilities (proven in the spike) remain documented only as a
*future* option.

The clean-room experiments still decide everything — only the backend that realizes
them changes. The split is below the contract line (the spec is phrased
mechanism-agnostically), so this costs no public-API change. Two FFI-only capabilities
and their no-FFI substitutes:

| capability | FFI path (deferred) | **no-FFI baseline (chosen)** |
|---|---|---|
| segment resize / shrink | POSIX `ftruncate` in place | **multi-segment: compact forward + drop empty trailing segments; size change via incremental dual-region migration** |
| connected-process count | kernel `shm_nattch` via `shmctl` | **in-segment PID table swept with `posix_kill($pid,0)`** |

Everything else is already FFI-independent and unchanged: bucket-fp index (§9c),
slab+incremental compaction (§9b), incremental rehash (§9d), **`IPC_RMID` reclaim via
`shmop_delete()`** and **seqlock+CRC** (§9e, both verified under `kill -9` with no
FFI).

**No-FFI cost vs the FFI path (honest):**

1. **Shrink is coarser.** No `ftruncate`; footprint drops only when a whole trailing
   segment empties (or via a migration that copies live data to a smaller geometry).
   The §9 contract already promises segment-granular shrink, not per-delete shrink, so
   this is within contract — just less fine-grained than FFI would allow.
2. **Resize across a size class copies.** Grow/shrink that can't be satisfied by
   adding/dropping a trailing segment uses the same **incremental dual-region**
   migration proven for the directory (§9d): bounded per-op, readers follow a
   `generation` in a small fixed control segment, never stop-the-world. Transient
   ~1.5–2× memory during a migration.
3. **Link count is derived, not kernel-maintained.** A PID table + liveness sweep is
   crash-tolerant (dead PIDs pruned on the next sweep) and runs only on
   attach/detach/destroy (cold path) — never on `get`/`set`. Slightly more code than
   reading `shm_nattch`, but no kernel dependency and no FFI.

**Net:** the no-FFI baseline meets the full spec (lifecycle, crash safety, bounded
memory, shrink) using `shmop` + `posix` + `igbinary` only. FFI would make shrink
finer and the count free, but buys nothing the contract requires. Folded into the
spec as section 15 (implementation direction) to guide development.

---

## 9g. Implemented — local Journal O(1) index + lazy binary mirror (2026-06-28)

**Scope (narrow, by directive):** local-mode (`Journal`, in-process) lookup/insert
only. **No** shared-memory format change, **no** shared directory rewrite, **no**
bucket-fp shared index, **no** incremental rehash, **no** allocator/slab work, **no**
public-API change, **no** FFI. This realizes the §9a finding-2 fix; the shared-side
wins (§9b–§9d) remain future passes.

**Root cause (two O(n)-per-op costs, both local-only):**

1. **Lookup scan.** `findSlotNormalized()` did open-addressing probing over the
   directory on every `get`/`has`/`isset`/`unset`/update, with a record-frame read
   per hash-candidate. `findInsertSlot()` probed again on new-key insert.
2. **Binary-mirror writeback (the dominant cost).** `writeDirectorySlot()` and the
   order-node writers did a `substr_replace` over the *entire* in-process directory
   (~`slots × 49 B`) / order-log mirror on **every** write. At 10k/100k this byte
   copy — not the scan — was the real O(n²) aggregate. Measured: removing only the
   scan left 10k insert unchanged (7,130 vs 7,225 ops/s); removing the eager mirror
   too made it flat.

**Change:**

- **Private hash-map index** (`Journal::$keyToSlotIndex`): `"kind:bytes" → slot
  index` for live keys only. `findSlotNormalized()` is now an array lookup
  (O(1)-expected), no probing, no per-candidate frame read. Maintained on insert
  (add), delete (remove), replacement (no-op: slot index is stable); rebuilt on
  `__unserialize()` and `compact()` (cold paths). Never serialized, never exposed,
  absent in shared mode — zero format impact.
- **Lazy binary mirror:** `directoryBinary`/`orderBinary` are invalidated (not
  byte-patched) on write and rebuilt on demand only when `directoryLog()`/
  `orderLog()` is requested (engine tests / serialization output). Per-slot/per-node
  reads now come from the in-process PHP arrays (`directorySlotsData`,
  `orderNodes`). Emitted bytes are byte-identical to the eager mirror.
- **Local serialization bug found and fixed** (under "preserve serialization /
  foreach order"): the order-list head/tail node lives at offset `0`, but
  `__serialize`/`__unserialize` conflated offset `0` with "no node" (`null`), so any
  non-empty local store lost `foreach` order on wake (count survived, iteration was
  empty). Now encoded with a `-1` sentinel. Local payload only; no shared format.

**Result (local, scalar values, `baseline.php`, PHP 8.1.2/Linux):**

| N | insert/s before | insert/s after | insert p95 before | insert p95 after |
|---|---|---|---|---|
| 1k | 112,847 | 358,827 | 13.2 µs | 7.2 µs |
| 10k | 7,225 | 323,853 | 147.7 µs | 4.1 µs |
| 100k | did not finish in 240 s | 229,383 | — | 7.4 µs |

Insert/s is now flat across N (O(1)-expected) instead of collapsing; reads/`isset`
stay ~1–3 µs. Regression guard: `Fast/test/local_index_scaling.php` (per-op cost
ratio 1.37 for 16× more entries; trips if it climbs back toward linear). Full gate:
117/117 green.

**Remaining local debt (not in this pass):** `findInsertSlot()` still open-address
probes for placement of *brand-new* keys (expected O(1) at load factor < 1, but
inherent to the open-addressed directory). The directory still cannot grow (throws
when full); shared-side footprint/shrink and the bucket-fp index are the next passes
(§9b–§9d).

---

## 10. Experiments to run first (evidence before architecture)

The justification rule applies to the design itself — prove it before committing.

1. **Allocator / shrink bake-off** (§4): slab + foreground compaction vs
   log-structured arena + incremental cleaning over 50→5M churn; measure
   utilization and writer tail latency. *Highest priority — least-solved today.*
   **DONE (2026-06-27) — slab + incremental compaction wins; see §9b.**
2. **Index bake-off** (§3): cache-line bucket + fingerprint vs current directory
   on read-hit/read-miss/`isset`.
   **DONE (2026-06-27) — bucket-fp wins (5× less memory, flat tails); see §9c.**
3. **Rehash-under-load** (§3): grow 1e3→1e6 with live readers; confirm no stalls,
   bounded per-op work.
   **DONE (2026-06-27) — incremental rehash bounds per-op work to ≤~100 entries vs
   a 512k stop-world stall, no reader stalls; K ≥ 4. See §9d.**
4. **Lifecycle / crash** (§1, §8): `kill -9` mid-write; confirm reclaim of
   non-persistent, survival of persistent, fail-closed on torn records.
   **DONE (2026-06-27) — seqlock fails closed (0/300 crashes, 0/924k live reads
   leaked); IPC_RMID-at-create auto-reclaims non-persistent under kill -9, persistent
   survives; shm_nattch is the kernel-maintained PID link count. See §9e.**
5. **FFI feasibility spike** (§0.2, §1, §4): can FFI drive POSIX `shm_open` +
   `ftruncate` + `mmap` and `shmctl(IPC_STAT)` portably enough to justify the
   dependency? This gates the resize and attach-count decisions.
   **DONE (2026-06-27): both capabilities pass on PHP 8.1.2/Linux — see the spike
   result box in §0.2. DECISION (2026-06-28, §9f): FFI is DEFERRED as a
   security-sensitive optional future accelerator and is NOT used in development. The
   no-FFI shmop/SysV baseline is the build target; capabilities here remain only a
   future option.**

---

## 11. Reading list (anchored)

Concurrency & reclamation
- Lamport, *Concurrent Reading and Writing*, CACM 1977.
- Linux kernel seqlock documentation.
- Fraser, *Practical Lock-Freedom* (epoch-based reclamation), 2004.
- McKenney et al., RCU.

In-memory / KV systems
- Rumble et al., *Log-structured Memory for DRAM-based Storage*, USENIX FAST 2014 (RAMCloud).
- Chandramouli et al., *FASTER: A Concurrent Key-Value Store with In-Place Updates*, SIGMOD 2018.
- Fan et al., *MemC3*, USENIX NSDI 2013; Li et al., *Algorithmic Improvements for Fast Concurrent Cuckoo Hashing*, EuroSys 2014 (libcuckoo).
- Nishtala et al., *Scaling Memcached at Facebook*, NSDI 2013.
- Redis internals: `dict` incremental rehashing, `maxmemory`, `activedefrag`.

Hashing / index layout
- Nam et al., *CCEH: Cacheline-Conscious Extendible Hashing*, FAST 2019.
- Lu et al., *Dash: Scalable Hashing on Persistent Memory*, VLDB 2020.
- Chen et al., *Lock-free Concurrent Level Hashing*, USENIX ATC 2020.
- Hu et al., *A Quantitative Evaluation of Persistent Memory Hash Indexes*, VLDB Journal 2023.
- Folly F14 / Abseil Swiss tables (tag-array layout).

Allocation & fragmentation
- Wilson, Johnstone, Neely, Boles, *Dynamic Storage Allocation: A Survey and Critical Review*, 1995.
- Johnstone & Wilson, *The Memory Fragmentation Problem: Solved?*, ISMM 1998.
- Bonwick, *The Slab Allocator*, USENIX 1994.
- jemalloc / tcmalloc design notes (size classes, decay/purge).

Ordering, encoding, durability
- Reed, MVCC, 1978; Bernstein & Goodman, concurrency-control survey, 1981.
- SQLite file format & record encoding; FlatBuffers / Cap'n Proto.
- Pillai et al., *All File Systems Are Not Created Equal* (ALICE), OSDI 2014.
- PMDK; Coburn et al., *NV-Heaps*, ASPLOS 2011; Volos et al., *Mnemosyne*, ASPLOS 2011.

OS fundamentals (the lifecycle answer)
- System V shared memory: `shmget`/`shmat`/`shmdt`/`shmctl`, `shm_nattch`.
- POSIX shared memory: `shm_open`/`shm_unlink`, `mmap`, `ftruncate`.
- Stevens & Rago, *Advanced Programming in the UNIX Environment* (unlink-while-open).
