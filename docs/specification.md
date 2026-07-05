# Fast Specification

Status: authoritative contract for `\Fast`.
This file defines **what Fast is and how it must behave**. Tests encode this
contract. When a test and this spec disagree, one of them is wrong — fix the
disagreement deliberately, do not paper over it.

---

## 0. Mission and acceptance

Fast is **easy-to-use shared memory that behaves like a PHP array** — across
processes, with predictable lifecycle, integrity, and performance.

```text
Fast is a shared-memory map for PHP. Use it like an array; it must stay correct
under concurrency and recover cleanly from process death.
```

### Stress benchmark acceptance

The canonical workload is `tests/stress_100k_bench.php` (100k records, 500k churn
ops, 256 MiB segment budget, fixed seed). A run is acceptable when:

- **Integrity:** records lost = 0, orphan records = 0, errors = 0, warnings = 0,
  and live/segment counts match the workload geometry.
- **Performance:** throughput must not regress beyond policy against the pinned
  baseline in `research/baselines/stress-100k-baseline.json` (see
  `tests/stress_gate.php` for the optional regression gate).

Example geometry (integrity fields from a good run):

```
Fast stress benchmark
records:       100,000
churn ops:     500,000
segment bytes: 268,435,456
seed:          1

inserted (phase 1):    100,000
live after churn:      86,382  (below inserted by design; phase 9 deletes ~10%)
segment count:         86,382
records lost:          0
orphan records:        0
errors:                0
warnings:              0
php peak memory:       reported by harness
```

Acceptance is **integrity first**, then throughput. A faster run that loses a
record, leaks an orphan, emits a warning, or corrupts counts is **not** acceptance.

Progress and pinned numbers live in `research/baselines/stress-100k-baseline.json`
and `docs/archive/continuity-2026-06.md`.

---

## 1. What Fast is

Fast is **easy-to-use shared memory that behaves like a PHP array**.

You make one, you use it like an array, and it works across processes. That is
the entire pitch. Everything in the implementation serves that pitch or it does
not belong.

```php
$cfg = new Fast('app');      // named, shared, multi-process
$cfg['debug'] = true;        // write like an array
if (isset($cfg['debug'])) {  // test like an array
    $x = $cfg['debug'];      // read like an array
}
foreach ($cfg as $k => $v) { /* iterate like an array */ }
count($cfg);                 // count like an array
```

### What Fast is NOT

```text
Fast is not a service object.
Fast is not a key/value repository.
Fast is not a diagnostics dashboard.
Fast is not a design-pattern exercise.

Fast is a PHP array-like shared-memory object.

The shortest correct path wins.
```

All abstractions are costs. Pay only for **ease of use, performance, stability**.
No abstraction survives just because it is "clean," "standard," "separated," or
"traditional." The implementation may be complex. The public object must not be.

## User stories Fast must make obvious

A normal developer should be able to predict what Fast does **without understanding
the machine room**. These stories describe Fast through real uses, not internals.

### Story 1 — Temporary shared runtime state

```php
$state = new Fast('runtime-state');
$state['ready'] = true;
```

Use the default named form for shared runtime state that should exist only while at
least one process is connected.

```text
Default named stores are non-persistent. When the last connected process leaves,
the store is reclaimed.
```

### Story 2 — Persistent named state

```php
$cfg = new Fast(['name' => 'app-config', 'persistent' => true]);
$cfg['debug'] = false;
```

Use `persistent => true` when the named store must survive a period with **zero**
connected processes.

```text
Persistence is opt-in. It controls survival after the final process leaves. It does
not make destroy() safe while others are attached.
```

### Story 3 — Handoff between processes

The failure case (this does **not** preserve data by default):

```php
$a = new Fast('handoff');
$a['job'] = 123;
$a->close();

$b = new Fast('handoff'); // another process opens the same name
```

```text
This does not preserve data by default. If $a was the last connected process, the
non-persistent store was reclaimed at close.
```

The correct forms — make the store persistent:

```php
$a = new Fast(['name' => 'handoff', 'persistent' => true]);
$a['job'] = 123;
$a->close();

$b = new Fast('handoff'); // opens the same name on the surviving store
```

Or keep another process connected across the handoff so the process link count
never reaches zero.

### Story 4 — Safe cleanup

```text
Most users should not call destroy().
For non-persistent stores, normal cleanup is close/destruct/final process exit.
destroy() is an explicit administrative delete and only succeeds when this process
is the sole connected process.
```

