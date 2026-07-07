/*
  +----------------------------------------------------------------------+
  | Copyright (c) 2025-2026 James Dornan                                  |
  +----------------------------------------------------------------------+
  | Licensed under the MIT License, see the LICENSE file for details.    |
  +----------------------------------------------------------------------+
 */

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

/*
 * Striped coordinator: splits capacity/size across N native sub-stores (name#i).
 * Keys route via xxh3; full-store iteration merges stripes by lowest order tag.
 */

#include "striped_native.h"
#include "flat_engine_internal.h"
#include "fast_layout.h"
#include "ext/hash/php_hash.h"
#include "ext/spl/spl_exceptions.h"
#include "zend_exceptions.h"

#include <stdio.h>
#include <string.h>

#define FAST_ROUTE_SHIFT 40  /* high bits of xxh3 digest pick the stripe index */

struct fast_striped_s {
	char *name;
	int stripes;
	uint32_t mask;
	uint32_t size;
	uint32_t slot_count;
	bool persistent;
	fast_native_t **subs;
	int cur_stripe;
};

static void fast_xxh3_digest(const unsigned char *data, size_t len, unsigned char digest[16])
{
	zend_string *algo = zend_string_init("xxh3", 4, 0);
	const php_hash_ops *ops = php_hash_fetch_ops(algo);
	void *ctx;

	zend_string_release(algo);
	if (!ops) {
		memset(digest, 0, 16);
		return;
	}
	ctx = emalloc(ops->context_size);
	ops->hash_init(ctx, NULL);
	ops->hash_update(ctx, data, len);
	ops->hash_final(digest, ctx);
	efree(ctx);
}

static uint64_t fast_digest_u64_le(const unsigned char digest[16])
{
	return (uint64_t)digest[0]
		| ((uint64_t)digest[1] << 8)
		| ((uint64_t)digest[2] << 16)
		| ((uint64_t)digest[3] << 24)
		| ((uint64_t)digest[4] << 32)
		| ((uint64_t)digest[5] << 40)
		| ((uint64_t)digest[6] << 48)
		| ((uint64_t)digest[7] << 56);
}

static uint32_t fast_striped_route(fast_striped_t *coord, zval *key)
{
	unsigned char digest[16];

	if (Z_TYPE_P(key) == IS_LONG) {
		int64_t v = (int64_t)Z_LVAL_P(key);
		char buf[9];

		buf[0] = '\0';
		memcpy(buf + 1, &v, 8);
		fast_xxh3_digest((const unsigned char *)buf, 9, digest);
	} else if (Z_TYPE_P(key) == IS_STRING) {
		zend_string *prefixed = zend_string_alloc(1 + Z_STRLEN_P(key), 0);

		ZSTR_VAL(prefixed)[0] = '\1';
		memcpy(ZSTR_VAL(prefixed) + 1, Z_STRVAL_P(key), Z_STRLEN_P(key));
		ZSTR_VAL(prefixed)[ZSTR_LEN(prefixed)] = '\0';
		fast_xxh3_digest((const unsigned char *)ZSTR_VAL(prefixed), ZSTR_LEN(prefixed), digest);
		zend_string_release(prefixed);
	} else {
		return 0;
	}

	return (uint32_t)((fast_digest_u64_le(digest) >> FAST_ROUTE_SHIFT) & coord->mask);
}

static char *fast_striped_subname(const char *name, int stripe, size_t *len_out)
{
	char buf[256];
	int n = snprintf(buf, sizeof(buf), "%s#%d", name, stripe);

	if (n < 0 || (size_t)n >= sizeof(buf)) {
		return NULL;
	}
	*len_out = (size_t)n;
	return estrndup(buf, (size_t)n);
}

