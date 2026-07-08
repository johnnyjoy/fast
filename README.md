# Fast

**Fast is an array-like shared-memory store for PHP.**

Use it like a normal PHP array, but share the data across local processes such as PHP-FPM workers, CLI jobs, forked workers, and background scripts.

```php
use Fast;

$cfg = new Fast('app-config');

$cfg['debug'] = true;

if (isset($cfg['debug'])) {
    $enabled = $cfg['debug'];
}

foreach ($cfg as $key => $value) {
    // Iterates in insertion order.
}

count($cfg);
```

Fast is designed for small-to-medium local shared state where you want PHP-native ergonomics without running a separate service.

It is **not** a Redis replacement, queue system, database, network cache, or diagnostics dashboard.

---

## Why Fast?

PHP makes per-request state easy, but sharing state across local PHP processes usually means reaching for an external service.

Fast gives you a smaller option:

- **Array-like API**: `ArrayAccess`, `Iterator`, and `Countable`
- **Shared memory**: named stores are visible across local processes
- **Pure PHP by default**: install with Composer
- **Optional native extension**: use `ext-fast` for production-oriented workloads
- **Concurrency-safe writes**: one writer section per Flat store or per Stripe
- **Crash recovery**: repair runs when a writer dies mid-update
- **Striped mode**: parallel writes when workers touch different keys
- **Interop mode**: PHP backend and extension can share the PHP wire format when configured

---

## Requirements

- Linux for shared-memory mode
- PHP 8.3+
- PHP extensions:
  - `igbinary`
  - `shmop`
  - `sysvsem`
- Optional:
  - `pcntl` for multi-process tests and benchmarks
  - `ext-fast` for the native backend

---

## Install

```bash
composer require johnnyjoy/fast
```

For local development:

```bash
composer install
composer test
composer lint
composer bench:compare
```

`composer bench:compare` needs `pcntl`.

---

## Quick Start

### Shared store

```php
use Fast;

$store = new Fast('app-cache');

$store['settings'] = [
    'theme' => 'dark',
    'debug' => false,
];

echo $store['settings']['theme'];
```

Open the same named store from another local PHP process:

```php
use Fast;

$store = new Fast('app-cache');

var_dump($store['settings']);
```

### Local in-process store

```php
use Fast;

$store = new Fast();

$store['temporary'] = true;
```

Calling `new Fast()` without a name creates an in-process store only. It does not use shared memory.

### Configured store

```php
use Fast;

$store = new Fast([
    'name' => 'workers',
    'capacity' => 1024,
    'size' => 8 * 1024 * 1024,
    'persistent' => true,
    'stripes' => 8,
]);
```

Unknown config keys throw.

| Key | Meaning |
|-----|---------|
| `name` | Store name. Enables shared multi-process mode. |
| `capacity` | Directory slot count. Must be a power of two. |
| `size` | Byte budget for shared storage. |
| `persistent` | Keep the store after the last process disconnects. |
| `stripes` | Use the Striped engine when set to `2` or higher. |

Extension-only compatibility options:

| Option | Meaning |
|--------|---------|
| `compat => true` | Make the extension use the PHP-compatible wire format. |
| `fast.compat=1` | Enable extension compatibility mode globally. |

---

## Public API

Fast intentionally keeps the public surface small.

```php
$store = new Fast('example');

$store['a'] = 1;
$store['b'] = ['x' => true];

isset($store['a']);

unset($store['a']);

count($store);

foreach ($store as $key => $value) {
    // ...
}

$store->close();
$store->destroy();
```

Supported PHP interfaces:

- `ArrayAccess`
- `Iterator`
- `Countable`

Lifecycle methods:

| Method | Behavior |
|--------|----------|
| `close()` | Disconnect this handle from the store. |
| `destroy()` | Destroy the store. Requires sole ownership. |
| `count()` | Return the number of live entries. |
| `each()` | Iteration helper. |

There is no public engine-introspection API. Internal stats are private/test-only.

---

## Lifecycle

Fast has two lifecycle modes.

### Non-persistent stores

This is the default.

```php
$store = new Fast('request-cache');
```

When the last connected process closes a non-persistent store, the shared memory is reclaimed.

### Persistent stores

```php
$store = new Fast([
    'name' => 'app-cache',
    'persistent' => true,
]);
```

A persistent store survives after all processes disconnect. It remains available until a sole owner explicitly destroys it.

```php
$store->destroy();
```

`close()` never destroys a persistent store.

Serialization stores the handle identity and config, not the contents of the shared store.

---

## Pure PHP vs Native Extension

Fast has two implementations with one public class.

| Backend | Used when | Class owner |
|---------|-----------|-------------|
| Pure PHP | Default Composer install | `src/Fast.php` |
| Native extension | `extension=fast` is loaded | `ext-fast` |

When the extension is loaded, it owns `\Fast`; the userland class is not loaded.

Both backends are expected to satisfy the same behavior contract in [`docs/specification.md`](docs/specification.md).

```bash
composer test
FAST_BACKEND=ext php tests/run.php
```