`destroy()` is listed in the public face beside `close()`, but it
is **not** ordinary cleanup — see the note in section 3.

### Story 5 — Serialization / sleep / wake

```php
$fast = new Fast('x');
$fast['a'] = 1;
$blob = serialize($fast);
$restored = unserialize($blob);
```

```text
Serialization preserves the Fast handle identity/configuration, not the shared
contents.
```

```text
If the store is non-persistent and this was the last connected process, sleep
destroys the store and wake does not restore the old contents.

If the store is persistent, wake reattaches to the existing contents.
```

### Story 6 — Cheap isset

```php
if (isset($fast['user'])) {
    $user = $fast['user'];
}
```

```text
isset() is a first-class access operation, not a disguised read.
It follows PHP semantics: false for missing and false for stored null.
Fast must treat isset() as a primary operation, not as an afterthought.
```

### Story 7 — Iteration and each

```text
Use foreach for normal array-like iteration.
Use each() only when intentionally performing a grouped writer-excluded pass.
```

`each()` takes a **named callable only** (section 5); closures are rejected.

### Plain rules

```text
Fast('name') is shared and non-persistent.
Fast(['name' => 'name', 'persistent' => true]) survives zero connected processes.

Default named stores die when the last connected process leaves.
Persistent stores survive when the last connected process leaves.

Serialization preserves the handle identity, not the contents.
Sleeping the sole handle of a non-persistent store destroys that store.

Most users should not call destroy().
destroy() is an explicit administrative delete and only works when this process is
the sole connected process.

isset() is a first-class access operation.
foreach is normal iteration.
each() is a named-callable grouped pass under writer exclusion.
```

## 2. Priorities (in order)

1. **Ease of use** — works like an array, sane defaults, no knobs required.
2. **Performance** — fast reads, fast writes, scales 50 → 5,000,000 entries.
3. **Stability** — multi-process safe, never returns torn/corrupt data, cleans
   up after itself.

Clarification:

```text
Ease of use means the public object is simple.
It does not mean the internals are bloated with convenience layers.

Performance means avoiding unnecessary indirection, counters, callbacks,
closures, and class boundaries when they do not earn their keep.

Stability means correctness under multi-process use, not defensive ceremony.
```

Do not trade the hot path for observability theater.

### The justification rule

```text
Every action must serve the priorities, or it must not exist.
```

Every method, property, class, abstraction, config key, test, and change must
serve at least one of ease of use, performance, or stability. If it serves none,
it does not get written, and if it already exists it gets removed. "It follows a
pattern," "it is conventional," "it is cleaner," or "it might be useful someday"
are **not** justifications. The burden is on the addition to prove it earns its
cost — not on the reviewer to prove it does not.

## 3. Public face

The public surface is **small and fixed**. The public access model is exactly:

```php
$fast['key'];
$fast['key'] = $value;
isset($fast['key']);
unset($fast['key']);

$fast->key;
$fast->key = $value;
isset($fast->key);
unset($fast->key);

foreach ($fast as $key => $value) {
}

count($fast);

$fast->each('walkFastEntry');

$fast->close();
$fast->destroy();
```

Plus construction (section 6) and PHP object magic (`__serialize`/`__unserialize`,
`__destruct`, optionally `__debugInfo`). That is the whole public story.

ArrayAccess is the primary path. Magic property access is sugar over ArrayAccess.
There is **one access model with two syntaxes**.

```text
new Fast('name') opens or creates a named store.
close() disconnects this process from that store (normal disconnection).
destroy() deletes the store, and only works when this process is the sole
connected process (administrative delete).
```

```text
destroy() is not normal cleanup. For non-persistent stores, normal cleanup is
final close/destruct/process exit. destroy() is an explicit administrative delete
and is refused while any other process is connected.
```

### Explicitly NOT public API

```text
attachShared()
openShared()
attachShard() / shard methods
segment methods
layout methods
allocator methods
journal log methods
record-frame methods
codec helpers
stats()
compact()
method-style CRUD: set/get/has/delete/tryGet/setMany/deleteMany
engine constants unless specifically documented
```

There is no public `open()`, `openShared()`, `attachShared()`, or any attach/shard/
segment method. The constructor is the only open/create path, and a `Fast` object
represents one store identity for its lifetime — it does not wander between stores.
`compact()` is internal maintenance, not public contract: the store maintains
itself.