static void fast_striped_pick(fast_striped_t *coord)
{
	int best = -1;
	uint64_t best_tag = 0;
	int i;

	/* Cross-stripe iteration: advance the stripe with the smallest order-log tag. */
	for (i = 0; i < coord->stripes; i++) {
		fast_native_t *sub = coord->subs[i];

		if (!fast_native_valid(sub)) {
			continue;
		}
		{
			uint64_t tag = fast_native_iter_tag(sub);
			if (best < 0 || tag < best_tag) {
				best = i;
				best_tag = tag;
			}
		}
	}
	coord->cur_stripe = best;
}

fast_striped_t *fast_striped_attach(
	const char *name, size_t name_len, uint32_t size, uint32_t slots, bool persistent, int stripes)
{
	uint32_t per_slots;
	uint32_t per_size;
	uint32_t min_per;
	int i;

	if (stripes < 2 || (stripes & (stripes - 1)) != 0) {
		zend_throw_exception_ex(zend_ce_value_error, 0, "stripes must be a power of two >= 2");
		return NULL;
	}
	if ((slots & (slots - 1)) != 0 || slots < (uint32_t)stripes) {
		zend_throw_exception_ex(zend_ce_value_error, 0, "capacity must be a power of two >= stripes");
		return NULL;
	}

	per_slots = slots / (uint32_t)stripes;
	per_size = size / (uint32_t)stripes;
	min_per = fast_min_segment_size(per_slots, FAST_ORDER_TAGGED);
	if (per_size < min_per) {
		zend_throw_exception_ex(zend_ce_value_error, 0,
			"size %u split across %d stripes is %u bytes/stripe, below the %u minimum for "
			"%u slots/stripe (capacity %u / %d); increase size to at least %u or reduce stripes/capacity",
			size, stripes, per_size, min_per, per_slots, slots, stripes, min_per * (uint32_t)stripes);
		return NULL;
	}

	fast_striped_t *coord = ecalloc(1, sizeof(fast_striped_t));
	coord->name = estrndup(name, name_len);
	coord->stripes = stripes;
	coord->mask = (uint32_t)stripes - 1U;
	coord->size = size;
	coord->slot_count = slots;
	coord->subs = ecalloc((size_t)stripes, sizeof(fast_native_t *));
	coord->cur_stripe = -1;

	for (i = 0; i < stripes; i++) {
		size_t sub_len = 0;
		char *subname = fast_striped_subname(name, i, &sub_len);

		if (!subname) {
			fast_striped_free(coord);
			zend_throw_exception(NULL, "striped sub-store name too long", 0);
			return NULL;
		}
		coord->subs[i] = fast_native_attach(
			subname, sub_len, per_size, per_slots, persistent, false, FAST_ORDER_TAGGED);
		efree(subname);
		if (!coord->subs[i]) {
			fast_striped_free(coord);
			return NULL;
		}
		if (i == 0) {
			coord->persistent = coord->subs[0]->persistent;
		}
	}

	return coord;
}

void fast_striped_free(fast_striped_t *coord)
{
	int i;

	if (!coord) {
		return;
	}
	if (coord->subs) {
		for (i = 0; i < coord->stripes; i++) {
			if (coord->subs[i]) {
				fast_native_free(coord->subs[i]);
			}
		}
		efree(coord->subs);
	}
	if (coord->name) {
		efree(coord->name);
	}
	efree(coord);
}

bool fast_striped_get(fast_striped_t *coord, zval *key, zval *val_out)
{
	uint32_t stripe = fast_striped_route(coord, key);
	return fast_native_get(coord->subs[stripe], key, val_out);
}

bool fast_striped_has(fast_striped_t *coord, zval *key)
{
	uint32_t stripe = fast_striped_route(coord, key);
	return fast_native_has(coord->subs[stripe], key);
}

void fast_striped_set(fast_striped_t *coord, zval *key, zval *val)
{
	uint32_t stripe = fast_striped_route(coord, key);
	fast_native_set(coord->subs[stripe], key, val);
}

void fast_striped_delete(fast_striped_t *coord, zval *key)
{
	uint32_t stripe = fast_striped_route(coord, key);
	fast_native_delete(coord->subs[stripe], key);
}

