# ext-fast — implementation plan

Optional compiled extension. Same goal as pure-PHP `\Fast`: array-like shared
memory across processes, correct under concurrency, clean crash recovery.

Pure-PHP package remains the portable default. Extension removes the PHP↔shm
hot-path tax.

**Locked decisions:** [ADR 005](../decisions/005-ext-fast-foundation.md) (accepted 2026-07-06).

---

## Success criteria

- `tests/run.php` passes with `FAST_BACKEND=ext`.
- `tests/stress_100k_bench.php` integrity: records lost 0, orphans 0, errors 0.
- Throughput beats pure-PHP baseline on reference hardware or documented gap + fix plan.

---

## Product split

| Artifact | Role |
|----------|------|
| `johnnyjoy/fast` | Spec, tests, docs, pure-PHP engine |
| `ext-fast` (separate repo or `ext/` subtree) | Native engine; optional compile |
| `\Fast` | Single public class name always |

Composer suggest: `"ext-fast": "Native Fast engine (optional compile)"`.

---

## Wire formats

| Mode | Default | Readable by pure PHP |
|------|---------|----------------------|
| Native (`LAYOUT_EXT`) | **Yes** | No |
| Compat (`LAYOUT_PHP`, current `LAYOUT=1`) | No | Yes (when compat enabled both sides) |

**Compat switch:**

```ini
fast.compat = 0   ; 0 native, 1 PHP layout
```

```php
new Fast(['name' => 'x', 'compat' => true]);  // instance overrides INI
```

Native layout targets: POSIX `mmap` backing, bucket-fp directory (design-law §1),
C11 atomics on x86_64, same public semantics as spec.

Compat layout: byte-identical to `docs/engine-architecture.md` / `Flat.php`.

---

## Module layout (target)

```
ext-fast/
  fast.c              # MINIT, INI, class registration
  fast_object.c       # ArrayAccess, Iterator, Countable, each, lifecycle
  engine/
    flat_native.c
    flat_compat.c
    striped_native.c
    alloc.c
    directory_native.c
    directory_compat.c
    lifecycle.c
    serialize.c       # scalar fast path + igbinary delegate (v1)
```

Local mode (no `name`): Zend hash table, no shm — same as today.

---

## Phases

| Phase | Scope | Gate |
|-------|-------|------|
| 0 | Scaffold, INI, local mode | `basic`, `array_access`, `iterate` |
| 1 | Native shared core | 10k churn integrity |
| 2 | Lifecycle + crash recovery | `crash_recovery*`, `lifecycle_*` |
| 3 | Compat engine + interop | mixed PHP/ext processes, stress integrity |
| 4 | Native Striped + perf | `ext_striped_smoke`, `striped_basic` (ext skips serialize); compare-engines/stress_gate manual |
| 5 | Facade completeness | full `tests/run.php` (native + compat CI jobs) | **done** |
| 6 | Ship docs, PECL path | Ubuntu 22.04/24.04, PHP 8.3/8.4 | **done** |

**Estimate:** ~30 weeks serial, one engineer. Phase 3 parallel with Phase 1 saves ~4 weeks with two engineers.

---

## Test strategy

- `FAST_BACKEND=php|ext` on existing `tests/run.php`.
- New `tests/interop_php_ext.php` (compat only).
- Native store open by pure PHP must fail clearly.
- phpt for INI, leaks, resource limits.

---

## Pre-Phase-1 docs (required)

| Doc | Purpose |
|-----|---------|
| [`docs/extension-layout-native.md`](../extension-layout-native.md) | Byte layout for `LAYOUT_EXT` |
| [`docs/extension-compat.md`](../extension-compat.md) | Field mapping to PHP `LAYOUT=1` |

---

## Next actions

1. ~~Lock §11 decisions~~ — done (ADR 005).
2. ~~Create `ext/fast` scaffold; Phase 0 local mode~~ — done.
3. ~~Write native + compat layout specs~~ — done.
4. ~~Add `FAST_BACKEND` to `tests/run.php`~~ — done.
5. Phase 1: native shared engine — **done** (mmap XFST, local + shared smoke).
6. ~~Phase 2: lifecycle flock, crash recovery~~ — **done** (`lifecycle_*`, `crash_recovery*` pass under `FAST_BACKEND=ext`).
7. ~~Phase 3: compat engine + interop~~ — **done** (`flat_compat.c`, `ext_compat_smoke`, `interop_php_ext`).
8. ~~Phase 4: Native Striped + perf~~ — **done** (`striped_native.c`, tagged order log in `flat_native.c`, `ext_striped_smoke`, `striped_basic` under `FAST_BACKEND=ext`; 12 gate tests pass).
9. ~~Phase 5: Facade completeness~~ — **done** (full `tests/run.php` under `FAST_BACKEND=ext`; serialize/each/writeback/stats, metadata-only isset, collision guard, cross-process mmap sync).
10. ~~Phase 6: Ship docs, PECL path~~ — **done** (`docs/extension-install.md`, `ext/fast/package.xml`, phpt tests, CI matrix Ubuntu 22.04/24.04 × PHP 8.3/8.4).
