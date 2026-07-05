# Fast documentation

`\Fast` is an array-like shared-memory store for PHP. These files describe
what it must do, how the engine stores data, and the research behind the design.

**New to the library?** Read [`specification.md`](specification.md) for behavior
and API rules. If you are choosing Flat vs Striped, read [`performance.md`](performance.md)
first — it compares both engines on the same measured tasks.

---

## Core documents

| Document | What it covers |
|----------|----------------|
| [`specification.md`](specification.md) | **The contract.** Public behavior, lifecycle, concurrency rules, acceptance bar. When anything disagrees, this wins. |
| [`performance.md`](performance.md) | **Flat vs Striped.** Same workloads, both engines, measured writes/sec and when to enable `stripes`. |
| [`engine-architecture.md`](engine-architecture.md) | **Current engine.** How `Flat` and `Striped` lay out segments, the directory, arena allocation, locks, and crash recovery. |
| [`design-law.md`](design-law.md) | Approved implementation direction (yields to the specification). |
| [`guiding-principles.md`](guiding-principles.md) | Operating doctrine: hot path first, bounded maintenance, research grounding. |
| [`design-study.md`](design-study.md) | Clean-room research narrative (results and decisions). |
| [`../research/README.md`](../research/README.md) | **Research index** — experiment scripts, spikes, prototypes, pinned baselines. |

## Architecture decisions

Founding ADRs live in [`decisions/`](decisions/). Several predate the Flat rewrite;
each carries an amendment banner where the public API list is obsolete.

## Archive

[`archive/`](archive/) holds historical engineering notes from before the Flat
engine (`Shared.php`, `Journal.php`, `Format.php`). Useful for context, not as a
description of current code. See [`engine-architecture.md`](engine-architecture.md)
for the live layout.

---

## I need to…

| Goal | Read |
|------|------|
| Choose Flat vs Striped with numbers | [`performance.md`](performance.md) + `php benchmarks/compare-engines.php` |
| Know what Fast must do | [`specification.md`](specification.md) |
| Understand storage and allocation | [`engine-architecture.md`](engine-architecture.md) |
| See stress benchmark rules | [`specification.md`](specification.md) (Mission section) |
| See pinned perf numbers | [`research/baselines/stress-100k-baseline.json`](../research/baselines/stress-100k-baseline.json) |
| Understand why a mechanism was chosen | [`design-study.md`](design-study.md) → [`../research/README.md`](../research/README.md) |
| Read founding ADRs | [`decisions/README.md`](decisions/README.md) |

---

## Authority order

When documents disagree:

```text
specification.md          contract (wins)
design-law.md             implementation direction
engine-architecture.md    current on-disk layout
design-study.md           evidence
archive/                  historical snapshots
guiding-principles.md     mindset, not contract
decisions/                history (amended where superseded)
```

If a test and the specification disagree, fix one of them deliberately. The
specification is the behavioral source of truth.

---

## Validation

```bash
composer install
composer lint          # PHPDoc on src/
composer test          # full contract suite
php benchmarks/compare-engines.php   # Flat vs Striped (needs pcntl)
php tests/stress_100k_bench.php   # canonical stress workload
```
