/*
  +----------------------------------------------------------------------+
  | Copyright (c) 2025-2026 James Dornan                                  |
  +----------------------------------------------------------------------+
  | Licensed under the MIT License, see the LICENSE file for details.    |
  +----------------------------------------------------------------------+
 */

#ifndef STRIPED_NATIVE_H
#define STRIPED_NATIVE_H

/* Striped coordinator: N independent native sub-stores, xxh3 key routing. */

#include "php.h"
#include "flat_native.h"

typedef struct fast_striped_s fast_striped_t;

fast_striped_t *fast_striped_attach(
	const char *name,
	size_t name_len,
	uint32_t size,
	uint32_t slots,
	bool persistent,
	int stripes);

void fast_striped_free(fast_striped_t *coord);

bool fast_striped_get(fast_striped_t *coord, zval *key, zval *val_out);
bool fast_striped_has(fast_striped_t *coord, zval *key);
void fast_striped_set(fast_striped_t *coord, zval *key, zval *val);
void fast_striped_delete(fast_striped_t *coord, zval *key);

zend_long fast_striped_count(fast_striped_t *coord);

void fast_striped_rewind(fast_striped_t *coord);
bool fast_striped_valid(fast_striped_t *coord);
void fast_striped_key(fast_striped_t *coord, zval *key_out);
void fast_striped_current(fast_striped_t *coord, zval *val_out);
void fast_striped_next(fast_striped_t *coord);
void fast_striped_seek(fast_striped_t *coord, zend_long pos);

void fast_striped_close(fast_striped_t *coord);

void fast_striped_lock(fast_striped_t *coord);

void fast_striped_unlock(fast_striped_t *coord);

const char *fast_striped_store_name(fast_striped_t *coord);

uint32_t fast_striped_directory_slots(fast_striped_t *coord);

uint32_t fast_striped_segment_size(fast_striped_t *coord);

int fast_striped_stripe_count(fast_striped_t *coord);

bool fast_striped_is_persistent(fast_striped_t *coord);

bool fast_striped_destroy_store(fast_striped_t *coord);

#endif
