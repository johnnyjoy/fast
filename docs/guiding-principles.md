# Fast Guiding Principles

Status: operating doctrine + research grounding. **Not** the contract — the contract
is `specification.md`. This is the working reference for *how to think* about Fast.

This document is the working reference for the `Fast` re-architecture.
It captures what `Fast` is for, what makes it fast or slow, and which storage ideas are worth
keeping when making future changes.

## Objective

`Fast` is a shared-memory map for PHP that behaves like an array — stable reads,
fast writes, predictable recovery, and a small public surface.

The canonical acceptance workload is the 100k stress benchmark
(`tests/stress_100k_bench.php`). Integrity is mandatory; throughput is tracked
against the pinned baseline (`research/baselines/stress-100k-baseline.json`).
See `docs/specification.md` §0.

This goal is served by:

- stable reads
- fast writes
- predictable recovery
- benchmark-gated structural changes only

The implementation should favor the hot path, not the prettiest abstraction.

## What Fast Is Doing

Current `Fast` responsibilities:

- expose the public API through `ArrayAccess`, `Iterator`, count, and direct methods
- keep key identity and payload storage separate
- preserve insertion order through explicit traversal metadata
- keep lookup cheap enough that `has()` does not require payload decode
- support shared-memory use for multiple processes

Current structural costs:

- mutation is too expensive if it rewrites too much unrelated state
- shared-state persistence must not become a full-snapshot tax on every write
- any read path that depends on reparsing the world is a regression risk

## Research That Matters

### 1. Write-ahead and append-only storage

SQLite WAL makes the key tradeoff clear: append changes first, keep reads on stable data, and
checkpoint later.
That model improves concurrency because readers and writers can proceed at the same time, while
the checkpoint is the separate maintenance cost that keeps the log from growing without bound.

