/*
  +----------------------------------------------------------------------+
  | Copyright (c) 2025-2026 James Dornan                                  |
  +----------------------------------------------------------------------+
  | Licensed under the MIT License, see the LICENSE file for details.    |
  +----------------------------------------------------------------------+
 */

#ifndef FLAT_COMPAT_H
#define FLAT_COMPAT_H

#include "php.h"
#include "flat_native.h"

fast_native_t *fast_compat_attach(
	const char *name,
	size_t name_len,
	uint32_t size,
	uint32_t slots,
	bool persistent);

#endif
