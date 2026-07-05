# Performance

This document tells you what Fast actually measures, what the numbers mean, and
how to decide between **Flat** (default) and **Striped** (optional write
concurrency). Everything here is reproducible from scripts in this repo.

**Reference environment** (unless noted otherwise):

- PHP 8.3.30, Linux x86_64
- CPU: AMD Ryzen 9 3900X (12 cores / 24 threads)
- RAM: 64 GiB (4 × 16 GiB), DDR4-2133 MT/s
- Shared store: 128 MiB segment budget (`size`), 262144 directory slots
- Extensions: igbinary, shmop, sysvsem

All pinned numbers in this document were captured on that hardware.

### Why RAM is part of the reference spec

Fast is a **shared-memory** store. The directory, order log, and value arena live
in SysV segments backed by **physical RAM**. Every get and set crosses the PHP
boundary through `shmop_read` and `shmop_write` — copies between the PHP process
and that RAM. There is no network hop, but there is still a memory read/write on
every operation.

That makes Fast **RAM-speed dependent** in a way a pure CPU-bound microbenchmark
is not. Faster DRAM (higher MT/s, better latency) moves throughput and per-op
latency. Slower RAM or memory pressure shows up in the numbers. This has been
measured directly: the same workload on the same CPU with faster RAM produces
significantly higher tx/s than on slower RAM.

CPU still matters — especially for multi-process lock contention (Flat vs Striped)
— but **do not publish or compare Fast numbers without disclosing RAM amount and
speed**. They are as much a part of the test rig as the processor.

When you reproduce results, record:

- CPU model and core count
- RAM capacity and speed (e.g. `sudo dmidecode -t memory | grep -E 'Size:|Speed:'`)
- PHP version and store `size` / `capacity` / `stripes`

