# ADR 0004: Choose a frame format for `fast` records

Status: Proposed — **public-surface list superseded (see amendment)**

> **Amendment (2026-06-29): the public API listed below is historical.** This ADR
> predates `docs/specification.md`. The direct methods it cites as context
> (`get`, `set`, `has`, `delete`, `setMany`, `deleteMany`, `transform`, `count`,
> `removeSegment`, `setPermanent`, `getAlloc`) are **not** the public surface;
> the authoritative face is `ArrayAccess` + magic + `Iterator` + `count()` +
> `each()` + `close()`/`destroy()` only (specification §3, enforced by
> `Fast/test/public_surface.php`). The **record frame-format decision** in this
> ADR (compact length-prefixed frame + separate order node + live directory entry,
> publish-last) still stands; only the enumerated API surface is obsolete.

## Context

ADR 0003 selected an append-only log with a live-entry directory and a separate order chain.
That decision still leaves one critical question open: what does one record look like on the wire?

This matters because the frame format determines how much work each operation must do.
If the format is poor, the implementation will need extra translation, extra decoding, extra
branching, and extra repair work to make the public API behave correctly.

The public `fast` surface already includes:

- `ArrayAccess`
- `Iterator`
- magic methods
- direct methods such as `get`, `set`, `has`, `delete`, `setMany`, `deleteMany`, `transform`,
  `count`, `removeSegment`, `setPermanent`, and `getAlloc`

The frame format must support those operations with as little per-operation reconstruction as
possible.

## Decision

Use a compact length-prefixed frame format for `fast` records.

### Record frame

Each stored record is written as:

- fixed header
- normalized key bytes
- normalized value bytes

The fixed header contains:

- magic
- version
- record kind
- key kind
- flags
- key length
- value length
- generation

### Order node

Iteration metadata is stored separately from the payload frame.

Each order node contains:

- record offset
- next-node offset

The order node is append-only as well. The live-entry directory points at the current record and
its order node.

### Live directory entry

The live-entry directory stores, at minimum:

- record offset
- order-node offset
- generation
- key kind
- flags / live state

This keeps `has` and `get` shallow: they can consult directory metadata without decoding the
payload frame.

## Why this format

The chosen frame format is meant to make the common operations cheap:

- `has` should use directory metadata only
- `get` should find the latest record and decode only the payload it needs
- `set` should append a new frame, append a new order node, then publish the directory entry last
- `delete` should retire the live entry and leave reclamation to explicit compaction
- iteration should use the order chain rather than inferring order from payload layout

This format also keeps the representation stable for PHP values without forcing the public API to
become a serialization wrapper.

## Alternatives Considered

### 1. Fixed-width slots

Rejected for the first `fast` format.

Fixed slots can simplify offsets, but they waste space and make variable-sized PHP values harder to
store without a lot of padding or additional indirection.

### 2. Serialized blobs with no explicit frame header

Rejected.

That would push too much work back onto decode-time reconstruction and make `has` / iteration less
clean than they need to be.

### 3. A combined record-and-order structure

Rejected for now.

Mixing order metadata into the payload frame makes the frame heavier than necessary and couples two
different concerns that the architecture has already decided to keep separate.

### 4. Compact length-prefixed record frame plus separate order node

Accepted.

This gives the best match to the current goals:

- minimal work on the hot path
- clear separation between identity, payload, and order
- explicit publication rules
- explicit reclaim path

## Consequences

### Positive

- Variable-sized PHP values fit naturally.
- The format keeps `has` and `get` shallow.
- Order metadata is explicit and independent from the payload layout.
- Reclaim/compaction can operate on whole frames and nodes without inventing new special cases.

### Negative

- The implementation must manage two append-only structures: record frames and order nodes.
- The directory entry format becomes important and must stay consistent.
- Compaction must understand both the payload log and the order chain.

## Implementation Plan

### Affected paths

- future `Fast.php`
- future `Fast/` runtime support files
- future `tests/` comparison tests
- `memory-bank/creative/creative-share-fast-v1.md`
- `memory-bank/creative/creative-share-fast-interface-v1.md`
- `memory-bank/creative/creative-share-fast-poc-plan-v1.md`

### Frame rules

- Record frames are append-only.
- Header fields are fixed-width and decode first.
- Key bytes are normalized before writing.
- Value bytes are stored in the payload body.
- Order metadata is stored separately in order nodes.
- The directory entry must publish the new frame and node only after both have been written.

### Suggested build sequence

1. Define the binary header constants.
2. Define the normalized key encoding.
3. Define the value encoding policy.
4. Define the order node shape.
5. Define the live directory entry shape.
6. Implement a minimal `Fast` around these frame rules.
7. Add contract tests for the public API surface.
8. Add the canonical stress benchmark (`tests/stress_100k_bench.php`).

### Verification criteria

- [ ] The record frame format is documented clearly enough for a later agent to implement it.
- [ ] The header is fixed-width and can be decoded before payload parsing.
- [ ] The live directory can answer `has` without decoding payload bytes.
- [ ] The order chain is separate from the payload frame.
- [ ] The publication order is append frame, append order node, publish directory entry.
- [ ] No `fast` runtime code depends on `share` internals.
- [ ] The oracle comparison remains available through tests and benchmarks only.

## Notes

This ADR locks the record format, not the compaction algorithm. Compaction may be chosen later as
long as it respects the frame rules and the separate order chain.
