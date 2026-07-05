# Pinned research baselines

JSON artifacts referenced by docs and tests.

| File | Used by |
|------|---------|
| [`stress-100k-baseline.json`](stress-100k-baseline.json) | `tests/stress_gate.php`, `docs/specification.md`, `docs/performance.md` |
| [`harness-current-baseline.json`](harness-current-baseline.json) | `docs/design-study.md` §9a (historical control matrix snapshot) |
| [`experiments/01-allocator.json`](experiments/01-allocator.json) | Design study §9b |
| [`experiments/02-index.json`](experiments/02-index.json) | Design study §9c |
| [`experiments/03-rehash.json`](experiments/03-rehash.json) | Design study §9d |
| [`experiments/04-crash.json`](experiments/04-crash.json) | Design study §9e |

Re-running an experiment may write a new timestamped file in the working
directory. Copy the result here only when intentionally updating the pinned
reference.
