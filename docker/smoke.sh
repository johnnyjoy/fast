#!/usr/bin/env bash
# Verify ext-fast inside a built FPM image (docs/extension-install.md).
set -euo pipefail

image="${1:?usage: smoke.sh <image-name>}"
script_dir="$(cd "$(dirname "$0")" && pwd)"

docker run --rm --shm-size=256m -v "${script_dir}/smoke.php:/tmp/smoke.php:ro" "$image" sh -c '
  php-fpm -t
  php -m | grep -E "^(fast|igbinary|sysvsem)$"
  php /tmp/smoke.php
'
