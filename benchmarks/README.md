# Benchmarks

## Start here: Flat vs Striped

If you are deciding whether to enable `stripes`, run:

```bash
php benchmarks/compare-engines.php
```

That runs **the same workloads** on Flat and Striped and prints a table with
writes/sec and a ratio. It is the evidence behind the guidance in
[`docs/performance.md`](../docs/performance.md).

Scenarios:

1. **Many writers, spread keys** — Striped’s intended job
2. **Many writers, one counter** — Striped should not win
3. **Single writer, spread keys** — Flat’s default; Striped is optional

## Full benchmark suite

```bash
php benchmarks/run.php              # default
php benchmarks/run.php --quick      # faster smoke
php benchmarks/run.php --full       # longer, larger N
php benchmarks/run.php --cases=fork_flat_vs_striped --workers=4,8
```

Results land in `benchmarks/results/` and `benchmarks/reports/`.

## Stress and research

| Script | Purpose |
|--------|---------|
| `tests/stress_100k_bench.php` | Canonical integrity + throughput stress |
| [`research/experiments/09-lock-striping/run.php`](../research/experiments/09-lock-striping/run.php) | Lock model that motivated Striped |

See [`research/README.md`](../research/README.md) for the full experiment index.

## Requirements

- PHP 8.3+, `igbinary`, `shmop`, `sysvsem`
- `pcntl` for fork-based comparisons
