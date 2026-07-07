# How Fast stores data

This document explains **where bytes live** and **how locks work**. You do not
need it to use Fast day to day.

Read [`specification.md`](specification.md) for public behavior. Read this file
when you are debugging storage, crash recovery, or comparing the PHP engine to
ext-fast.

**In one sentence:** Fast keeps a hash table in shared memory and exposes it as a
PHP array; reads and writes copy data between PHP and that memory.

The layout version is `LAYOUT = 1` in `Flat.php`. If that number changes, this
document and the on-disk format change together.

---

## The basic idea

Fast keeps a hash table in shared memory and makes it look like a PHP array. Every
read and write crosses the PHP/shmop boundary, so the engine tries to touch as few
bytes as possible on each operation.

The cost model comes from `research/experiments/05-primitives/run.php`. On a typical Linux box,
a small `shmop_read` or `shmop_write` is on the order of tens to low hundreds of
nanoseconds. `unpack()` on a multi-field blob costs more. The layout is built around
that: compare keys as raw bytes, read one directory slot and one record blob on a
hit, and avoid extra indirection tables.

A warm `get` on a shared store is roughly: hash the key, read one 32-byte slot,
compare two 8-byte tags, read the value bytes, decode. No separate record header
walk, no id table chase.

---

## Where things live in memory

Shared storage uses SysV `shmop` segments. Each segment has a fixed size when
created; you grow by opening another segment, not by resizing one.

Segment 0 holds everything fixed at the front of the store:

```
[ 1024-byte header ]
[ directory: slotCount × 32 bytes ]
[ order log: slotCount × 8 or 16 bytes per possible entry ]
[ value arena begins here ... ]
```

The arena continues into segment 1, 2, and so on as the store fills. Blocks never
straddle a segment boundary so a read stays inside one `shmop_read` when possible.

Local mode (no store name) skips all of this and uses a PHP array inside the
process. Same public API, different engine path.

---

## The directory

The directory is an open-addressing hash table. Each slot is 32 bytes and holds
everything needed to find a key without opening the record first:

* two 8-byte hashes of the normalized key (primary tag at byte 0, confirm tag at
  byte 24)
* record offset in the arena, generation, value length, key length
* state (empty, live, tombstone) and type bytes for key and value

Probe starts at `hash & (slotCount - 1)` and walks forward until empty or match.
Slot count must be a power of two so the mask is cheap.

When a key is deleted the slot becomes a tombstone. A later insert can reuse that
slot; the generation increments so old order-log entries for that slot index are
ignored during iteration.

The directory size is fixed at create time. If every slot is live or tombstoned and
nothing matches, insert fails with a clear error asking for more `capacity`.

---

## Records in the arena

There is no separate on-disk record header in the arena. An allocation is simply
key bytes followed by value bytes, packed together. The directory slot already knows
the lengths and types.

Values use small fast paths where possible: null, bool, int, float, and raw string
skip igbinary. Everything else goes through igbinary. The spec requires that
extension.

On insert the engine allocates a power-of-two-sized block from the size-class free
lists (minimum 16 bytes, 32 classes). It writes key+value, then publishes the slot.

On update, if the new payload fits in the existing block size class, it overwrites
in place and patches only the length and type bytes in the slot. If the value
grows past the class, it allocates a new block, copies, frees the old one, and
updates the slot offset.

---

## Allocation and freeing

New space comes from two places.

First, the free lists. Each size class has a head pointer in the segment header.
`free()` pushes a block onto the matching list; `alloc()` pops if available.

Second, the frontier pointer. If no free block fits, the engine bumps the frontier
by the rounded-up size. Before advancing into a new segment index it opens that
segment (or throws `ShmExhaustedException` if the host will not grant more shared
memory).

Deletes mark the slot tombstone, push the block onto the free list, and adjust live
counts. Freed bytes accumulate in a local `dirtyBytes` counter.

