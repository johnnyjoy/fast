# Installing ext-fast

The **ext-fast** extension is optional. It provides the same `\Fast` class as the
Composer package, implemented in C for lower overhead on shared-memory operations.

**You do not need the extension to use Fast.** Install it when you want native
speed in production (often PHP-FPM) and can compile or package a PHP extension.

When the extension is loaded, it **registers** `\Fast` — you do not also load
`src/Fast.php` in the same process.

---

## What you need

| Item | Details |
|------|---------|
| PHP | 8.3 or 8.4 |
| OS | Linux x86_64 (v1) |
| Extensions | **igbinary** (required), **sysvsem** (required for shared stores), **shmop** (only if using compat mode) |
| Build tools | `phpize`, `php-config`, a C compiler |

---

## Install on a server (from source)

Install **igbinary** and **sysvsem** first (Fast depends on igbinary at load time):

```bash
# Debian/Ubuntu example — package names vary by PHP version
sudo apt-get install -y php8.3-dev php8.3-igbinary php8.3-sysvsem php8.3-shmop
# Or: sudo pecl install igbinary  (then enable igbinary before fast in php.ini)
```

```bash
git clone https://github.com/johnnyjoy/fast.git
cd fast/ext/fast

phpize
./configure --enable-fast
make
sudo make install
```

Enable in PHP (FPM and CLI if you use both). **Load igbinary before fast:**

```ini
extension=igbinary
extension=fast
fast.compat=0
```

Check:

```bash
php -m | grep -E '^(fast|igbinary|sysvsem)$'
php -r 'var_dump(class_exists("Fast"));'
```

---

## Docker + PHP-FPM

This is the usual production setup: **many FPM workers in one container** sharing
one cache via a named store.

Each worker loads the same `extension=fast` INI. A store like
`new Fast(['name' => 'app-cache'])` is visible to **all workers in that container**.

You need these PHP extensions in the image:

| Extension | Required? |
|-----------|-----------|
| igbinary | Yes — Fast encodes values through it |
| sysvsem | Yes — write locking |
| shmop | Only if `fast.compat=1` (PHP ↔ ext interop) |

Build context: the **repository root** is the Docker build context and you run
`COPY ext/fast /usr/src/ext/fast`.

**Important:** exclude host `phpize` artifacts from the build context. A local
`make` in `ext/fast` leaves a `Makefile` with absolute paths from your machine;
`docker build` will then fail inside the container. The repo includes
[`.dockerignore`](../.dockerignore) for this. If you copy files manually, omit
`Makefile`, `*.dep`, `modules/`, and `.libs/`.

Tested Dockerfiles (kept in sync with this doc): [`docker/debian-fpm.Dockerfile`](../docker/debian-fpm.Dockerfile),
[`docker/alpine-fpm.Dockerfile`](../docker/alpine-fpm.Dockerfile). Smoke test:
`docker build -f docker/debian-fpm.Dockerfile . && ./docker/smoke.sh fast-ext-debian-fpm`.

Use a **single INI** with igbinary listed before fast. `docker-php-ext-enable`
creates separate files loaded in alphabetical order (`fast` before `igbinary`),
which breaks on Alpine (musl) and is fragile elsewhere:

```ini
; /usr/local/etc/php/conf.d/99-fast.ini
extension=igbinary
extension=fast
fast.compat=0
```

### Debian — `php:8.3-fpm-bookworm`

```dockerfile
FROM php:8.3-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends $PHPIZE_DEPS \
    && docker-php-ext-install sysvsem shmop \
    && pecl install igbinary

COPY ext/fast /usr/src/ext/fast
WORKDIR /usr/src/ext/fast
RUN phpize \
    && ./configure --enable-fast \
    && make -j"$(nproc)" \
    && make install \
    && rm -rf /usr/src/ext/fast

RUN { \
      echo 'extension=igbinary'; \
      echo 'extension=fast'; \
      echo 'fast.compat=0'; \
    } > /usr/local/etc/php/conf.d/99-fast.ini

RUN apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
        $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
```

Verify in the container:

```bash
php-fpm -t
php -m | grep -E '^(fast|igbinary|sysvsem)$'
php -r 'var_dump(class_exists("Fast"));'
```

### Alpine — `php:8.3-fpm-alpine`

```dockerfile
FROM php:8.3-fpm-alpine

RUN apk add --no-cache $PHPIZE_DEPS linux-headers \
    && docker-php-ext-install sysvsem shmop \
    && pecl install igbinary

COPY ext/fast /usr/src/ext/fast
WORKDIR /usr/src/ext/fast
RUN phpize \
    && ./configure --enable-fast \
    && make -j"$(nproc)" \
    && make install \
    && rm -rf /usr/src/ext/fast

RUN { \
      echo 'extension=igbinary'; \
      echo 'extension=fast'; \
      echo 'fast.compat=0'; \
    } > /usr/local/etc/php/conf.d/99-fast.ini

RUN apk del $PHPIZE_DEPS

WORKDIR /var/www/html
```

### docker-compose

Large stores need enough shared memory:

```yaml
services:
  app:
    build: .
    shm_size: "256mb"
```

| Note | Detail |
|------|--------|
| Workers | All FPM children in one container share the same named store |
| Composer package | Optional when ext is loaded |
| PHP app + ext in different processes | Enable `fast.compat=1` on **both** sides |
| Smaller images | Multi-stage build: compile in a builder stage, copy `.so` + INI only |

---

## Native vs compat mode

| Mode | Setting | Who can read the store |
|------|---------|------------------------|
| **Native** (default) | `fast.compat=0` | ext-fast only |
| **Compat** | `fast.compat=1` | ext-fast **and** pure-PHP Fast |

Use native unless you must mix PHP and ext processes on the **same store name**.

Details: [`extension-layout-native.md`](extension-layout-native.md),
[`extension-compat.md`](extension-compat.md).

---

## PECL

When published:

```bash
pecl install fast
```

Until then, build from source. A [`package.xml`](../ext/fast/package.xml) is
included for packaging.

---

## Composer package

```bash
composer require johnnyjoy/fast
```

The Composer package is the portable default. The extension is an optional compile.
You do not need both at runtime when ext is enabled.

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| `igbinary` missing at startup | Install and enable igbinary **before** fast |
| Docker `make`: path `/home/.../fast.c` not found | Host `Makefile` in `COPY ext/fast` — use [`.dockerignore`](../.dockerignore) or clean `ext/fast` before build |
| Alpine: `igbinary_unserialize: symbol not found` | Load order: igbinary INI must come before fast (use one `99-fast.ini`, not separate `docker-php-ext-*.ini`) |
| Layout error opening store | Native vs PHP mismatch — use one backend or enable compat on both |
| `segment key … already in use` | Pick a different store `name` |
| Out of shared memory | Increase `shm_size` in Docker or lower `size` in config |
| FPM workers don't see cache | Enable `extension=fast` in **FPM** php.ini, not CLI only |

---

## Testing the extension

```bash
cd ext/fast && make
./run-phpt.sh
cd ../.. && FAST_BACKEND=ext FAST_EXT_SO=ext/fast/modules/fast.so php tests/run.php
```

Docker smoke (from repo root):

```bash
docker build -f docker/debian-fpm.Dockerfile -t fast-ext-debian-fpm .
docker build -f docker/alpine-fpm.Dockerfile -t fast-ext-alpine-fpm .
./docker/smoke.sh fast-ext-debian-fpm
./docker/smoke.sh fast-ext-alpine-fpm
```
