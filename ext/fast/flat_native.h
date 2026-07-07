/*
  +----------------------------------------------------------------------+
  | Copyright (c) 2025-2026 James Dornan                                  |
  +----------------------------------------------------------------------+
  | Licensed under the MIT License, see the LICENSE file for details.    |
  +----------------------------------------------------------------------+
 */

#ifndef FLAT_NATIVE_H
#define FLAT_NATIVE_H

/* Native / compat shared Flat engine (get/set/has, lifecycle, iteration). */

#include "php.h"

typedef struct fast_native_s fast_native_t;

fast_native_t *fast_native_attach(
	const char *name,
	size_t name_len,
	uint32_t size,
	uint32_t slots,
	bool persistent,
	bool compat_layout,
	uint32_t order_bytes);

void fast_native_free(fast_native_t *eng);

void fast_native_close(fast_native_t *eng);

bool fast_native_destroy_store(fast_native_t *eng);

void fast_native_minit(void);
void fast_native_mshutdown(void);

bool fast_native_get(fast_native_t *eng, zval *key, zval *value_out);

bool fast_native_has(fast_native_t *eng, zval *key);

void fast_native_set(fast_native_t *eng, zval *key, zval *value);

void fast_native_delete(fast_native_t *eng, zval *key);

zend_long fast_native_count(fast_native_t *eng);

void fast_native_rewind(fast_native_t *eng);

bool fast_native_valid(fast_native_t *eng);

void fast_native_key(fast_native_t *eng, zval *key_out);

void fast_native_current(fast_native_t *eng, zval *value_out);

void fast_native_next(fast_native_t *eng);

void fast_native_seek(fast_native_t *eng, zend_long pos);

uint32_t fast_native_live_count(fast_native_t *eng);

uint64_t fast_native_iter_tag(fast_native_t *eng);

void fast_native_lock(fast_native_t *eng);

void fast_native_unlock(fast_native_t *eng);

const char *fast_native_store_name(fast_native_t *eng);

uint32_t fast_native_directory_slots(fast_native_t *eng);

uint32_t fast_native_segment_size(fast_native_t *eng);

bool fast_native_is_persistent(fast_native_t *eng);

bool fast_engine_is_sole_connection(fast_native_t *eng);

bool fast_native_destroy_store_force(fast_native_t *eng);

#endif
