#!/usr/bin/env bash
# Run ext-fast phpt tests with igbinary loaded before fast (make test sets
# extension_dir=modules/ only, so the dependency is not found otherwise).
set -euo pipefail

root="$(cd "$(dirname "$0")" && pwd)"
cd "$root"

make -s all

igbinary="$(php-config --extension-dir)/igbinary.so"
fast="${root}/modules/fast.so"

if [[ ! -f "$igbinary" ]]; then
	echo "igbinary.so not found at $igbinary" >&2
	exit 1
fi
if [[ ! -f "$fast" ]]; then
	echo "fast.so not found at $fast (run make first)" >&2
	exit 1
fi

exec php -n \
	-d "extension=${igbinary}" \
	-d "extension=${fast}" \
	run-tests.php -n \
	-d "extension=${igbinary}" \
	-d "extension=${fast}" \
	tests/