`stats()` is **not part of the public Fast contract.** Public callers use
`count()`, `isset()`, `foreach`, ArrayAccess, magic properties, `close()`,
`destroy()`. Engine diagnostics belong in tests, private
helpers, or `__debugInfo()` if kept. If `stats()` exists in code today, it is
temporary diagnostic debt / implementation detail — not contract. It must not be
preserved as public merely because tests once used it. Tests prove behavior; they
do not sanctify scaffolding.

## 4. Access contract

### Primary: array access

`$fast['k']`, `$fast['k'] = $v`, `isset($fast['k'])`, `unset($fast['k'])` are the
primary interface. They read and write storage **directly**. They do not exist to
forward to some other "real" method.

### Secondary: magic properties

`$fast->k` is sugar for `$fast['k']`. Magic methods delegate to array access and
add nothing else. One access path, one syntax over it.

### Array semantics Fast must match

- `isset($fast['k'])` is **false** when the stored value is `null` (PHP semantics).
- Reading a missing key returns `null` and does **not** create an entry.
- Keys are `int|string`. Iteration is in **insertion order**.
- `count($fast)` is the number of live entries.

### Array semantics Fast cannot match (documented limits)

- No automatic int-key list renumbering / `[]` push semantics guarantees beyond
  explicit keys.
- References into nested values are write-back on flush, not live aliases.

## 5. Iteration and `each()`

### foreach

`foreach` observes the set of entries as of the start of the loop (snapshot-ish).
Concurrent writes from other processes may not appear mid-loop, but values are
never torn. `foreach` is the normal array-like iteration tool.

### each() — named callable only

`each(string|array $fn, ...$args)` is a grouped walk that invokes a callable once
per live entry. It accepts a **named callable only**:

```php
$fast->each('functionName');
$fast->each([SomeClass::class, 'method']);
$fast->each([$object, 'method']);
```

Closures are **rejected**. The signature is `string|array`, so a closure (or any
other type) is rejected by the PHP type system with a `TypeError` before the body
runs.

```text
Closures are not part of the each() contract. Closures are rejected.
```

Why: `each()` may walk very large shared stores. It is for named callable
dispatch, not closure-heavy callback soup.

### each() lock semantics

```text
each() is a grouped walk under a single writer lock in shared mode.
The named callable must be short.
Long work inside each() blocks other writers.
Use foreach for ordinary array-like iteration.
```

## 6. Construction and defaults

```php
new Fast();                                  // local, in-process, not shared
new Fast([]);                                // local, in-process, not shared
new Fast('name');                            // shared, named, non-persistent
new Fast(['name' => 'name']);                // shared, named, non-persistent
new Fast(['name' => 'name', 'persistent' => true]); // shared, named, persistent
```

Honored config keys: `name`, `capacity`, `size`, `persistent`. `persistent`
defaults to `false`. Every other key is a **hard error**
(`InvalidArgumentException`). Unsupported keys must not be silently ignored — a
typo or an unimplemented option must fail loudly. Examples of rejected keys:

```text
project_id
key
permissions
nmae
unknown
```

Defaults must make a small store cheap (a few KB is fine for app config) and let
the same store grow to millions of entries without the caller tuning anything.

## 7. Lifecycle and persistence

A named shared store has two modes:

```text
non-persistent / transient: default
persistent: opt-in (['persistent' => true])
```

### Process link count

```text
The process link count is the number of connected OS processes, not Fast objects.
```

That means a store's process link count is how many distinct OS processes currently
have it open — not how many `Fast` handles exist. It is a **count**, not necessarily
a single numeric header field: the implementation may derive it (e.g. from a
per-process attachment table), so long as it reflects connected processes:

```text
First Fast handle for a store in a process:
    shared process link count += 1

Additional Fast handles for the same store in the same process:
    no shared link count change

Last Fast handle for that store in that process closes/destructs/sleeps:
    shared process link count -= 1
```

The implementation may keep local per-process tracking (store identity + process
id + local handle count) to know when the first/last handle in this process
attaches/leaves. That is mechanism. The contract is: **the process link count
reflects connected processes.**

### What persistence controls

Persistence controls only what happens when the **final connected process**
leaves (the process link count is about to reach zero):

```text
Non-persistent (default):
    When the process link count reaches zero, the shared store is automatically
    destroyed and memory is reclaimed.

Persistent (opt-in):
    When the process link count reaches zero, the shared store remains available
    by name for later reattachment.
```

