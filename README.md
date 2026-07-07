# Fast

Array-like shared-memory store for PHP — use it like an array, across processes.

```php
use \Fast;

$cfg = new Fast('app-config');
$cfg['debug'] = true;

if (isset($cfg['debug'])) {
    $enabled = $cfg['debug'];
}

foreach ($cfg as $key => $value) {
    // ...
}

count($cfg);
```

## When to use Flat vs Striped

**Flat** (default) is one shared map, one write lock. Use it for a single process,
read-heavy workloads, or when many writers touch the same few keys.

**Striped** (`'stripes' => 8` in the config) splits the map into independent
sub-stores so multiple processes can write in parallel when they hit **different
keys**.

We measure both engines on the **same tasks** so you do not have to guess:

```bash
php benchmarks/compare-engines.php
```

On a reference Linux / PHP 8.3 run, many writers with spread keys saw Striped
**~3× faster at 4 workers and ~5× at 8 workers**; many writers on one shared
counter showed **no win** (Striped slightly slower at 8 workers). Full tables
and how to read them: [`docs/performance.md`](docs/performance.md).

```php
// Default — one store, strict iteration order
$store = new Fast(['name' => 'workers', 'persistent' => true]);

// Many worker processes, writes spread across keys
$store = new Fast(['name' => 'workers', 'persistent' => true, 'stripes' => 8]);
```

## Requirements

- PHP 8.3+
- Extensions: `igbinary`, `shmop`, `sysvsem`
- Optional: `pcntl` (multi-process tests and engine comparison)

## Install

```bash
composer require johnnyjoy/fast
```

Optional native engine (Linux x86_64, PHP 8.3+): compile [`ext/fast`](ext/fast/) or see [`docs/extension-install.md`](docs/extension-install.md). When the extension is loaded it owns class `\Fast`.

For local development:

```bash
composer install
composer test
composer bench:compare   # Flat vs Striped on the same workloads (needs pcntl)
```

## Documentation

| Doc | Contents |
|-----|----------|
| [`docs/performance.md`](docs/performance.md) | Measured Flat vs Striped, how to reproduce |
| [`docs/engine-architecture.md`](docs/engine-architecture.md) | How storage and locks work |
| [`docs/specification.md`](docs/specification.md) | Behavior contract |
| [`docs/extension-install.md`](docs/extension-install.md) | Optional ext-fast build, PECL, CI |
| [`docs/README.md`](docs/README.md) | Full doc index |

## Using Fast with AI assistants

The [`skills/`](skills/) directory is for **consumers** — copy it into your app
(or reference it from `vendor/johnnyjoy/fast/skills/`) so Cursor and other agents
know how to use `\Fast` correctly. Start with [`skills/fast/SKILL.md`](skills/fast/SKILL.md).

Public API PHPDoc is enforced on `src/` via `composer lint`.

## Validation

```bash
composer install
composer test          # full contract suite
composer lint          # PHPDoc on public API (src/)
php tests/stress_100k_bench.php   # 100k-key stress (integrity + throughput)
```

## License

MIT — see [LICENSE](LICENSE).