Run the commands in [Reproduce everything](#reproduce-everything) on your hardware
before you commit to a configuration.

---

## Two engines, one API

Both engines implement the same public surface: `new Fast([...])`, array syntax,
`foreach`, `count()`, lifecycle.

| | **Flat** | **Striped** |
|---|----------|-------------|
| Config | omit `stripes`, or `stripes => 1` | `'stripes' => 8` (power of two ≥ 2) |
| Storage | one SysV segment set, one write lock | N independent Flat sub-stores, N locks |
| Best for | single writer, read-heavy, hot keys, strict iteration order | many processes writing **different** keys |
| Default | **yes** | opt-in only |

Striped is not “a faster Flat.” It is a different layout you enable when
multi-process **write** contention on spread keys is your bottleneck.

---

## Part 1 — Flat performance (single process)

These numbers describe **Flat only**, one PHP process, warm shared store. They
answer: “How fast is a get/set on the hot path?”

### Per-operation latency (p95)

Measured with the op matrix harness (`Matrix\benchSize`): fill N string keys,
then time inserts, warm lookups, and overwrites. Latency is from `hrtime()` around
each property access.

| Keys in store (N) | Insert p95 | Warm lookup p95 | Update p95 |
|-------------------|------------|-----------------|------------|
| 1,000 | 7.6 µs | 2.1 µs | 4.1 µs |
| 10,000 | 6.6 µs | 2.8 µs | 4.8 µs |

Warm lookup and update stay flat as N grows: directory probe is O(1) average case.
That is the design target for Flat.

Pinned best-floor latencies (used by `benchmarks/track.php` regression gate) live
in `benchmarks/history/baseline.json` for N = 1000, 10000, 25000.

### Stress throughput (Flat, single process)

The canonical endurance workload is `tests/stress_100k_bench.php`:

- 100,000 inserts, then 500,000 mixed churn ops (reads, updates, deletes, reinserts)
- 256 MiB segment budget, fixed seed
- Integrity must be perfect (zero lost records, zero orphans)

**Pinned reference** (`research/baselines/stress-100k-baseline.json`, captured on
Flat, PHP 8.1.2 Linux; re-run on 8.3 for your machine):

| Phase | Throughput | Per-op |
|-------|------------|--------|
| Insert 100k | 318,442 tx/s | 3.14 µs |
| Warm read 100k | 706,165 tx/s | 1.42 µs |
| Update same size | 428,913 tx/s | 2.33 µs |
| Update larger | 227,730 tx/s | 4.39 µs |
| Mixed write-heavy 500k | 254,547 tx/s | 3.93 µs |
| **Total run** | **295,676 tx/s** | **3.38 µs** |

Peak PHP heap during the run is ~15 MiB; payload lives in shared memory, not in
the PHP heap.

Acceptance rule (see `specification.md`): **integrity first**, then throughput
must not regress beyond policy vs this baseline (`tests/stress_gate.php`).

### What Flat is optimized for

- **Reads** lock-free on x86 (seqlock); one directory slot read + one record read on hit
- **Scalar values** skip igbinary on the fast path
- **count()** O(1) from header live count
- **Single writer** no lock contention between your own ops

Flat is the default because most apps have one writer process, or many readers
and few writers, or writes concentrated on a small key set.

---

## Part 2 — Flat vs Striped (same workloads)

This is the comparison that matters for choosing `stripes`. **Both engines run
identical PHP code**; only the config changes.

```php
// Flat
$store = new Fast(['name' => 'bench', 'persistent' => true]);

// Striped (8 sub-stores)
$store = new Fast(['name' => 'bench', 'persistent' => true, 'stripes' => 8]);
```

### How the comparison works

Script: `benchmarks/compare-engines.php`

Each scenario:

1. Creates a fresh named store with the given stripe count
2. Runs the workload (fork + synchronized start for multi-process cases)
3. Measures total wall time and reports **writes per second**
4. Repeats for Flat (1 stripe) and Striped (8 stripes) **on the same task**

**Ratio** = Striped ÷ Flat. Above 1.0 means Striped was faster on that task.
Below 1.0 means Flat was faster.

Default parameters: 2000 writes per worker, 200,000 key space for spread tests,
8 stripes.

### Measured results (reference run)

```text
Scenario                              Workers   Flat writes/s   Striped writes/s   Ratio
─────────────────────────────────────────────────────────────────────────────────────
Many writers, keys spread               4          49,355         183,527        3.72×
Many writers, one shared counter        4          89,033          87,626        0.98×
Many writers, keys spread               8          36,362         210,224        5.78×
Many writers, one shared counter        8          60,672          57,685        0.95×
Single writer, keys spread              1         138,076         161,686        1.17×
```

### Scenario A — Many writers, keys spread (Striped’s job)

**What happens:** 4 or 8 PHP child processes start together. Each performs 2000
writes to random keys `k:0` … `k:199999` (scalar integers). Writes collide on
keys sometimes, but traffic is spread across the hash space.

**Why Flat struggles:** one write semaphore. Only one process can write at a time;
others block. Throughput **falls** as worker count rises (41k writes/s at 8 workers
vs 49k at 4 on this run).

**Why Striped helps:** keys route to 8 stripes by hash. Up to 8 writers can hold
different stripe locks at once. Throughput **rises** with workers (210k writes/s at
8 workers).

**Takeaway:** if your production pattern looks like this (worker pool, each worker
owns or touches many different keys), Striped is worth measuring. A **3–6×** win
at 4–8 workers on reference hardware is typical.

### Scenario B — Many writers, one shared counter (Flat’s job; Striped misplaced)

**What happens:** same fork setup, but every worker runs `$store['counter']++`
2000 times on the **same key**.

**Why Striped cannot help:** that key hashes to exactly one stripe. All workers
still serialize on one lock. Striped adds hash routing and splits `capacity` and
`size` eight ways, so you pay overhead without gaining parallelism.

**Measured:** ratio ≈ 1.0 at 4 workers, **0.95× at 8 workers** (Striped slightly
slower). This matches the design: **do not enable stripes for global counters,
leader election keys, or any write pattern dominated by a few hot keys.**

Note: `++` on a shared key is not atomic across processes in the PHP sense; use
Fast for counters only with full awareness of read-modify-write semantics under
your concurrency model.

### Scenario C — Single writer, keys spread (Flat’s default)

**What happens:** one process, 8000 random key writes (same total work as one
worker in the 4-worker spread test).

**Why Striped is usually wrong here:** no lock contention to remove. Striped still
routes every key through stripe selection and uses smaller per-stripe segments.

**Measured:** ratio 1.17× on reference run (Striped slightly faster). Treat
single-writer comparisons as **noisy**; choose Flat for simplicity unless you
**also** have the multi-writer spread pattern in production.

### Side-by-side: when each engine wins

| Workload shape | Flat | Striped | Reference ratio (8 workers, spread) |
|----------------|------|---------|-------------------------------------|
| 1 process writing | ✓ default | unnecessary | n/a |
| Many processes, many keys | lock bottleneck | ✓ parallel writes | **5.78×** |
| Many processes, one hot key | ✓ same as Striped or better | no benefit | **0.95×** |
| Mostly reads across processes | ✓ lock-free reads | same read path per stripe | not bench-gated here |
| Strict global insertion order | ✓ strict | approximate across stripes | correctness tradeoff |

### Striped costs (even when it wins)

1. **Capacity and size are totals split across stripes.** `'capacity' => 262144,
   'stripes' => 8` gives 32768 slots per stripe. Uneven hash load fills one stripe
   before the nominal total.

2. **Iteration order** is merged across stripes by timestamp. Strict single-writer
   order is preserved; concurrent cross-stripe inserts can fuzz order slightly.

3. **Configuration floor:** `size / stripes` must meet per-stripe minimum;
   attach fails with a clear error if not.

4. **Eight segments, eight semaphores** more moving parts for debugging.

---

## Part 3 — Decision guide

Use this flow before you set `'stripes' => 8` in production.

```
Do multiple PHP processes write to the same named store?
  NO  → use Flat (default)
  YES → Do writes go to many different keys (not one global key)?
          NO  → use Flat
          YES → Run: php benchmarks/compare-engines.php
                Is "MP spread keys" ratio ≥ ~2× at your worker count?
                  YES → try Striped in staging; re-measure your real workload
                  NO  → stay on Flat
```

**Enable Striped when:**

- You have measured write lock contention on Flat
- Writers touch a broad keyspace (sessions, per-job status, sharded counters keyed by id)
- You accept approximate iteration order across concurrent writers

**Stay on Flat when:**

- One process, or one writer and many readers
- Writes concentrate on a few keys
- You need the simplest lifecycle and strictest iteration semantics

---

## Part 4 — What this document does not cover

| Topic | Status |
|-------|--------|
| Read-heavy multi-process | Reads use seqlock on Flat; not head-to-head bench vs Striped here |
| vs Redis / APCu / files | Different problem (network, local-only, durability) |
| Maximum store size / growth | See `engine-architecture.md` (arena growth, compaction) |
| ARM / non-x86 seqlock | Flat disables lock-free reads; uses locked reads (see platform note in spec) |
| Striped stress 100k | Stress baseline is Flat only; run `FAST_MP_STRIPES=8` on `mp_parallel_stress.php` for Striped correctness |

---

## Reproduce everything

Record your hardware before comparing numbers:

```bash
# CPU
lscpu | grep -E 'Model name|CPU\(s\):'

# RAM amount and speed
grep MemTotal /proc/meminfo
sudo dmidecode -t memory | grep -E 'Size:|Speed:|Type:' | grep -v 'No Module'
```

### Flat vs Striped (primary comparison)

```bash
php benchmarks/compare-engines.php
php benchmarks/compare-engines.php --workers=4,8 --stripes=8 --ops=2000
php benchmarks/compare-engines.php --json > flat-vs-striped.json
composer bench:compare
```

### Single-process op latency (Flat)

```bash
php benchmarks/run.php --quick --cases=fast_op_matrix
# or directly:
php -r 'require "tests/bootstrap.php"; require "tests/index_matrix_lib.php";
  $b=Matrix\benchSize("x",10000,42);
  print_r($b);'
```

### Stress throughput (Flat, integrity + total tx/s)

```bash
php tests/stress_100k_bench.php
php tests/stress_gate.php    # optional regression vs pinned baseline
```

### Harness integration (same matrix as compare-engines)

```bash
php benchmarks/run.php --cases=fork_flat_vs_striped --workers=4,8
```

### Multi-process correctness with Striped

```bash
FAST_MP_STRIPES=8 php tests/mp_parallel_stress.php
```

---

## Related docs

- [`engine-architecture.md`](engine-architecture.md) — segments, locks, allocation
- [`specification.md`](specification.md) — behavior contract and stress acceptance
- [`benchmarks/README.md`](../benchmarks/README.md) — benchmark suite index