Persistent does **not** mean "safe to destroy while others are attached."
Persistent does **not** change the destroy rule.

### destroy() rule (identical in both modes)

```text
destroy() may remove the store only when the process link count is 1.

link count == 1:
    destroy() succeeds; this process is the only connected process.

link count > 1:
    destroy() fails clearly and leaves the store intact.
```

You must not rip a store out from under other attached processes, persistent or
not. The persistence flag does not change this; it only changes last-process
auto-reclaim behavior.

```text
The link-count check and store destruction must be performed under the lifecycle
lock so another process cannot attach between the check and removal.
```

The link count is the count of **live** connected processes. Before judging destroy
eligibility, Fast reconciles stale connections left by crashed processes (under the
no-FFI baseline this means sweeping the per-process attachment table and removing
dead PIDs — see `docs/design-law.md`). `destroy()` is judged against the live connected-process
count **after** that reconciliation, so a dead holder cannot block destruction.

`close()` never destroys a persistent store. For non-persistent stores, a final
clean close (the last connected process leaving) may destroy the store
automatically.

### Closed handles

`close()` disconnects this `Fast` handle from its store. `close()` is safe to call
more than once — a second `close()` is a no-op and never double-drops this
process's link or double-removes its lifecycle state.

After `close()`, the handle is no longer usable for array access, magic property
access, iteration, `count()`, `each()`, `destroy()`, or serialization. Those
operations fail clearly (a thrown `RuntimeException`) rather than silently
reopening the store, returning empty values, returning `null`/`false`, behaving
like an empty array, or operating on stale resources. A closed handle is closed;
to use the named store again, construct a new `Fast`.

There is no `open()`, no `reopen()`, and no public way to inspect or clear the
closed state. `__destruct` on an already-closed handle must not double-close or
corrupt the process link count.

### Crash safety (honest, fail-closed)

```text
Clean close/destruct/sleep must update the process link count.

A crash or SIGKILL may leave stale lifecycle state unless the implementation
provides explicit recovery.

On detected corruption or lifecycle inconsistency, Fast must fail closed:
refuse normal access and throw clearly. It must not silently continue on a
corrupt store. Explicit destroy is allowed only when lifecycle rules permit it.
```

There is no promise that some random process which notices a problem will
automatically destroy a persistent store others may still be using.

### Reconciliation with existing tests

The previous behavior treated every named store as persistent-only with an
unguarded `destroy()`. Many existing tests encode that old model and are now
wrong against this spec. They are reshaped to the contract below — the spec is
not bent back to them. The canonical patterns:

```text
Survive a close/handoff (write here, read there after closing):
    Use a PERSISTENT store, OR keep at least one handle/process connected across
    the handoff. A non-persistent store dies when the last connection leaves.

Clean up at end of a test:
    Prefer letting a non-persistent store auto-reclaim when the last handle goes
    away. Call destroy() only when this process is the sole owner (link == 1).
    Do NOT call destroy() on one handle while another handle/process is still
    attached — that now fails by rule.

Two handles in one test (writer + reader, same process):
    They are ONE connected process, so the link count is 1. destroy() by either
    is allowed only after the other has gone away. Simplest: drop explicit
    destroy() and let last-handle teardown reclaim (non-persistent).

Sleep/wake round-trip of contents:
    Use a PERSISTENT store (see section 8). Sleeping the sole handle of a
    non-persistent store destroys it.

Diagnostics / counter assertions:
    Do not assert public stats() or internal counters (sections 3, 13). Assert
    behavior (count(), isset(), foreach, lifecycle) or use a test-only
    introspection helper.

persistent config key:
    persistent is honored (section 6); a test asserting it is rejected is obsolete.
```

## 8. Serialization, sleep, and wake

### Mandatory igbinary

- The serializer for complex values (arrays/objects) is **igbinary**, and
  igbinary is **mandatory** — a hard requirement of `Fast`, not an optional
  acceleration. If the `igbinary` extension is not loaded, `Fast` fails fast
  rather than silently falling back to PHP `serialize()`.
- Rationale: a value serialized with igbinary and stored in shared memory may be
  read by another process or a later runtime. If that side did not also use
  igbinary, the data is unreadable. A fallback creates stores that can be written
  but not read. One serializer, always, removes that failure.
