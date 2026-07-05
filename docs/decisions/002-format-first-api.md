# ADR 0002: Choose a format-first `fast` public API

Status: Proposed — **public-surface list superseded (see amendment)**

> **Amendment (2026-06-29): the public surface below is historical and no longer
> accurate.** This ADR predates the contract in `docs/specification.md`. The
> broad direct-method API it lists as context (`get`, `set`, `has`, `delete`,
> `setMany`, `deleteMany`, `transform`, `count`, `removeSegment`, `setPermanent`,
> `getAlloc`) is **not** the public surface anymore. The authoritative public face
> is now small and fixed (specification §3, enforced by
> `Fast/test/public_surface.php`): construction, `ArrayAccess`, magic property
> access, `Iterator`, `count()`, `each()` (named callable only), `close()`,
> `destroy()`, and PHP object magic — and **no** method-style CRUD, `stats()`,
> `compact()`, or engine helpers. The **format-first decision rule** in this ADR
> (organize the binary format around access patterns; keep the hot path short)
> still stands; only the enumerated API surface is obsolete.

## Context

The `fast` effort is a clean-slate rearchitecture, not a wrapper around the legacy `share`
implementation. The public surface must still feel natural to PHP callers and remain compatible
with the behaviors the project already expects.

The intended surface is broader than one interface style. It includes:

- `ArrayAccess`
- `Iterator`
- magic methods such as `__get`, `__set`, `__isset`, and `__unset`
- direct methods such as `get`, `set`, `has`, `delete`, `setMany`, `deleteMany`, `transform`,
  `count`, `removeSegment`, `setPermanent`, and `getAlloc`

The user-visible API is not the decision point. The decision point is how the internal data format
is organized so that this surface can remain small, direct, and cheap to execute.

The primary lesson from the `share` work is that repeated translation, repeated checks, and
shape-shifting on the hot path cost real throughput. The format must do more of the organizing
work up front so the code has less to do per operation.

## Decision

Adopt a format-first decision rule for `fast`, but do not choose the final storage model yet.

That means:

- design the eventual internal storage format around the actual access patterns
- make the common operations cheap by construction
- move work to write-time only when it reduces total work across the workload
- avoid repeated decode/repack/revalidate work on the hot path
- keep the API expressive, but do not let the API force the storage format to become accidental

The public API remains PHP-native and broad, but the storage format will be chosen by the
evaluation criteria below, not by legacy precedent.

## Options Considered

### 1. Keep a generic storage format and adapt per API entrypoint

Rejected.

This recreates the legacy problem: the implementation pays translation costs at each entrypoint,
and the public API becomes a compatibility shell around a costly internal shape.

### 2. Choose the storage model after evaluating the usage surface, maintenance burden, and
performance data

Accepted.

This keeps the design honest: the format is selected because it fits the workload and the
maintenance budget, not because it resembles the old code or because it is convenient to sketch.

## Consequences

### Positive

- Common operations can be one lookup or one append instead of a chain of conversions.
- The public API can stay broad without forcing repeated internal reshaping.
- The implementation can choose a record/index layout that matches `get`, `set`, `has`,
  `delete`, `setMany`, iteration, and compound mutation patterns after evaluation.
- Future performance work focuses on the format rather than patching around it.

### Negative

- The first storage model choice matters more, because format mistakes are expensive.
- The implementation must be disciplined about not reintroducing legacy-style translation layers.
- More of the complexity moves into the design of the binary or logical record format.

## Implementation Plan

### Affected paths

- `memory-bank/creative/creative-share-fast-v1.md`
- `memory-bank/creative/creative-share-fast-interface-v1.md`
- `memory-bank/creative/creative-share-fast-poc-plan-v1.md`
- future `Fast/` runtime files
- future `tests/` harnesses

### Design constraints for the next implementation

- The public surface must support `ArrayAccess`, `Iterator`, magic methods, and direct methods.
- The implementation must not depend on `share` internals at runtime.
- The internal format must keep the common paths short:
  - `has` should not require payload decode
  - `get` should not require reformatting
  - `set` should not require broad reshaping of unrelated data
  - iteration should use explicit traversal metadata
- Any batching or compound operation should share the same format rules rather than inventing a
  separate hidden path.

### Suggested build sequence

1. Define the evaluation criteria for the storage model.
2. Define the workload mix and maintenance constraints that matter.
3. Compare candidate models against those criteria.
4. Define how magic methods map onto the direct methods.
5. Implement one minimal `Fast` using the selected model.
6. Add contract tests for the full surface, including `ArrayAccess`, `Iterator`, and magic methods.
7. Add the canonical stress benchmark (`tests/stress_100k_bench.php`).

### Files that will likely need to be created or replaced

- future `Fast.php`
- `php tests/run.php`
- future format notes under `memory-bank/creative/`

## Verification Criteria

- [ ] The chosen format is documented clearly enough that a later agent can implement it without
      rediscovering the same decision.
- [ ] The public `fast` surface includes `ArrayAccess`, `Iterator`, magic methods, and the named
      direct methods.
- [ ] The internal format is optimized for the common operations rather than generic adaptation.
- [ ] No `fast` runtime code depends on `share` internals.
- [ ] The oracle comparison remains available through tests and benchmarks only.

## Notes

This ADR intentionally does not choose the exact record encoding. It chooses the rule that the
format must be the main organizational tool, and that the storage model must be selected by
evidence from the workload, maintenance burden, and implementation simplicity.
