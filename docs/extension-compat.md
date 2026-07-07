# Compat wire format (LAYOUT_PHP)

**Audience:** when PHP and ext-fast must share one named store. Enable
`fast.compat=1` on both sides.

**Status:** byte-compatible with pure-PHP `Fast\Engine\Flat` layout v1.  
**Enabled when `fast.compat=1` (INI or constructor).**

Native format: [`extension-layout-native.md`](extension-layout-native.md).  
Narrative: [`engine-architecture.md`](engine-architecture.md).

---

## Identity

| Field | Value |
|-------|-------|
| Magic | `FLT2` |
| Layout version | `1` (`Flat::LAYOUT`) |
| SysV key prefix | `fast-flat-*` (same as PHP) |
| Lock files | Same flock paths as PHP engine |

Interop requirement: PHP process and ext-fast process with compat enabled on the **same named store** must read/write identical bytes without translation.

---

## Segment geometry

Segment 0:

```
[ 1024-byte header ]
[ directory: slotCount × 32 bytes ]
[ order log: slotCount × orderBytes ]
[ arena from frontier base ... ]
```

Additional segments: arena spill only. Blocks never cross segment boundary.

Constants (from `Flat.php`):

| Constant | Value |
|----------|-------|
| `HEADER` | 1024 |
| `SLOT` | 32 |
| `ORDER` | 8 (untagged) |
| `ORDER_TAGGED` | 16 (Striped) |
| `ALLOC_MIN` | 16 |
| `FREE_CLASSES` | 32 |

---

## Header offsets

| Offset | Name | Size |
|--------|------|------|
| 0 | `H_MAGIC` | 4 |
| 4 | `H_LAYOUT` | 4 |
| 8 | `H_SEQ` | 4 |
| 12 | `H_LIVE` | 4 |
| 16 | `H_TOMB` | 4 |
| 20 | `H_ORDER` | 4 |
| 24 | `H_FRONTIER` | 8 |
| 32 | `H_SLOTS` | 4 |
| 36 | `H_PERSIST` | 4 |
| 64 | `H_FREEHEADS` | 256 (32 × u64) |
| 320 | `H_NAMEHASH` | 16 |
| 576 | `H_LIVECAPS` | 8 |
| 584 | `H_ORDERSZ` | 4 |

---

## Directory slot (32 bytes)

| Bytes | Content |
|-------|---------|
| 0–7 | primary key hash tag |
| 8–11 | record offset (u32) |
| 12–15 | generation (u32) |
| 16–19 | value length (u32) |
| 20–21 | key length (u16) |
| 22 | state (`ST_EMPTY=0`, `ST_LIVE=1`, `ST_TOMB=2`) |
| 23 | key type + value type packed |
| 24–31 | confirm key hash tag |

Probe: `(hash & (slotCount-1))` linear.

---

## Value types

| Constant | Value |
|----------|-------|
| `TYPE_NULL` | 0 |
| `TYPE_BOOL` | 1 |
| `TYPE_INT` | 2 |
| `TYPE_FLOAT` | 3 |
| `TYPE_STRING` | 4 |
| `TYPE_IGBINARY` | 5 |

Scalar fast path + igbinary for other shapes — same as PHP engine.

---

## Concurrency (compat)

Match PHP engine:

- Writer: SysV semaphore, full critical section.
- Reader x86_64: seqlock on `H_SEQ` with TSO assumption; env `FAST_LOCKFREE` honored for testing parity.
- Reader ARM: semaphore path only in PHP; ext compat matches PHP behavior on each platform.

---

## Striped compat

Port of `src/Engine/Striped.php`:

- N × independent Flat compat sub-stores.
- Key route: xxh3 high bits % N; inner hash per stripe.
- Order log 16-byte tagged entries; global foreach merges by hrtime.

---

## Implementation note

Phase 3: implement `flat_compat.c` as a literal port of `Flat.php` hot paths — do not “improve” layout in compat mode. Improvements belong in `LAYOUT_EXT` only.

---

## Verification

| Test | Purpose |
|------|---------|
| `tests/interop_php_ext.php` (new) | Mixed-process read/write |
| `tests/stress_100k_bench.php` | Integrity on compat store |
| `tests/run.php` with `FAST_BACKEND=ext` | Full contract (post Phase 5) |