- **Capability detection is by extension, never by function.** Check
  `extension_loaded('igbinary')`; checking individual functions is costly and
  pointless.
- **Do not cache capability in a stored flag.** Fast does not maintain static
  `$hasIgbinary` / `$hasShmop` / `$hasSysVSem` properties or a `bootCapabilities()`
  primer merely to remember whether a required extension exists. `extension_loaded()`
  is itself a cheap lookup against an already-populated runtime table; mirroring it
  into a class flag is mechanism that earns nothing (economy of mechanism). Check the
  required extension **at the point where the capability is needed** and throw clearly
  if it is unavailable.
- **What is required, and when:**
  - `igbinary` is mandatory for Fast generally. Construction and wake fail
    immediately if `igbinary` is not loaded.
  - `shmop` and `sysvsem` are mandatory **only for named shared stores**. Opening a
    named shared store fails immediately if either extension is not loaded.
  - A local, in-process Fast may exist without `shmop`/`sysvsem`.
  - FFI is never required.

### Sleep / wake (handle serialization)

**Serialization preserves the Fast handle identity/configuration, not the shared
contents.**

`__serialize()` / `__unserialize()` do **not** serialize the shared store
contents. They serialize enough identity/configuration to close now and re-open
later by name.

```text
Serialized Fast handles do not contain the shared store contents.

Sleep closes the handle's connection to the store and decrements the process link
only when this was the last local handle for that store in the process.

Wake re-opens by name/configuration and increments the process link only when
this is the first local handle for that store in the process.

Sleep/wake must not duplicate or leak process links.
```

Mechanism (no-FFI baseline, see `docs/design-law.md`): decrementing/incrementing the process
link is realized through the per-process attachment table — **sleep removes this
process from the PID table when it was the last local handle for that store, and
wake adds it back** when it becomes the first local handle again.

### Sleep is a real close — locked-down consequences

Sleep is a real close: it is governed by the **same lifecycle rules as `close()`**
(section 7). It is not a special exemption. There is no hidden reservation that
keeps a store alive across a sleep.

```text
Sleep counts as this process leaving (when it is the last local handle, and
therefore the last connected process). It is subject to the exact same
last-process rule as close.
```

Therefore:

```text
Non-persistent store, sleep by the last connected process:
    The process link count reaches zero, so the store is DESTROYED at sleep
    and its memory is reclaimed. The serialized handle still holds only
    identity/config. A later wake reattaches by name and finds the store GONE
    (recreated empty if wake creates it). The previous contents are NOT restored.

Persistent store, sleep by the last connected process:
    The store SURVIVES at link count zero. A later wake reattaches by name and
    the previous contents are intact.

Either mode, while another process is still connected:
    The link count does not reach zero on sleep, so the store survives and wake
    finds the contents intact regardless of persistence.
```

This is intended, not a contradiction. The rule is uniform: a non-persistent
store lives exactly as long as some process is connected; sleeping the last
connection is the last connection leaving, so it dies.

```text
Canonical rule: to sleep a handle and reliably restore the SAME contents on wake
when no other process is keeping the store connected, the store must be
PERSISTENT. Sleeping the sole handle of a non-persistent store is a destroy.
```

## 9. Memory growth and shrinkage

The memory law:

```text
Memory is allocated and deallocated automatically while using as little memory
as reasonable.
```

Concretely:

```text
Deletes and replacements return space to the allocator immediately.

Freed space is reused before growing.

Compaction may release fully unused trailing shared-memory segments.

Permanent high-water-mark growth is not allowed.

Memory must automatically skrink as shared memory use declines based on reasonable metrics.

Shared memory usage must be bounded by live data plus reasonable fragmentation,
not by everything ever written.
```

Do not imply that every delete immediately reduces OS shared-memory size; deletes
return space to the allocator and freed space is reused, while OS-level shrink
happens via compaction of fully unused trailing segments.

`destroy()` is **not** normal memory management. `destroy()` deletes the store.
Memory management happens during ordinary set/unset/update/compact behavior.

## 10. Concurrency

- One writer at a time (writer lock). Readers do not block on every write.
- Readers validate consistency (sequence/seqlock) and retry; they never observe
  torn or partially-published data.