Compaction is not constant. After enough churn, if the store has spilled past
segment 0 and the arena holds roughly twice as much dead space as live data, the
engine compacts under the write lock: copy all live records tightly from the arena
base, patch slot offsets and the order log, reset the frontier, and clear the free
lists (they would be stale anyway). When this process is the only one attached, it
can also delete trailing growth segments and give RAM back to the OS.

---

## Insertion order and iteration

PHP expects `foreach` to walk keys in insertion order. Fast keeps an append-only
order log sitting between the directory and the arena. Each entry is a slot index
plus that slot's generation. On `rewind`, the engine walks the log and skips entries
whose slot is not live or whose generation no longer matches (stale after reuse).

Reinserting a key that was deleted gets a new generation and a new log entry at the
end, so it shows up last in iteration, which matches ordinary array behavior.

The log is bounded by slot count in the sense that you cannot have more live keys
than slots. Under heavy delete+reinsert churn the log can fill with stale entries;
then the engine compacts the log in place, keeping only entries that still point at
live slots.

---

## Concurrency

One SysV semaphore serializes writers. Readers on x86 try a seqlock first: read the
4-byte sequence in the header, if it is even probe the directory, read the sequence
again, accept only if unchanged. An odd sequence means a write is in progress; retry
a few times, then fall back to a locked read.

PHP and shmop expose no memory fences. The lock-free path is only enabled on
Total-Store-Order CPUs (typical x86/x86_64). On ARM and similar, reads go through
the semaphore so the syscall provides the barrier. Override with `FAST_LOCKFREE`
if you know what you are doing.

Writes bump the sequence odd at the start of the critical section and even again at
the end. Slot and arena updates happen inside that window.

---

## Attach, crash recovery, lifecycle

Opening a named store attaches segment 0, checks magic `FLT2`, layout version, and a
16-byte name fingerprint (the segment key is only crc32, so collisions are possible
and must be rejected loudly).

Each connected process holds a shared `flock` on a small lock file for that store.
When a process dies the kernel releases its lock. No PID table to maintain.

Non-persistent stores: if attach finds no other live holders, it deletes orphaned
segments and starts fresh.

If the sequence is odd at attach, a writer was probably killed mid-update. The next
attacher takes the lock, rebuilds header counters from the slot table, quarantines
slots that fail hash validation, and repairs the order log. Value bytes torn during
an in-place overwrite are not checksum-protected; that remains a documented edge.

`close()` drops this process's link. `destroy()` removes segments and the lock file
but only when no other process is connected (unless an internal forced path used by
Striped teardown).

---

## Striped mode

`Striped` is optional via `stripes` in the constructor config. It builds several
independent `Flat` sub-stores, each with its own segment, semaphore, and allocator.
Total `capacity` and `size` are split evenly across stripes.

Keys route to a stripe from the high bits of an xxh3 hash. Each stripe still hashes
inside its own directory with a different function, so stripe choice does not clump
keys inside a stripe.

Write throughput scales when many processes write different keys spread across
stripes. It does not help (and costs a small routing tax) when traffic hammers one
key or a small hot set, because that key always lands on one stripe and one lock.

Striped stores use a 16-byte order log entry with an hrtime tag on each insert.
Global iteration merges the stripe cursors by tag, so order is strict for a single
writer and only fuzzes slightly when inserts race across stripes.

---

## What to read next

* Behavior and API law: `specification.md`
* Why experiments picked certain mechanisms: `design-study.md`
* Byte offsets and constant names: `src/Fast/Flat.php` (search for `H_`, `SLOT`,
  `ORDER`, `ST_`, `TYPE_`)
* Striped routing and merge: `src/Fast/Striped.php` class docblock

Older docs under `archive/structural-audit.md`, `archive/efficiency-audit.md`, and some ADRs
still mention `Shared.php`, `Journal.php`, and `Format.php`. Those files are gone;
`Flat` replaced that stack. Trust this file and the source for the current layout.