Relevant source:
- [SQLite WAL documentation](https://sqlite.org/wal.html)

What to take from it:

- appends are cheap
- readers should not block on every write
- compaction/checkpointing belongs off the hot path
- if the log is allowed to grow forever, read performance degrades

### 2. Shared-memory read consistency

Linux sequence counters and seqlocks are built around a simple rule:
writers mark a critical section, readers retry if the version changes.
That gives lockless readers a way to get a consistent snapshot without paying full lock overhead.

Relevant source:
- [Linux kernel seqlock documentation](https://docs.kernel.org/locking/seqlock.html)

What to take from it:

- readers should be cheap and retry-based
- writers must be serialized
- readers must validate consistency, not assume it
- pointer-heavy or shape-changing data is a poor fit for this style unless the indirection is very
  carefully managed

### 3. LSM-tree style organization

LSM-tree research consistently points to the same pattern: keep writes sequential and deferred,
then merge or compact later.
The main advantage is write throughput; the main risk is read amplification if compaction and
indexing are not controlled.

Relevant sources:
- [A survey of LSM-Tree based Indexes, Data Systems and KV-stores](https://arxiv.org/abs/2402.10460)
- [LSM-based Storage Techniques: A Survey](https://arxiv.org/abs/1812.07527)
- [The Skiplist-Based LSM Tree](https://arxiv.org/abs/1809.03261)

What to take from it:

- sequential append is usually the right write primitive
- a separate directory/index is essential
- compaction must be explicit and bounded
- read performance depends on keeping the search structure narrow and current

### 4. Stable storage shape

SQLite’s file-format design shows another useful discipline:

- fixed-width headers
- explicit versioning
- reserved space for expansion
- clear separation between header, index, payload, and overflow areas

Relevant source:
- [SQLite database file format](https://www.sqlite.org/fileformat.html)

What to take from it:

- the format should be readable before payload parsing
- the version and layout must be explicit
- structural expansion should be planned, not accidental
- overflow should be a first-class concept, not a side effect

### 5. Recent storage research that matches Fast's workload

The last 20 years of storage research keeps converging on the same few patterns that matter
for `Fast`:

- append first, reconcile later
- keep readers on a narrow stable structure
- separate live metadata from payload growth
- bound maintenance so it does not steal the hot path
- use concurrency-aware indirection instead of reparsing the world

Relevant sources:
- [CedrusDB: Persistent Key-Value Store with Memory-Mapped Lazy-Trie](https://arxiv.org/abs/2005.13762)
- [From WiscKey to Bourbon: A Learned Index for Log-Structured Merge Trees](https://arxiv.org/abs/2005.14213)
- [Autumn: A Scalable Read Optimized LSM-tree based Key-Value Store with Fast Point and Range Read Speed](https://arxiv.org/abs/2305.05074)
- [vLSM: Low tail latency and I/O amplification in LSM-based KV stores](https://arxiv.org/abs/2407.15581)
- [LearnedKV: Integrating LSM and Learned Index for Superior Performance on SSD](https://arxiv.org/abs/2406.18892)
- [HotRAP: Hot Record Retention and Promotion for LSM-trees with Tiered Storage](https://arxiv.org/abs/2402.02070)
- [LSMGraph: A High-Performance Dynamic Graph Storage System with Multi-Level CSR](https://arxiv.org/abs/2411.06392)
- [BVLSM: Write-Efficient LSM-Tree Storage via WAL-Time Key-Value Separation](https://arxiv.org/abs/2506.04678)
- [O^3-LSM: Maximizing Disaggregated LSM Write Performance via Three-Layer Offloading](https://arxiv.org/abs/2603.05439)

What to take from them:

- `CedrusDB` shows that memory-mapped, persistent in-memory indexes can recover quickly
  when the index shape is storage-friendly instead of transient.
- `Bourbon` and `Autumn` both reinforce that lookup speed depends on keeping the search
  structure compact and current rather than piling more logic into the read path.
- `vLSM` and `HotRAP` show that read latency and tail latency are usually harmed by
  compaction chains, tier imbalance, and stale hot records.
- `BVLSM` reinforces the value of separating key/value handling early in the write path
  instead of duplicating the work during later flush or compaction stages.
- `O^3-LSM` shows that modern high-throughput systems treat memory placement, offloading,
  and delegation as first-class structural decisions, not afterthoughts.
- `LSMGraph` shows that dynamic workloads benefit from layered metadata and version control
  when the storage shape must remain both mutable and cheap to read.

## Practical Design Rules For Fast

### Keep these

- append-only record creation
- a separate live directory for presence and key-to-value mapping
- separate order metadata for iteration
- one-writer discipline in shared mode
- explicit reclaim/compaction instead of implicit cleanup during hot writes

### Avoid these

- full-snapshot rewrite on every mutation
- payload decoding just to answer existence queries
- hidden dependence on external store implementations or foreign concepts
- reintroducing special cases that only exist to rescue an awkward class shape
- unbounded log growth without a reclaim policy

### Prefer this shape

- write path:
  1. normalize key
  2. append payload
  3. append traversal metadata
  4. publish directory entry last
- read path:
  1. probe directory
  2. validate entry version/state
  3. fetch payload only if needed
- maintenance path:
  1. compact dead entries
  2. reclaim unreachable traversal metadata
  3. trim shared storage when safe

## Stability Rules

- Every change must be benchmarked and regression-gated.
- The full functional suite must stay green.
- If a structural change improves one hotspot but regresses another materially, reject it.
- Do not accept a design just because it works.
- Do not keep a design if it forces repeated work in the hot path.

## Current Working Hypothesis

The right long-term shape for `Fast` is:

- append-only mutation
- narrow directory/index
- separate order chain
- bounded compaction/reclaim
- lockless or low-contention readers with explicit consistency checks
- early key/value separation so the hot path does not pay to rediscover static facts
- explicit maintenance boundaries so writes stay fast and reads do not degrade as the log grows

The newest research does not suggest a different direction. It reinforces that the fastest
stable design is the one that keeps the hot path short and pushes expensive reconciliation
into bounded maintenance work.

That is the shape most aligned with the workload and the research.

## References

- [SQLite WAL](https://sqlite.org/wal.html)
- [SQLite database file format](https://www.sqlite.org/fileformat.html)
- [Linux seqlock documentation](https://docs.kernel.org/locking/seqlock.html)
- [LSM survey 2024](https://arxiv.org/abs/2402.10460)
- [LSM survey 2018](https://arxiv.org/abs/1812.07527)
- [Skiplist-based LSM tree](https://arxiv.org/abs/1809.03261)
