# ADR 0003: Choose an append-only `fast` storage model

Status: Proposed — **public-surface list superseded (see amendment)**

> **Amendment (2026-06-29): the public API listed below is historical.** This ADR
> predates `docs/specification.md`. The direct methods it cites as context
> (`get`, `set`, `has`, `delete`, `setMany`, `deleteMany`, `transform`, `count`,
> `removeSegment`, `setPermanent`, `getAlloc`) are **not** the public surface;
> the authoritative face is `ArrayAccess` + magic + `Iterator` + `count()` +
> `each()` + `close()`/`destroy()` only (specification §3, enforced by
> `Fast/test/public_surface.php`). The **append-only storage model decision** in
> this ADR (live directory + separate order chain + explicit reclaim/compaction)
> still stands; only the enumerated API surface is obsolete.

## Context

The `fast` effort is a clean-slate rearchitecture. The prior prototype was removed because it
drifted back toward legacy `share` thinking.

The public API is already constrained by the project surface:

- `ArrayAccess`
- `Iterator`
- magic methods
- direct methods such as `get`, `set`, `has`, `delete`, `setMany`, `deleteMany`, `transform`,
  `count`, `removeSegment`, `setPermanent`, and `getAlloc`

The remaining decision is the internal storage model. The goal is to reduce work on the hot path
by organizing data so common operations do less translation, less branching, and less rebuilding.

## Decision

Use an append-only log with:

- a live-entry directory
- a separate order chain for iteration
- explicit reclaim/compaction for obsolete records

The model is:

- **write new state**
- **publish metadata last**
- **keep identity and payload separate**
- **keep iteration metadata separate from payload**
- **reclaim later, explicitly**

This model is chosen because it best fits the current usage shape:

- `get` and `has` should hit a live-entry directory, not decode payload structure
- `set` should append and publish, not rewrite unrelated data in place
- `delete` should retire a live entry and leave reclaim to compaction
- iteration should walk order metadata, not infer order from payload records
- batch mutation should reuse the same append-and-publish discipline

## Alternatives Considered

### 1. Fixed slot table with generation stamps

Rejected for the first `fast` model.

This can be a valid design, but it is less flexible for append-heavy workloads and gives less room
to separate write-time publication from reclaim/compaction.

### 2. LSM-style multi-level sorted runs

Rejected for the first `fast` model.

It is a strong storage design in general, but it adds compaction and read-amplification machinery
that is more than the current `fast` scope needs.

### 3. B-tree or page-tree model

Rejected for the first `fast` model.

It is better suited to range-heavy tree maintenance than to the current goal of reducing per-op
translation and branching.

### 4. Append-only log with live directory and order chain

Accepted.

This gives the cleanest route to:

- short hot paths
- explicit metadata
- simple publication rules
- explicit compaction
- a direct fit for the current PHP/shared-memory usage surface

## Consequences

### Positive

- Writes become sequential and easy to reason about.
- Reads can stay shallow if the directory is compact.
- Iteration is explicit and stable.
- Deletes and overwrites become metadata updates plus later reclaim.
- The model supports moving complexity out of the common path.

### Negative

- Compaction becomes a real responsibility.
- The design needs a careful metadata layout.
- The first version must balance directory size, order metadata, and reclaim policy.
- The implementation must be disciplined about not turning the log into a hidden rewrite engine.

## Implementation Plan

### Affected paths

- future `Fast.php`
- future `Fast/` runtime support files
- future `tests/` contract harnesses
- `memory-bank/creative/creative-share-fast-v1.md`
- `memory-bank/creative/creative-share-fast-interface-v1.md`
- `memory-bank/creative/creative-share-fast-poc-plan-v1.md`

### Storage layout requirements

- Append-only record frames for new writes.
- A live-entry directory that maps keys to the latest visible record.
- Order metadata that supports insertion-order iteration and `seek`.
- Tombstones or equivalent invalidation markers for deletes.
- Explicit reclaim/compaction for obsolete records and dead directory entries.

### Operational rules

- `has` must not require payload decode.
- `get` must not require rebuilding structure.
- `set` must publish the new record atomically after the append.
- `delete` must retire the live entry and leave reclaim to compaction.
- `setMany` must reuse the same append-and-publish discipline.
- `transform` must follow the same storage rules as other mutations.

### Suggested build sequence

1. Define the record frame format.
2. Define the live-entry directory entry format.
3. Define the order-chain metadata.
4. Define tombstone and reclaim rules.
5. Define publication order and generation/update semantics.
6. Implement a minimal `Fast` around the chosen layout.
7. Add contract tests for the public API surface.
8. Add the canonical stress benchmark (`tests/stress_100k_bench.php`).

### Tests and verification

- `php tests/run.php`
- `php tests/stress_100k_bench.php`
- a dedicated `fast` comparison benchmark once the runtime exists

## Verification Criteria

- [ ] The append-only model is documented clearly enough for a later agent to implement it.
- [ ] `get` and `has` can be satisfied from the directory without payload decoding.
- [ ] `set` appends new data and publishes metadata last.
- [ ] `delete` leaves reclaim to explicit compaction.
- [ ] Iteration uses separate order metadata.
- [ ] No `fast` runtime code depends on `share` internals.
- [ ] The comparison oracle remains available through tests and benchmarks only.

## Notes

This ADR chooses the storage model. It does not yet choose the exact binary encoding for the log,
directory, or order chain. Those details belong in the next implementation brief.
