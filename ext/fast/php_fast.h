/*
  +----------------------------------------------------------------------+
  | Copyright (c) 2025-2026 James Dornan                                  |
  +----------------------------------------------------------------------+
  | Licensed under the MIT License, see the LICENSE file for details.    |
  +----------------------------------------------------------------------+
 */

#ifndef PHP_FAST_H
#define PHP_FAST_H

#define PHP_FAST_VERSION "1.0.0"

#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"

extern zend_module_entry fast_module_entry;
#define phpext_fast_ptr &fast_module_entry

#include "flat_native.h"
#include "striped_native.h"

/* INI */
extern zend_long fast_compat_ini;

/* class */
extern zend_class_entry *fast_ce;

extern const zend_function_entry fast_methods[];

void fast_handlers_init(void);

typedef enum {
	FAST_ENGINE_NONE = 0,
	FAST_ENGINE_LOCAL,
	FAST_ENGINE_NATIVE,
	FAST_ENGINE_STRIPED,
} fast_engine_kind;

typedef struct _fast_object {
	zend_object zo;
	fast_engine_kind kind;
	union {
		zend_array *local;
		fast_native_t *native;
		fast_striped_t *striped;
	} store;
	uint32_t iter_pos;
	bool closed;
	uint32_t cfg_slots;
	uint32_t cfg_size;
	uint32_t cfg_stripes;
	/* Deferred writeback for $f[$k]++ / nested mutation: read stages into pending_*,
	 * flush compares against pending_orig and writes only if the value changed. */
	zval pending_key;
	zval pending_val;
	zval pending_orig;
	bool pending_dirty;
	bool pending_key_set;
} fast_object;

static inline fast_object *fast_from_obj(zend_object *obj)
{
	return (fast_object *)((char *)(obj) - XtOffsetOf(fast_object, zo));
}

#define Z_FAST_P(zv) fast_from_obj(Z_OBJ_P(zv))

void fast_local_init(fast_object *obj);
bool fast_shared_init(fast_object *obj, zval *config);

zend_object *fast_create_object(zend_class_entry *ce);

PHP_MINIT_FUNCTION(fast);
PHP_MSHUTDOWN_FUNCTION(fast);
PHP_MINFO_FUNCTION(fast);

#endif
