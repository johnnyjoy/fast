# Research

Decision trail for Fast — not shipped API. Each entry answers: **what were we
deciding**, **how to reproduce**, **what we concluded**, **where it landed**.

Narrative and results live in [`docs/design-study.md`](../docs/design-study.md).
Contract tests and production benchmarks live in `tests/` and `benchmarks/`.

```bash
# From repo root; Linux + igbinary/shmop/sysvsem
php research/experiments/05-primitives/run.php 100000
php research/experiments/09-lock-striping/run.php
```

---

## Layout

| Directory | Purpose |
|-----------|---------|
| [`experiments/`](experiments/) | Numbered design-study bake-offs (algorithm-level or engine-level) |
| [`spikes/`](spikes/) | Time-boxed platform probes still cited in docs |
| [`baselines/`](baselines/) | Pinned JSON outputs referenced by docs and gates |

Retired artifacts (superseded prototypes, incremental ablations, scratch
archives, one-off harnesses) were removed; their conclusions remain in
[`docs/design-study.md`](../docs/design-study.md). For regression numbers use
[`benchmarks/`](../benchmarks/) and [`docs/performance.md`](../docs/performance.md).

---

## Experiments

| ID | Run | Question | Conclusion (short) | Shipped in |
|----|-----|----------|-------------------|------------|
| 01 | [`experiments/01-allocator/run.php`](experiments/01-allocator/run.php) | Allocator + shrink | Size-class slab + incremental compaction | `Flat` arena; compactor in `src/Engine/Flat.php` |
| 02 | [`experiments/02-index/run.php`](experiments/02-index/run.php) | Directory layout | Bucket-fp beats open-linear-40 | Directory slots in `Flat` |
| 03 | [`experiments/03-rehash/run.php`](experiments/03-rehash/run.php) | Rehash under load | Incremental K-bucket migration | Fixed directory size at create |
| 04 | [`experiments/04-crash/run.php`](experiments/04-crash/run.php) | Crash safety | Real `kill -9` + seqlock validation | Lifecycle in `Flat` |
| 05 | [`experiments/05-primitives/run.php`](experiments/05-primitives/run.php) | Primitive cost floor | 1 slot read + 1 record read target | Cited in `docs/engine-architecture.md` |
| 09 | [`experiments/09-lock-striping/run.php`](experiments/09-lock-striping/run.php) | Global lock vs stripes | Striped for spread-key MP writes | `src/Engine/Striped.php` |

Experiments 06–08 and 10–15 (micro ablations, rejected caches, facade tax) were
retired — outcomes are captured in the design study and in `src/Engine/`.

Pinned outputs for experiments 01–04: [`baselines/experiments/`](baselines/experiments/).

---

## Spikes

| Spike | Run | Notes |
|-------|-----|-------|
| FFI / POSIX shm | [`spikes/ffi-shm/run.php`](spikes/ffi-shm/run.php) | Platform feasibility; shipping uses SysV `shmop` only |

Name-collision and fork-lifecycle probes were retired; guard behavior lives in
`tests/name_collision_guard.php` and lifecycle tests under `tests/`.

---

## Baselines

See [`baselines/README.md`](baselines/README.md). Stress gate:
[`baselines/stress-100k-baseline.json`](baselines/stress-100k-baseline.json).
