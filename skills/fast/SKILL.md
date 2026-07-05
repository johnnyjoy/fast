---
name: fast
description: Helps developers use the Fast PHP shared-memory library (johnnyjoy/fast) in application code. Use when integrating Fast, choosing Flat vs Striped, shared state across PHP processes, lifecycle/persistence, or debugging Fast usage — class \Fast, composer require johnnyjoy/fast.
---

# Using Fast in your project

Fast is an array-like **shared-memory store for PHP**. Install it, then use **`\Fast`**
like an array — including across worker processes.

```bash
composer require johnnyjoy/fast
```

```php
use \Fast;

$cfg = new Fast('app-config');
$cfg['debug'] = true;
```

**Requirements:** PHP 8.3+, extensions `igbinary`, `shmop`, `sysvsem` (Linux).

---

## The API in one minute

| You write | What happens |
|-----------|--------------|
| `new Fast()` | Local only — no shared memory, single process |
| `new Fast('name')` | Shared store, visible to other processes with the same name |
| `$store['key'] = $value` | Write (array syntax) |
| `$x = $store['key']` | Read |
| `isset($store['key'])` | Key exists |
| `foreach ($store as $k => $v)` | Iterate |
| `count($store)` | Entry count |
| `$store->close()` | This process disconnects |
| `$store->destroy()` | Delete the store (sole-owner rules apply) |

The public class is always **`\Fast`** (global namespace). Import with `use \Fast;`.

---

## Choosing Flat vs Striped

Both use the same API. Default is **Flat** (one lock, strict iteration order).

Enable **Striped** when many processes write **different keys** in parallel:

```php
$store = new Fast([
    'name'       => 'workers',
    'persistent' => true,
    'stripes'    => 8,   // power of two, >= 2
]);
```

| Use Flat (default) | Use Striped |
|--------------------|-------------|
| One writer or mostly reads | Many writers, keys spread out |
| Shared counter / hot key | Per-worker or per-job keys |
| Strict insertion order | Approximate order is fine |

Striped does **not** speed up everyone writing the **same** key. If in doubt,
start with Flat.

`capacity` (directory slots) and `size` (segment bytes) are **total** budgets;
with stripes they are split evenly across sub-stores.

---

## Configuration

Only these keys are accepted; anything else throws:

| Key | Purpose |
|-----|---------|
| `name` | Named shared store (omit for local-only) |
| `persistent` | `true` = store survives when no process is connected |
| `capacity` | Directory slot count (power of two) |
| `size` | Shared segment size in bytes |
| `stripes` | Enable Striped engine (power of two ≥ 2) |

```php
new Fast(['name' => 'cache', 'persistent' => true, 'size' => 64 * 1024 * 1024]);
```

---

## Persistence and lifecycle

**Non-persistent (default):** when the last **process** disconnects, the store is
reclaimed. Multiple `Fast` objects in one process count as one connection.

**Persistent:** opt in with `'persistent' => true` for config or state that must
survive zero attached processes.

- `close()` — drop this handle; other processes may still have the store open.
- `destroy()` — administrative delete; only when this process is the sole owner.

Opening `new Fast('name')` always **opens or creates** — it cannot prove a store
was deleted. Use lifecycle tests in the docs if you need destroy semantics.

---

## Multi-process patterns

**Fork / workers:** each child must open the same named store:

```php
$parent = new Fast(['name' => 'jobs', 'persistent' => true]);
// after fork:
$child = new Fast(['name' => 'jobs', 'persistent' => true]);
$child["worker:{$pid}"] = 'busy';
```

**Spread writes for throughput:** use Striped and key per worker/job, not one
global counter.

**`each()`:** runs under a writer lock in shared mode — keep callbacks short.
Prefer `foreach` for normal iteration.

---

## Common mistakes

- Expecting Striped to help when all workers update one key.
- Long work inside `each()` — blocks other writers.
- Assuming `destroy()` from one handle always succeeds while another process
  still has the store open.
- Typos in config keys — unsupported keys fail loudly (by design).
- Benchmarking without noting RAM speed; Fast is shared-memory and RAM-bound.

---

## Examples

**App config (survives restarts)**

```php
$cfg = new Fast(['name' => 'app-config', 'persistent' => true]);
$cfg['maintenance'] = false;
```

**Request-scratch (local, no shm)**

```php
$ctx = new Fast();
$ctx['request_id'] = $id;
```

**Worker pool**

```php
$state = new Fast(['name' => 'job-state', 'persistent' => true, 'stripes' => 8]);
$state["job:{$id}:status"] = 'running';
```

---

## Further reading (in the package / on GitHub)

| Question | Document |
|----------|----------|
| Full behavior contract | `docs/specification.md` |
| Flat vs Striped numbers | `docs/performance.md` |
| How storage works | `docs/engine-architecture.md` |
| Doc index | `docs/README.md` |

Repository: https://github.com/johnnyjoy/fast
