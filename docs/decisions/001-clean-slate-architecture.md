# ADR 0001: Choose a clean-slate `fast` architecture

Status: Accepted (amended 2026-06-30 for standalone `johnnyjoy/fast` repo)

> **Amendment.** Fast is now a standalone package. The behavioral oracle is
> `docs/specification.md` and the contract tests — not the legacy `share` store.
> Runtime code must not depend on `share`. Comparison harnesses against `share` are
> out of scope for this repository.

## Context

A prior prototype absorbed too much legacy framework thinking and drifted toward
wrappers, forwarding, and inherited shapes.

The question is:

> If we started over with no legacy code or inherited implementation thinking, what architecture
> would we build for the same public behavior?

The specification and contract tests are the behavioral oracle.

## Decision

Build `fast` as a clean-slate architecture with no runtime coupling to other stores.

`fast` must:

- implement the public behavior defined in the specification
- keep its own storage, mutation, traversal, and lifecycle decisions
- avoid runtime dependencies on foreign store internals, subclasses, or forwarding

This ADR explicitly rejects a shadow/wrapper approach as the primary architecture.

## Alternatives Considered

### 1. Keep a share-shaped prototype and incrementally diverge it

Rejected.

Reason: the earlier prototype repeatedly drifted back toward the legacy design. The wrapper and
subclass paths proved too easy to keep around, which defeats the clean-slate goal.

### 2. Build `fast` as a blank-slate backend with the same public surface

Accepted.

Reason: this is the only option that keeps the new architecture independent while still allowing
behavioral comparison against the oracle.

## Consequences

### Positive

- The new architecture can choose its own storage model and primitive operations.
- The specification and contract tests are the behavioral oracle.
- The codebase has a clear boundary between public contract and engine implementation.

### Negative

- The `fast` work now requires a real backend design instead of a wrapper.
- Early code may look less convenient than the legacy shape because it must not borrow legacy
  internals.
- The comparison harness must remain separate from runtime code.

## Implementation Plan

### Affected paths

- `memory-bank/creative/creative-share-fast-v1.md`
- `memory-bank/creative/creative-share-fast-interface-v1.md`
- `memory-bank/creative/creative-share-fast-poc-plan-v1.md`
- future `Fast/` runtime files
- future `tests/` contract and stress harnesses

### Constraints for the next implementation

- No `Fast` runtime code may import or call foreign store internals.
- The first implementation should choose one explicit storage model and one explicit traversal model.
- The implementation should keep the control plane small and avoid reintroducing legacy special cases.

### Suggested build sequence

1. Define the storage primitive for `fast`.
2. Define the key identity primitive.
3. Define the traversal primitive.
4. Define lifecycle and capacity behavior.
5. Implement one minimal `Fast` runtime.
6. Add contract tests for null handling, missing-key reads, iteration order, `seek`, and batch
   mutation.
7. Add the canonical stress benchmark (`tests/stress_100k_bench.php`).

### Tests and verification

- `php tests/run.php`
- `php tests/stress_100k_bench.php`

## Verification Criteria

- [ ] `fast` runtime code does not depend on foreign store internals.
- [ ] Contract tests prove observable behavior for the targeted surface.
- [ ] Stress benchmark reports integrity fields all zero on a good run.

## Notes

This ADR is intentionally narrow. It decides the shape of the `fast` effort, not the specific
storage engine. The next ADR or design brief must choose that engine explicitly.
