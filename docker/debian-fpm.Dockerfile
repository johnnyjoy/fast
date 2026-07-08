# Canonical copy of docs/extension-install.md — Debian FPM example.
# CI and local smoke: docker build -f docker/debian-fpm.Dockerfile .
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

# One INI, correct order: igbinary must load before fast (Alpine/musl enforces this at dlopen).
RUN { \
      echo 'extension=igbinary'; \
      echo 'extension=fast'; \
      echo 'fast.compat=0'; \
    } > /usr/local/etc/php/conf.d/99-fast.ini

RUN apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
        $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
