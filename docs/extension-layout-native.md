# Native wire format (LAYOUT_EXT)

**Audience:** extension authors and advanced debugging. App developers can ignore this.

**Status:** specification for native ext-fast stores.  
**Default when `fast.compat=0`.** Not readable by pure-PHP Fast.

Compat format: [`extension-compat.md`](extension-compat.md).

---

## Identity

| Field | Value |
|-------|-------|
| Magic | `XFST` (4 bytes, segment 0 offset 0) |
| Layout version | `1` (u32 @ offset 4) |
| Store name hash | 16 bytes @ offset 320 (xxh128 of UTF-8 name; collision guard) |
| Lock / key prefix | `fast-native-*` (SysV key derivation + flock path — never `fast-flat-*`) |

Opening a native store with pure PHP, or a PHP store without `fast.compat=1`, must fail with an explicit layout error.

---

## Backing store

POSIX shared memory per named store:

```
shm_open(name) → ftruncate → mmap(MAP_SHARED)
```

Grow: `ftruncate` larger + remap (preserve live bytes).  
Shrink: compact live records toward base, truncate trailing pages, `munmap` released regions.

Local mode (no `name`): in-process only — Zend array + insertion-order iteration (Phase 0 ext behavior).

---

## Segment 0 fixed region

```
offset 0:     header (1024 bytes)
offset 1024:  directory (bucket table)
offset D:     order log
offset O:     value arena start (frontier begins here)
```

Header fields (native v1):

| Offset | Size | Field |
|--------|------|-------|
| 0 | 4 | magic `XFST` |
| 4 | 4 | layout_version |
| 8 | 4 | seqlock sequence (even=stable) |
| 12 | 4 | live count |
| 16 | 4 | tombstone count |
| 20 | 4 | order log write cursor |
| 24 | 8 | arena frontier |
| 32 | 4 | directory bucket count (power of 2) |
| 36 | 4 | persistent flag |
| 64 | 256 | free-list heads (32 classes × u64) |
| 320 | 16 | name xxh128 |
| 576 | 8 | sum live block capacities (compaction trigger) |
| 584 | 4 | order entry size (8 or 16) |

---

## Directory (Phase 1 implementation)

Phase 1 ships **32-byte open-address slots** under XFST magic (same slot geometry as
compat/PHP body). Bucket-fp directory (below) is Phase 1.5.

Design-law target — bucket-fp:

Per entry (8 bytes):

| Byte | Field |
|------|-------|
| 0 | fingerprint (1 byte, xxh3 key tag) |
| 1 | state (empty/live/tomb) |
| 2–3 | reserved |
| 4–7 | slot index OR inline metadata |

Bucket probe: linear bucket chain (not 32-byte open-address slots).

Key bytes live in arena only; directory never stores full keys.

**Rehash:** incremental dual-table migration (design-law §2), K=8–16 buckets per op.

---

## Arena record

No separate record header in arena. Allocation = key bytes || value bytes.

Value encoding (type byte in directory side table or packed in bucket extension):

| Type | Encoding |
|------|----------|
| null | empty payload |
| bool | 1 byte |
| int | i64 LE |
| float | f64 LE |
| string | raw bytes |
| other | igbinary (via PHP `igbinary_*` in v1) |

Allocator: 32 size classes, power-of-two blocks, min 16 bytes (same family as PHP Flat).

Compaction: bounded foreground copy when `arena_used >= 2 × max(live_caps, 16)` and trailing segments exist.

---

## Order log

Same semantics as spec: append (slot_index, generation) on insert/reinsert; skip stale on iterate; compact in place when cursor exhausts slot budget.

| Mode | Entry size |
|------|------------|
| Flat native | 8 bytes: u32 slot, u32 gen |
| Striped native | 16 bytes: u32 slot, u32 gen, u64 hrtime tag |

---

## Concurrency (native)

| Platform | Readers | Writers |
|----------|---------|---------|
| x86_64 | seqlock + C11 atomics on header sequence | SysV semaphore |
| ARM64 v1 | semaphore only (ADR 005) | SysV semaphore |

No `FAST_LOCKFREE` env override in native mode.

---

## Striped native

N independent native Flat sub-stores. Route: high bits of xxh3(key) % N.

Total `capacity` / `size` split evenly. Global iteration: merge order logs by hrtime tag.

---

## Phase map

| Phase | Native deliverable |
|-------|-------------------|
| 0 | Local mode only (no shm) |
| 1 | Shared native core (directory, arena, order, get/set/has/delete/count/iterate) |
| 2 | Lifecycle, flock, crash recovery |
| 4 | Native Striped |
