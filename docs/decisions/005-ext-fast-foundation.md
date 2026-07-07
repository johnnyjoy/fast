# ADR 005: ext-fast foundation decisions

**Status:** Accepted (2026-07-06)

**Context:** Optional native PHP extension (`ext-fast`) implementing the same
product goal as pure-PHP `\Fast`. Full plan: [`../plans/ext-fast.md`](../plans/ext-fast.md).

---

## Decisions

### 1. Class registration

When `ext-fast` is loaded, the extension **owns** class `\Fast` (Option A).

Pure-PHP `src/Fast.php` is not registered when the extension is present. One public
class, one code path per install.

### 2. igbinary from C

v1 calls into PHP **`igbinary_*`** for non-scalar encode/decode. Embedding igbinary
in C is deferred until profiling proves it necessary.

`ext-igbinary` is required at module init; fail with a clear error if missing.

### 3. Native lock / segment namespace

Native stores use a **distinct prefix** from pure-PHP SysV keys and lock files
(e.g. `fast-native-*` vs `fast-flat-*`). Native and PHP wire formats must never
collide on attach by accident.

### 4. Striped in native v1

**Yes.** Native engine ships with Striped (`stripes` config) in v1, same semantics
as pure-PHP: total `capacity` / `size` split across stripes, spread-key MP writes.

### 5. ARM64 v1 concurrency

**Semaphore-backed reads only** on ARM64 in v1. No seqlock/lock-free reader path
until independently audited for fence correctness.

x86_64: C11 atomics reader path in native layout.

### 6. Compat mode default

**Off.** Default wire format is native (`LAYOUT_EXT`). PHP byte layout (`LAYOUT_PHP`,
current `LAYOUT = 1`) is opt-in via INI `fast.compat=1` or constructor `compat =>
true`.

Interop with pure-PHP processes requires compat explicitly enabled on the
extension side (and PHP side using the existing layout).

---

## Consequences

- Phase 0 scaffolding may proceed.
- Native layout spec (`docs/extension-layout-native.md`) and compat mapping
  (`docs/extension-compat.md`) are required before Phase 1 native layout coding.
- CI will run `tests/run.php` under `FAST_BACKEND=ext` once the extension exists.
