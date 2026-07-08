# ext-fast

Native PHP extension for `\Fast`. When loaded, **owns** class `\Fast` (ADR 005).
Pure-PHP `johnnyjoy/fast` remains the portable default; this removes the PHP↔shm
hot-path tax on Linux x86_64.

Full install guide: [`../../docs/extension-install.md`](../../docs/extension-install.md)
(includes **PHP-FPM Docker** examples for Debian and Alpine).

## Requirements

- PHP 8.3 or 8.4 development headers (`phpize`, `php-config`)
- `ext-igbinary` enabled (required dependency — v1 calls `igbinary_*` from C)
- Linux x86_64 (v1). ARM64: semaphore-backed reads only.

## Build and install

```bash
cd ext/fast
phpize
./configure --enable-fast
make
sudo make install
```

```ini
; php.ini
extension=fast
fast.compat=0   ; 0 = native LAYOUT_EXT (default), 1 = PHP LAYOUT_PHP interop
```

Verify:

```bash
php -m | grep fast
php --ri fast
php -r 'var_dump(class_exists("Fast"));'
```

## PECL

`package.xml` is included for PECL packaging:

```bash
cd ext/fast
pecl package package.xml
```

## Test

**Extension phpt (INI, local smoke):**

```bash
./run-phpt.sh
```

**Full contract suite** (from repo root):

```bash
make
FAST_BACKEND=ext FAST_EXT_SO=ext/fast/modules/fast.so php tests/run.php
```

**Userland baseline:**

```bash
composer test
```

## v1 decisions (locked)

| # | Decision | Value |
|---|----------|-------|
| 1 | Class registration | Extension owns `\Fast` when loaded |
| 2 | igbinary | PHP `igbinary_*` in v1 |
| 3 | Native namespace | `fast-native-*` (distinct from PHP `fast-flat-*`) |
| 4 | Striped | Native Striped v1, same semantics |
| 5 | ARM64 | Semaphore reads only (`spin=0` on non-TSO CPUs) |
| 6 | Compat default | Off — native default |

## Concurrency reads

Lock-free reads use a seqlock on `H_SEQ` with no explicit memory fence. That is
correct on **TSO CPUs** (x86/x86_64). On weakly ordered CPUs (ARM64, etc.) the
extension sets `spin=0` at attach so reads go through the writer semaphore
(syscall barrier), matching PHP `Flat.php`.

Override for testing: `FAST_LOCKFREE=0` (always locked reads) or `FAST_LOCKFREE=1`
(force lock-free spin attempts).

## Layout specs

- Native: [`../../docs/extension-layout-native.md`](../../docs/extension-layout-native.md)
- Compat: [`../../docs/extension-compat.md`](../../docs/extension-compat.md)

## INI

| Setting | Default | Meaning |
|---------|---------|---------|
| `fast.compat` | `0` | `1` = PHP wire format; `0` = native |

Per-instance override: `new Fast(['compat' => true])`.

## License

MIT — see [LICENSE](LICENSE).