### Native extension

Build from [`ext/fast`](ext/fast/) or see [`docs/extension-install.md`](docs/extension-install.md).

```bash
cd ext/fast
phpize
./configure --enable-fast
make
```

Load `igbinary` before `fast`:

```ini
extension=igbinary
extension=fast
```

---

## Storage Compatibility

Fast has two wire formats.

| Mode | Magic | Namespace | Reader |
|------|-------|-----------|--------|
| PHP layout | `FLT2` | `fast-flat-*` SysV shared memory | Pure PHP backend, or extension with compatibility mode |
| Native layout | `XFST` | `fast-native-*` mmap | Native extension default |

The native extension uses its own layout by default. That is the preferred mode when all workers run the extension.

For mixed PHP/extension fleets, enable compatibility mode on the extension:

```php
$store = new Fast([
    'name' => 'shared-cache',
    'compat' => true,
]);
```

Or in `php.ini`:

```ini
fast.compat=1
```

The layouts use different magic values and namespaces so they do not attach to each other accidentally.

---

## When to Use Flat vs Striped

Fast has two internal engines.

| Scenario | Recommended engine |
|----------|--------------------|
| Single process | Flat |
| Read-heavy workload | Flat |
| Many writers touching the same few keys | Flat |
| Many workers writing different keys | Striped |
| Hot shared counter | Flat |

### Flat

Flat is the default. It is one shared map with one writer critical section.

```php
$store = new Fast([
    'name' => 'workers',
    'persistent' => true,
]);
```

Flat gives strict insertion-order iteration.

### Striped

Striped splits the map into independent sub-stores so multiple processes can write in parallel when they hit different keys.

```php
$store = new Fast([
    'name' => 'workers',
    'persistent' => true,
    'stripes' => 8,
]);
```

Striped can help when work is spread across keys. It does not help when every worker writes the same key.

Under concurrent writers, Striped iteration order is approximate.

### Benchmark comparison

Run both engines on the same workloads:

```bash
php benchmarks/compare-engines.php
```

On a reference Linux / PHP 8.3 run, spread-key writes saw Striped roughly **3× faster at 4 workers** and **5× faster at 8 workers**. A shared-counter workload showed **no win**.

See [`docs/performance.md`](docs/performance.md) for full tables and interpretation.

---

## Concurrency and Recovery

Fast uses a conservative concurrency model.

Writers enter a SysV semaphore-protected critical section. A seqlock marks writes as in-progress and stable.

On x86/x86_64, readers use a lock-free read path with seqlock validation and fallback to a locked read when needed.

On ARM64, the native extension uses semaphore reads only.

If a writer dies mid-update, the next attach runs repair. Repair validates live slots, quarantines invalid entries, recomputes counters, and repairs the order log.

---

## Validation

```bash
composer install
composer test
composer lint
php tests/stress_100k_bench.php
```

Native extension contract run:

```bash
FAST_BACKEND=ext FAST_EXT_SO=ext/fast/modules/fast.so php tests/run.php
```

Extension PHPT tests:

```bash
cd ext/fast
./run-phpt.sh
```

Docker smoke test:

```bash
docker build -f docker/debian-fpm.Dockerfile -t fast-ext-debian-fpm .
./docker/smoke.sh fast-ext-debian-fpm
```

---

## Documentation

| Doc | Contents |
|-----|----------|
| [`docs/specification.md`](docs/specification.md) | Authoritative behavior contract |
| [`docs/performance.md`](docs/performance.md) | Flat vs Striped benchmarks and interpretation |
| [`docs/engine-architecture.md`](docs/engine-architecture.md) | Storage, locks, and PHP engine architecture |
| [`docs/extension-install.md`](docs/extension-install.md) | Optional `ext-fast` build, Docker, PECL, and CI notes |
| [`docs/extension-layout-native.md`](docs/extension-layout-native.md) | Native layout details |
| [`docs/extension-compat.md`](docs/extension-compat.md) | PHP/extension compatibility format |
| [`docs/README.md`](docs/README.md) | Full documentation index |

When documents disagree, [`docs/specification.md`](docs/specification.md) wins.

---

## Using Fast with AI Assistants

The [`skills/`](skills/) directory is for consumers. Copy it into your app or reference it from `vendor/johnnyjoy/fast/skills/` so Cursor and other agents know how to use `\Fast` correctly.

Start with [`skills/fast/SKILL.md`](skills/fast/SKILL.md).

---

## Known Limitations

- Shared mode is Linux-only.
- The PHP and native layouts are separate by default.
- Mixed PHP/extension fleets require extension compatibility mode.
- Striped iteration order is approximate under concurrent writers.
- Seek cost is currently `O(order)`.
- Extreme multi-process delete/reinsert churn can fill the order log under stress.
- PECL packaging exists, but the package has not yet been published to PECL.

---

## Acknowledgments

Portions of this codebase were developed and edited with assistance from [Cursor AI](https://cursor.com).

---

## License

MIT — see [LICENSE](LICENSE).