# Canonical copy of docs/extension-install.md — Alpine FPM example.
# CI and local smoke: docker build -f docker/alpine-fpm.Dockerfile .
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