zend_long fast_striped_count(fast_striped_t *coord)
{
	zend_long total = 0;
	int i;

	for (i = 0; i < coord->stripes; i++) {
		total += fast_native_count(coord->subs[i]);
	}
	return total;
}

void fast_striped_rewind(fast_striped_t *coord)
{
	int i;

	for (i = 0; i < coord->stripes; i++) {
		fast_native_rewind(coord->subs[i]);
	}
	fast_striped_pick(coord);
}

bool fast_striped_valid(fast_striped_t *coord)
{
	return coord->cur_stripe >= 0;
}

void fast_striped_key(fast_striped_t *coord, zval *key_out)
{
	if (coord->cur_stripe >= 0) {
		fast_native_key(coord->subs[coord->cur_stripe], key_out);
	} else {
		ZVAL_NULL(key_out);
	}
}

void fast_striped_current(fast_striped_t *coord, zval *val_out)
{
	if (coord->cur_stripe >= 0) {
		fast_native_current(coord->subs[coord->cur_stripe], val_out);
	} else {
		ZVAL_NULL(val_out);
	}
}

void fast_striped_next(fast_striped_t *coord)
{
	if (coord->cur_stripe >= 0) {
		fast_native_next(coord->subs[coord->cur_stripe]);
	}
	fast_striped_pick(coord);
}

void fast_striped_seek(fast_striped_t *coord, zend_long pos)
{
	zend_long i;

	if (pos < 0) {
		zend_throw_exception_ex(spl_ce_OutOfBoundsException, 0, "Seek position %ld is out of range", pos);
		return;
	}

	fast_striped_rewind(coord);
	for (i = 0; i < pos; i++) {
		if (!fast_striped_valid(coord)) {
			zend_throw_exception_ex(spl_ce_OutOfBoundsException, 0, "Seek position %ld is out of range", pos);
			return;
		}
		fast_striped_next(coord);
	}
	if (!fast_striped_valid(coord)) {
		zend_throw_exception_ex(spl_ce_OutOfBoundsException, 0, "Seek position %ld is out of range", pos);
	}
}

void fast_striped_close(fast_striped_t *coord)
{
	int i;
	zend_object *ex = EG(exception);

	for (i = 0; i < coord->stripes; i++) {
		fast_native_close(coord->subs[i]);
		if (EG(exception) && !ex) {
			ex = EG(exception);
		}
	}
	if (ex && !EG(exception)) {
		EG(exception) = ex;
	}
}

bool fast_striped_destroy_store(fast_striped_t *coord)
{
	int i;

	for (i = 0; i < coord->stripes; i++) {
		if (!fast_engine_is_sole_connection(coord->subs[i])) {
			zend_throw_exception_ex(spl_ce_RuntimeException, 0,
				"cannot destroy striped Fast \"%s\": another process is still connected to stripe %d",
				coord->name, i);
			return false;
		}
	}

	for (i = 0; i < coord->stripes; i++) {
		fast_native_destroy_store_force(coord->subs[i]);
	}
	return true;
}

void fast_striped_lock(fast_striped_t *coord)
{
	int i;

	for (i = 0; i < coord->stripes; i++) {
		fast_native_lock(coord->subs[i]);
	}
}

void fast_striped_unlock(fast_striped_t *coord)
{
	int i;

	for (i = coord->stripes - 1; i >= 0; i--) {
		fast_native_unlock(coord->subs[i]);
	}
}

const char *fast_striped_store_name(fast_striped_t *coord)
{
	return coord ? coord->name : NULL;
}

uint32_t fast_striped_directory_slots(fast_striped_t *coord)
{
	return coord ? coord->slot_count : 0;
}

uint32_t fast_striped_segment_size(fast_striped_t *coord)
{
	return coord ? coord->size : 0;
}

int fast_striped_stripe_count(fast_striped_t *coord)
{
	return coord ? coord->stripes : 0;
}

bool fast_striped_is_persistent(fast_striped_t *coord)
{
	return coord ? coord->persistent : false;
}
