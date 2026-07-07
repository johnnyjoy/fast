# Fast documentation

Fast is a **PHP array that lives in shared memory**. Multiple processes (PHP-FPM
workers, CLI jobs, etc.) can read and write the same named store.

```php
use \Fast;

$cache = new Fast('app-cache');
$cache['settings'] = ['theme' => 'dark'];
```

You do not need to understand shared memory to **use** Fast. You only need the
API (same as an array) and a few config keys (`name`, `persistent`, `stripes`, …).

---

## Start here

| If you want to… | Read this |
|-----------------|-----------|
| **Use Fast in your app** | [`specification.md`](specification.md) — sections 0–2 and the config table |
| **Pick Flat or Striped** | [`performance.md`](performance.md) — measured comparison |
| **Install the optional C extension** | [`extension-install.md`](extension-install.md) — includes PHP-FPM Docker |
| **See how data is stored** (optional) | [`engine-architecture.md`](engine-architecture.md) |

Install the library:

```bash
composer require johnnyjoy/fast
```

Requirements: PHP 8.3+, extensions `igbinary`, `shmop`, `sysvsem`, Linux.

---

## All documents

### Everyday use

| Document | Purpose |
|----------|---------|
| [`specification.md`](specification.md) | **Behavior contract** — what `\Fast` must do. When docs disagree, this wins. |
| [`performance.md`](performance.md) | Flat vs Striped with real numbers and when to enable `stripes`. |
| [`extension-install.md`](extension-install.md) | Build and run **ext-fast** (optional native extension). |

### Under the hood

| Document | Purpose |
|----------|---------|
| [`engine-architecture.md`](engine-architecture.md) | Segments, directory, locks, crash recovery (pure-PHP engine). |
| [`extension-layout-native.md`](extension-layout-native.md) | Byte layout for the native extension (advanced). |
| [`extension-compat.md`](extension-compat.md) | Wire format when PHP and ext must share one store (advanced). |

### Project history and direction

| Document | Purpose |
|----------|---------|
| [`design-law.md`](design-law.md) | Implementation direction (not the public contract). |
| [`guiding-principles.md`](guiding-principles.md) | Engineering mindset. |
| [`design-study.md`](design-study.md) | Research that led to current design. |
| [`decisions/`](decisions/) | Architecture decision records (ADRs). |
| [`plans/ext-fast.md`](plans/ext-fast.md) | Extension rollout plan. |
| [`archive/`](archive/) | Old notes — not current behavior. |
| [`../research/README.md`](../research/README.md) | Benchmarks and experiments. |

---

## Common tasks

| Task | Where to look |
|------|----------------|
| Shared cache across FPM workers | [`extension-install.md`](extension-install.md) (Docker) or `new Fast('name')` in [`specification.md`](specification.md) |
| Many writers, different keys | [`performance.md`](performance.md) → enable `stripes` |
| Store survives deploy / restart | `persistent => true` in [`specification.md`](specification.md) |
| Run benchmarks locally | `php benchmarks/compare-engines.php` (needs `pcntl`) |
| Stress / integrity test | `php tests/stress_100k_bench.php` |

---

## If documents disagree

```text
specification.md       ← behavior (wins)
design-law.md          ← how we build it
engine-architecture.md ← on-disk layout (PHP engine)
design-study.md        ← evidence
archive/               ← history only
```

---

## Run the test suite

```bash
composer install
composer test
composer lint          # PHPDoc on public API
```