- Cross-process visibility: a write published by one process is observable by
  another (subject to the read's consistency check).

## 11. Implementation shape (not product religion)

The current development shape may include:

```text
Fast.php
Shared.php
Journal.php
Format.php
```

The spec does **not** sanctify that as permanent architecture.

```text
Class boundaries are implementation details unless they serve ease of use,
performance, or stability.
```

```text
Journal currently exists as a development aid and internal organization boundary.
It is not part of the public contract.

If Journal only serves Fast and imposes overhead, indirection, or maintenance
cost without buying correctness or performance, it should eventually be folded
back into Fast or otherwise eliminated.

The project does not follow class-separation religion. The shortest correct
path wins.
```

Also:

```text
Do not split classes to satisfy style preferences.
Do not keep collaborators that merely serve one class if they cost performance
or obscure the product.
Do not merge classes blindly during unstable development if separation is
currently helping implementation and verification.
```

```text
Temporary separation for development is allowed.
Permanent indirection must earn its cost.
```

### Naming: no class-name prefixes on members

A method or property must **not** repeat its own class name as a prefix. The
class already provides that context, so the prefix is pure stutter and noise.

```text
A member of class X must not be named X<Something>.
The receiver already says X. $this / $obj->X-prefix is redundant.
```

Examples (bad → good):

```text
class Shared:
    sharedRegionLayout()  -> regionLayout()
    readSharedSequence()  -> readSequence()
    sharedHeaderBytes()   -> headerBytes()
    $sharedName           -> $name

class Journal:
    journalStats()        -> stats()

class Fast:
    fastEach()            -> each()
```

This applies to private and public members alike. (It does not forbid a name that
merely contains the word — e.g. a genuine concept that happens to share a token —
only the redundant `ClassName`-prefix pattern.)

## 12. Constants and internals

```text
Engine constants, layout constants, record kinds, value kinds, header sizes,
slot sizes, allocator constants, and binary-format constants are implementation
details unless explicitly documented as public.

Tests should not force constants to remain on the Fast facade.

Codec/layout tests should use Fast\Format or internal fixtures, not public
facade constants, unless the constant is deliberately public.
```

This prepares a future constants-containment pass without performing it now.

## 13. Test discipline (the contract)

- Tests describe **how Fast is used and what it guarantees** — array access,
  magic access, iteration, lifecycle, concurrency safety, memory boundedness, and
  cleanup.
- Tests must not assert internal mechanism (which publish path ran, how many
  block-cache hits occurred, exact byte offsets, exact counter increments) as if
  it were contract.

```text
Tests must not require public stats().
Tests must not require proof counters.
Tests must not require public engine leaks.
Tests must not require public codec helpers on Fast.
Tests must not assert exact internal counter increments as contract.
Tests should assert behavior: array access, magic access, iteration, lifecycle,
concurrency safety, memory boundedness, and cleanup.
```

For performance proof: use benchmarks and targeted test helpers, not public
production counters.

For diagnostics: use test-only fixtures, reflection where justified, or private
diagnostic helpers. Do not widen public API to make tests convenient.

During a large structural change, tests will break first; that is expected. Fix
the code to the contract, then make the contract tests green. Do not reshape the
code to satisfy an implementation-detail test.

## 14. Non-negotiables

- Correctness and integrity under the stress benchmark (section 0) are non-negotiable.
  Performance must not regress beyond policy against the pinned baseline.
- Behaves like an array (within section 4 limits).
- Public face stays small (section 3); `stats()` and engine internals are not
  public contract.
- `each()` takes a named callable only; closures are rejected.
- Non-persistent by default; `persistent` is an opt-in honored config key.
- The process link count is the number of connected OS processes, not handles.
- `destroy()` only at link count == 1, checked-and-removed atomically under the
  lifecycle lock.
- Multi-process safe; never returns torn data; fails closed on corruption.
- Memory allocated/deallocated automatically; bounded by live data, not history.
- igbinary is mandatory; one serializer, always.
- FFI is **never required**. The shared-memory baseline is `shmop`/SysV only
  (see `docs/design-law.md`). FFI is a possible future optional accelerator,
  not a dependency.
- Simple to use with zero required configuration.
- Every action must serve a priority (ease of use, performance, stability) or it
  must not exist (section 2, the justification rule).
- The shortest correct path wins.

## Implementation direction

This specification defines **what Fast must do**.

The current evidence-backed implementation direction lives in:

`docs/design-law.md`

That document guides how the implementation currently intends to satisfy this
contract. It is **not public API**. If the design law and this specification
disagree, **this specification wins**.
