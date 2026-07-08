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

#include "php_fast.h"
#include "fast_layout.h"
#include "flat_compat.h"
#include "striped_native.h"
#include "zend_interfaces.h"
#include "Zend/zend_interfaces_arginfo.h"
#include "Zend/zend_closures.h"
#include "zend_exceptions.h"
#include "ext/spl/spl_exceptions.h"
#include "ext/standard/php_var.h"
#include "ext/standard/basic_functions.h"
#include <string.h>

/*
 * \Fast object handlers and method implementations.
 * Custom read/write_dimension and read/write_property implement pending writeback
 * and copy-on-read for magic properties (see php_fast.h pending_* fields).
 */

static void fast_assert_open(fast_object *obj);
static void fast_assert_valid_offset(zval *key);
static void fast_flush_pending(fast_object *obj);
static void fast_pending_reset(fast_object *obj);
static void fast_obj_teardown_store(fast_object *obj);
static void fast_build_stats(fast_object *obj, zval *out);
static bool fast_validate_each_callable(zval *fn);
static zend_long fast_obj_count(fast_object *obj);
static void fast_obj_init_fields(fast_object *obj);
static void fast_local_update(zend_array *arr, zval *key, zval *value);
static uint32_t fast_local_count(zend_array *arr);
static bool fast_local_key_at(zend_array *arr, uint32_t pos, zval *key_out);
static void fast_local_unset(zend_array *arr, zval *key);

void fast_local_init(fast_object *obj)
{
	obj->kind = FAST_ENGINE_LOCAL;
	obj->store.local = zend_new_array(0);
	obj->iter_pos = 0;
	obj->closed = false;
	obj->cfg_slots = 0;
	obj->cfg_size = 0;
	obj->cfg_stripes = 1;
	fast_pending_reset(obj);
}

static void fast_throw_closed(void)
{
	zend_throw_exception(NULL, "Fast handle is closed: close() released this store connection; "
		"create a new Fast to open the store again", 0);
}

static void fast_pending_reset(fast_object *obj)
{
	if (obj->pending_key_set) {
		zval_ptr_dtor(&obj->pending_key);
	}
	if (obj->pending_dirty) {
		zval_ptr_dtor(&obj->pending_val);
		zval_ptr_dtor(&obj->pending_orig);
	}
	ZVAL_NULL(&obj->pending_key);
	ZVAL_NULL(&obj->pending_val);
	ZVAL_NULL(&obj->pending_orig);
	obj->pending_dirty = false;
	obj->pending_key_set = false;
}

static void fast_obj_init_fields(fast_object *obj)
{
	obj->kind = FAST_ENGINE_NONE;
	obj->store.local = NULL;
	obj->iter_pos = 0;
	obj->closed = false;
	obj->cfg_slots = 0;
	obj->cfg_size = 0;
	obj->cfg_stripes = 1;
	obj->pending_dirty = false;
	obj->pending_key_set = false;
	ZVAL_NULL(&obj->pending_key);
	ZVAL_NULL(&obj->pending_val);
	ZVAL_NULL(&obj->pending_orig);
}

static void fast_assert_valid_offset(zval *key)
{
	if (Z_TYPE_P(key) != IS_LONG && Z_TYPE_P(key) != IS_STRING) {
		zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
			"array offset must be int|string");
	}
}

static zval *fast_pending_val(fast_object *obj)
{
	if (Z_ISREF(obj->pending_val)) {
		return Z_REFVAL(obj->pending_val);
	}
	return &obj->pending_val;
}

static void fast_flush_pending(fast_object *obj)
{
	zval cmp_zv;
	zval *val;

	if (!obj->pending_dirty || !obj->pending_key_set) {
		return;
	}

	val = fast_pending_val(obj);
	ZVAL_LONG(&cmp_zv, 0);
	if (compare_function(&cmp_zv, val, &obj->pending_orig) == SUCCESS && Z_LVAL(cmp_zv) != 0) {
		if (obj->kind == FAST_ENGINE_STRIPED) {
			fast_striped_set(obj->store.striped, &obj->pending_key, val);
		} else if (obj->kind == FAST_ENGINE_NATIVE) {
			fast_native_set(obj->store.native, &obj->pending_key, val);
		} else if (obj->store.local) {
			fast_local_update(obj->store.local, &obj->pending_key, val);
		}
	}

	zval_ptr_dtor(&cmp_zv);
	obj->pending_dirty = false;
	obj->pending_key_set = false;
	zval_ptr_dtor(&obj->pending_key);
	zval_ptr_dtor(&obj->pending_val);
	zval_ptr_dtor(&obj->pending_orig);
	ZVAL_NULL(&obj->pending_key);
	ZVAL_NULL(&obj->pending_val);
	ZVAL_NULL(&obj->pending_orig);
}

static void fast_obj_teardown_store(fast_object *obj)
{
	fast_flush_pending(obj);
	if (obj->kind == FAST_ENGINE_LOCAL && obj->store.local) {
		zend_array_destroy(obj->store.local);
		obj->store.local = NULL;
	} else if (obj->kind == FAST_ENGINE_STRIPED && obj->store.striped) {
		fast_striped_free(obj->store.striped);
		obj->store.striped = NULL;
	} else if (obj->kind == FAST_ENGINE_NATIVE && obj->store.native) {
		fast_native_free(obj->store.native);
		obj->store.native = NULL;
	}
	obj->kind = FAST_ENGINE_NONE;
}

static zend_long fast_obj_count(fast_object *obj)
{
	if (obj->closed) {
		return 0;
	}
	if (obj->kind == FAST_ENGINE_STRIPED) {
		return fast_striped_count(obj->store.striped);
	}
	if (obj->kind == FAST_ENGINE_NATIVE) {
		return fast_native_count(obj->store.native);
	}
	return (zend_long)fast_local_count(obj->store.local);
}

static void fast_build_stats(fast_object *obj, zval *out)
{
	bool shared = obj->kind == FAST_ENGINE_NATIVE || obj->kind == FAST_ENGINE_STRIPED;
	const char *name = NULL;
	uint32_t slots = obj->cfg_slots;
	uint32_t size = obj->cfg_size;
	bool persistent = false;

	array_init(out);
	if (shared) {
		if (obj->kind == FAST_ENGINE_STRIPED) {
			name = fast_striped_store_name(obj->store.striped);
			slots = fast_striped_directory_slots(obj->store.striped);
			size = fast_striped_segment_size(obj->store.striped);
			persistent = fast_striped_is_persistent(obj->store.striped);
		} else {
			name = fast_native_store_name(obj->store.native);
			slots = fast_native_directory_slots(obj->store.native);
			size = fast_native_segment_size(obj->store.native);
			persistent = fast_native_is_persistent(obj->store.native);
		}
	}

	add_assoc_bool(out, "shared", shared);
	if (shared && name) {
		add_assoc_string(out, "name", (char *)name);
	} else {
		add_assoc_null(out, "name");
	}
	add_assoc_long(out, "count", obj->closed ? 0 : fast_obj_count(obj));
	add_assoc_long(out, "directory_slots", (zend_long)slots);
	add_assoc_bool(out, "persistent", persistent);
	add_assoc_long(out, "shared_size", (zend_long)size);
}

static void fast_assert_open(fast_object *obj)
{
	if (obj->closed) {
		fast_throw_closed();
	}
}

static bool fast_config_has_unknown_keys(zend_array *arr)
{
	zval *val;
	zend_string *key;

	ZEND_HASH_FOREACH_STR_KEY_VAL(arr, key, val) {
		if (!key) {
			continue;
		}
		if (
			zend_string_equals_literal(key, "name")
			|| zend_string_equals_literal(key, "capacity")
			|| zend_string_equals_literal(key, "size")
			|| zend_string_equals_literal(key, "persistent")
			|| zend_string_equals_literal(key, "stripes")
			|| zend_string_equals_literal(key, "compat")
		) {
			continue;
		}
		zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
			"Unsupported Fast config key: %s", ZSTR_VAL(key));
		return true;
	} ZEND_HASH_FOREACH_END();

	return false;
}

bool fast_shared_init(fast_object *obj, zval *config)
{
	const char *name = NULL;
	size_t name_len = 0;
	uint32_t size = FAST_DEFAULT_SIZE;
	uint32_t slots = FAST_DEFAULT_SLOTS;
	uint32_t stripes = 1;
	bool persistent = false;
	bool compat = fast_compat_ini != 0;

	if (Z_TYPE_P(config) == IS_STRING) {
		name = Z_STRVAL_P(config);
		name_len = Z_STRLEN_P(config);
	} else if (Z_TYPE_P(config) == IS_ARRAY) {
		zend_array *cfg = Z_ARRVAL_P(config);
		if (fast_config_has_unknown_keys(cfg)) {
			return false;
		}
		zval *nv = zend_hash_str_find(cfg, "name", sizeof("name") - 1);
		if (nv && Z_TYPE_P(nv) == IS_STRING) {
			name = Z_STRVAL_P(nv);
			name_len = Z_STRLEN_P(nv);
		}
		zval *sv = zend_hash_str_find(cfg, "size", sizeof("size") - 1);
		if (sv && Z_TYPE_P(sv) == IS_LONG) {
			size = (uint32_t)Z_LVAL_P(sv);
		}
		zval *cv = zend_hash_str_find(cfg, "capacity", sizeof("capacity") - 1);
		if (cv && Z_TYPE_P(cv) == IS_LONG) {
			slots = (uint32_t)Z_LVAL_P(cv);
		}
		zval *pv = zend_hash_str_find(cfg, "persistent", sizeof("persistent") - 1);
		if (pv && (Z_TYPE_P(pv) == IS_TRUE || Z_TYPE_P(pv) == IS_FALSE)) {
			persistent = Z_TYPE_P(pv) == IS_TRUE;
		}
		zval *cp = zend_hash_str_find(cfg, "compat", sizeof("compat") - 1);
		if (cp && (Z_TYPE_P(cp) == IS_TRUE || Z_TYPE_P(cp) == IS_FALSE)) {
			compat = Z_TYPE_P(cp) == IS_TRUE;
		}
		zval *st = zend_hash_str_find(cfg, "stripes", sizeof("stripes") - 1);
		if (st && Z_TYPE_P(st) == IS_LONG) {
			if (Z_LVAL_P(st) < 1) {
				zend_throw_exception_ex(zend_ce_value_error, 0, "stripes must be >= 1");
				return false;
			}
			stripes = (uint32_t)Z_LVAL_P(st);
		}
	} else {
		zend_throw_exception_ex(zend_ce_value_error, 0,
			"Fast constructor expects string or array config");
		return false;
	}

	if (!name || name_len == 0) {
		if (stripes > 1) {
			zend_throw_exception_ex(zend_ce_value_error, 0, "stripes requires a named shared store");
			return false;
		}
		fast_local_init(obj);
		return true;
	}

	if (stripes > 1) {
		if (compat) {
			zend_throw_exception_ex(zend_ce_value_error, 0,
				"stripes is not supported with compat mode");
			return false;
		}
		obj->store.striped = fast_striped_attach(name, name_len, size, slots, persistent, (int)stripes);
		if (!obj->store.striped) {
			return false;
		}
		obj->kind = FAST_ENGINE_STRIPED;
		obj->iter_pos = 0;
		obj->closed = false;
		obj->cfg_slots = slots;
		obj->cfg_size = size;
		obj->cfg_stripes = stripes;
		fast_striped_rewind(obj->store.striped);
		return true;
	}

	obj->store.native = compat
		? fast_compat_attach(name, name_len, size, slots, persistent)
		: fast_native_attach(name, name_len, size, slots, persistent, false, FAST_ORDER);
	if (!obj->store.native) {
		return false;
	}
	obj->kind = FAST_ENGINE_NATIVE;
	obj->iter_pos = 0;
	obj->closed = false;
	obj->cfg_slots = slots;
	obj->cfg_size = size;
	obj->cfg_stripes = 1;
	fast_native_rewind(obj->store.native);
	return true;
}

static void fast_parse_construct(zval *self, zval *config)
{
	fast_object *obj = Z_FAST_P(self);

	if (!config || Z_TYPE_P(config) == IS_NULL) {
		fast_local_init(obj);
		return;
	}

	if (Z_TYPE_P(config) == IS_STRING && Z_STRLEN_P(config) == 0) {
		fast_local_init(obj);
		return;
	}

	if (Z_TYPE_P(config) == IS_ARRAY && zend_array_count(Z_ARRVAL_P(config)) == 0) {
		fast_local_init(obj);
		return;
	}

	fast_shared_init(obj, config);
}

static uint32_t fast_local_count(zend_array *arr)
{
	return (uint32_t)zend_array_count(arr);
}

static bool fast_local_key_at(zend_array *arr, uint32_t pos, zval *key_out)
{
	zend_ulong h;
	zend_string *key;
	zval *val;
	uint32_t i = 0;

	ZEND_HASH_FOREACH_KEY_VAL(arr, h, key, val) {
		if (i++ != pos) {
			continue;
		}
		if (key) {
			ZVAL_STR_COPY(key_out, key);
		} else {
			ZVAL_LONG(key_out, (zend_long)h);
		}
		return true;
	} ZEND_HASH_FOREACH_END();

	return false;
}

static void fast_local_unset(zend_array *arr, zval *key)
{
	switch (Z_TYPE_P(key)) {
		case IS_STRING:
			zend_hash_del(arr, Z_STR_P(key));
			break;
		case IS_LONG:
			zend_hash_index_del(arr, Z_LVAL_P(key));
			break;
		default:
			break;
	}
}

static zval *fast_local_find(zend_array *arr, zval *key)
{
	switch (Z_TYPE_P(key)) {
		case IS_STRING:
			return zend_hash_find(arr, Z_STR_P(key));
		case IS_LONG:
			return zend_hash_index_find(arr, Z_LVAL_P(key));
		default:
			return NULL;
	}
}

static bool fast_local_exists(zend_array *arr, zval *key)
{
	switch (Z_TYPE_P(key)) {
		case IS_STRING:
			return zend_hash_exists(arr, Z_STR_P(key));
		case IS_LONG:
			return zend_hash_index_exists(arr, Z_LVAL_P(key));
		default:
			return false;
	}
}

static void fast_local_update(zend_array *arr, zval *key, zval *value)
{
	zval stored;

	/* Detach and copy so writes never alias engine-owned zvals. */
	ZVAL_COPY_DEREF(&stored, value);
	zval_copy_ctor(&stored);
	switch (Z_TYPE_P(key)) {
		case IS_STRING:
			zend_hash_update(arr, Z_STR_P(key), &stored);
			break;
		case IS_LONG:
			zend_hash_index_update(arr, Z_LVAL_P(key), &stored);
			break;
		default:
			zval_ptr_dtor(&stored);
			break;
	}
}

static zend_array *fast_data(fast_object *obj)
{
	return obj->kind == FAST_ENGINE_LOCAL ? obj->store.local : NULL;
}

static zval *fast_prepare_pending_read(fast_object *obj, zval *offset)
{
	if (obj->closed) {
		fast_throw_closed();
		return NULL;
	}
	if (!offset || Z_TYPE_P(offset) == IS_NULL) {
		zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
			"array offset must be int|string");
		return NULL;
	}
	fast_assert_valid_offset(offset);
	if (EG(exception)) {
		return NULL;
	}
	if (obj->pending_dirty) {
		fast_flush_pending(obj);
		if (EG(exception)) {
			return NULL;
		}
	}

	zval_ptr_dtor(&obj->pending_key);
	zval_ptr_dtor(&obj->pending_val);
	zval_ptr_dtor(&obj->pending_orig);
	ZVAL_COPY(&obj->pending_key, offset);
	obj->pending_key_set = true;
	obj->pending_dirty = true;

	if (obj->kind == FAST_ENGINE_STRIPED) {
		if (fast_striped_get(obj->store.striped, offset, &obj->pending_val)) {
			ZVAL_COPY(&obj->pending_orig, &obj->pending_val);
			return &obj->pending_val;
		}
	} else if (obj->kind == FAST_ENGINE_NATIVE) {
		if (fast_native_get(obj->store.native, offset, &obj->pending_val)) {
			ZVAL_COPY(&obj->pending_orig, &obj->pending_val);
			return &obj->pending_val;
		}
	} else if (obj->store.local) {
		zval *val = fast_local_find(obj->store.local, offset);
		if (val) {
			ZVAL_COPY_DEREF(&obj->pending_val, val);
			ZVAL_COPY(&obj->pending_orig, &obj->pending_val);
			return &obj->pending_val;
		}
	}

	ZVAL_NULL(&obj->pending_val);
	ZVAL_NULL(&obj->pending_orig);
	return &obj->pending_val;
}

static zval *fast_read_dimension(zend_object *object, zval *offset, int type, zval *rv)
{
	zval *ret = fast_prepare_pending_read(fast_from_obj(object), offset);

	(void)rv;
	if (!ret) {
		return &EG(uninitialized_zval);
	}
	/* BP_VAR_W/RW/UNSET requires a real ref for in-place mutation ($f['k']++). */
	if (type == BP_VAR_W || type == BP_VAR_RW || type == BP_VAR_UNSET) {
		if (!Z_ISREF_P(ret)) {
			ZVAL_MAKE_REF(ret);
		}
	}
	return ret;
}

static void fast_write_dimension(zend_object *object, zval *offset, zval *value)
{
	fast_object *obj = fast_from_obj(object);
	zval key, val_copy;

	if (obj->closed) {
		fast_throw_closed();
		return;
	}
	if (!offset || Z_TYPE_P(offset) == IS_NULL) {
		zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
			"array offset must be int|string");
		return;
	}
	fast_assert_valid_offset(offset);
	if (EG(exception)) {
		return;
	}
	if (obj->pending_dirty) {
		fast_flush_pending(obj);
		if (EG(exception)) {
			return;
		}
	}

	ZVAL_COPY(&key, offset);
	ZVAL_COPY_DEREF(&val_copy, value);
	if (obj->kind == FAST_ENGINE_STRIPED) {
		fast_striped_set(obj->store.striped, &key, &val_copy);
	} else if (obj->kind == FAST_ENGINE_NATIVE) {
		fast_native_set(obj->store.native, &key, &val_copy);
	} else {
		fast_local_update(obj->store.local, &key, &val_copy);
	}
	zval_ptr_dtor(&val_copy);
	zval_ptr_dtor(&key);
}

static void fast_unset_dimension(zend_object *object, zval *offset)
{
	fast_object *obj = fast_from_obj(object);

	if (obj->closed) {
		fast_throw_closed();
		return;
	}
	if (!offset || Z_TYPE_P(offset) == IS_NULL) {
		zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
			"array offset must be int|string");
		return;
	}
	fast_assert_valid_offset(offset);
	if (EG(exception)) {
		return;
	}
	if (obj->pending_dirty) {
		fast_flush_pending(obj);
		if (EG(exception)) {
			return;
		}
	}
	if (obj->kind == FAST_ENGINE_STRIPED) {
		fast_striped_delete(obj->store.striped, offset);
	} else if (obj->kind == FAST_ENGINE_NATIVE) {
		fast_native_delete(obj->store.native, offset);
	} else {
		fast_local_unset(obj->store.local, offset);
	}
}

static zval *fast_read_property(zend_object *object, zend_string *name, int type, void **cache_slot, zval *rv)
{
	zval key;
	fast_object *obj = fast_from_obj(object);
	zval *ret;

	(void)cache_slot;
	ZVAL_STR(&key, name);
	ret = fast_prepare_pending_read(obj, &key);
	zval_ptr_dtor(&key);
	if (!ret) {
		return &EG(uninitialized_zval);
	}
	if (type == BP_VAR_W || type == BP_VAR_RW || type == BP_VAR_UNSET) {
		if (!Z_ISREF_P(ret)) {
			ZVAL_MAKE_REF(ret);
		}
		return ret;
	}
	/* Read context: return a copy so __get results do not alias pending_val. */
	ZVAL_COPY(rv, fast_pending_val(obj));
	return rv;
}

static zval *fast_write_property(zend_object *object, zend_string *name, zval *value, void **cache_slot)
{
	zval key;

	(void)cache_slot;
	ZVAL_STR(&key, name);
	fast_write_dimension(object, &key, value);
	zval_ptr_dtor(&key);
	return value;
}

static void fast_unset_property(zend_object *object, zend_string *name, void **cache_slot)
{
	zval key;

	(void)cache_slot;
	ZVAL_STR(&key, name);
	fast_unset_dimension(object, &key);
	zval_ptr_dtor(&key);
}

static zend_object_handlers fast_object_handlers;

static void fast_free_obj(zend_object *object)
{
	fast_object *obj = fast_from_obj(object);
	fast_pending_reset(obj);
	if (obj->kind == FAST_ENGINE_LOCAL && obj->store.local) {
		zend_array_destroy(obj->store.local);
		obj->store.local = NULL;
	} else if (obj->kind == FAST_ENGINE_STRIPED && obj->store.striped) {
		fast_striped_free(obj->store.striped);
		obj->store.striped = NULL;
	} else if (obj->kind == FAST_ENGINE_NATIVE && obj->store.native) {
		fast_native_free(obj->store.native);
		obj->store.native = NULL;
	}
	obj->kind = FAST_ENGINE_NONE;
	zend_object_std_dtor(object);
}

zend_object *fast_create_object(zend_class_entry *ce)
{
	fast_object *obj = emalloc(sizeof(fast_object));
	memset(obj, 0, sizeof(fast_object));
	zend_object_std_init(&obj->zo, ce);
	object_properties_init(&obj->zo, ce);
	fast_obj_init_fields(obj);
	obj->zo.handlers = &fast_object_handlers;
	return &obj->zo;
}

PHP_METHOD(Fast, __construct)
{
	zval *config = NULL;
	ZEND_PARSE_PARAMETERS_START(0, 1)
		Z_PARAM_OPTIONAL
		Z_PARAM_ZVAL(config)
	ZEND_PARSE_PARAMETERS_END();
	fast_parse_construct(getThis(), config);
}

PHP_METHOD(Fast, offsetExists)
{
	zval *key;
	fast_object *obj = Z_FAST_P(getThis());
	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ZVAL(key)
	ZEND_PARSE_PARAMETERS_END();
	fast_assert_open(obj);
	if (EG(exception)) {
		RETURN_FALSE;
	}
	fast_assert_valid_offset(key);
	if (EG(exception)) {
		RETURN_FALSE;
	}
	fast_flush_pending(obj);
	if (EG(exception)) {
		RETURN_FALSE;
	}
	if (obj->kind == FAST_ENGINE_STRIPED) {
		RETURN_BOOL(fast_striped_has(obj->store.striped, key));
	}
	if (obj->kind == FAST_ENGINE_NATIVE) {
		RETURN_BOOL(fast_native_has(obj->store.native, key));
	}
	if (!fast_data(obj) || !fast_local_exists(obj->store.local, key)) {
		RETURN_FALSE;
	}
	zval *val = fast_local_find(obj->store.local, key);
	RETURN_BOOL(val && Z_TYPE_P(val) != IS_NULL);
}

PHP_METHOD(Fast, offsetGet)
{
	zval *key;
	fast_object *obj = Z_FAST_P(getThis());
	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ZVAL(key)
	ZEND_PARSE_PARAMETERS_END();
	fast_assert_open(obj);
	if (EG(exception)) {
		return;
	}
	fast_assert_valid_offset(key);
	if (EG(exception)) {
		return;
	}
	if (obj->pending_dirty) {
		fast_flush_pending(obj);
		if (EG(exception)) {
			return;
		}
	}

	zval_ptr_dtor(&obj->pending_key);
	zval_ptr_dtor(&obj->pending_val);
	zval_ptr_dtor(&obj->pending_orig);
	ZVAL_COPY(&obj->pending_key, key);
	obj->pending_key_set = true;
	obj->pending_dirty = true;

	if (obj->kind == FAST_ENGINE_STRIPED) {
		if (fast_striped_get(obj->store.striped, key, &obj->pending_val)) {
			ZVAL_COPY(&obj->pending_orig, &obj->pending_val);
			RETURN_ZVAL(&obj->pending_val, 0, 0);
		}
	} else if (obj->kind == FAST_ENGINE_NATIVE) {
		if (fast_native_get(obj->store.native, key, &obj->pending_val)) {
			ZVAL_COPY(&obj->pending_orig, &obj->pending_val);
			RETURN_ZVAL(&obj->pending_val, 0, 0);
		}
	} else {
		zval *val = fast_local_find(obj->store.local, key);
		if (val) {
			ZVAL_COPY_DEREF(&obj->pending_val, val);
			ZVAL_COPY(&obj->pending_orig, &obj->pending_val);
			RETURN_ZVAL(&obj->pending_val, 0, 0);
		}
	}

	ZVAL_NULL(&obj->pending_val);
	ZVAL_NULL(&obj->pending_orig);
	RETURN_ZVAL(&obj->pending_val, 0, 0);
}

PHP_METHOD(Fast, offsetSet)
{
	zval *key, *value;
	fast_object *obj = Z_FAST_P(getThis());
	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_ZVAL(key)
		Z_PARAM_ZVAL(value)
	ZEND_PARSE_PARAMETERS_END();
	fast_assert_open(obj);
	if (EG(exception)) {
		return;
	}
	fast_assert_valid_offset(key);
	if (EG(exception)) {
		return;
	}
	if (obj->pending_dirty) {
		fast_flush_pending(obj);
		if (EG(exception)) {
			return;
		}
	}
	{
		zval val_copy;
		ZVAL_COPY_DEREF(&val_copy, value);
		if (obj->kind == FAST_ENGINE_STRIPED) {
			fast_striped_set(obj->store.striped, key, &val_copy);
		} else if (obj->kind == FAST_ENGINE_NATIVE) {
			fast_native_set(obj->store.native, key, &val_copy);
		} else {
			fast_local_update(obj->store.local, key, &val_copy);
		}
		zval_ptr_dtor(&val_copy);
	}
}

PHP_METHOD(Fast, offsetUnset)
{
	zval *key;
	fast_object *obj = Z_FAST_P(getThis());
	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ZVAL(key)
	ZEND_PARSE_PARAMETERS_END();
	fast_assert_open(obj);
	if (EG(exception)) {
		return;
	}
	fast_assert_valid_offset(key);
	if (EG(exception)) {
		return;
	}
	if (obj->pending_dirty) {
		fast_flush_pending(obj);
		if (EG(exception)) {
			return;
		}
	}
	if (obj->kind == FAST_ENGINE_STRIPED) {
		fast_striped_delete(obj->store.striped, key);
		return;
	}
	if (obj->kind == FAST_ENGINE_NATIVE) {
		fast_native_delete(obj->store.native, key);
		return;
	}
	fast_local_unset(obj->store.local, key);
}

PHP_METHOD(Fast, __get)
{
	zend_string *name;
	zval key;
	fast_object *obj = Z_FAST_P(getThis());
	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_STR(name)
	ZEND_PARSE_PARAMETERS_END();
	ZVAL_STR(&key, name);
	fast_assert_open(obj);
	if (EG(exception)) {
		return;
	}
	if (obj->pending_dirty) {
		fast_flush_pending(obj);
		if (EG(exception)) {
			return;
		}
	}

	zval_ptr_dtor(&obj->pending_key);
	zval_ptr_dtor(&obj->pending_val);
	zval_ptr_dtor(&obj->pending_orig);
	ZVAL_COPY(&obj->pending_key, &key);
	obj->pending_key_set = true;
	obj->pending_dirty = true;

	if (obj->kind == FAST_ENGINE_STRIPED) {
		if (fast_striped_get(obj->store.striped, &key, &obj->pending_val)) {
			ZVAL_COPY(&obj->pending_orig, &obj->pending_val);
			RETURN_ZVAL(&obj->pending_val, 0, 0);
		}
	} else if (obj->kind == FAST_ENGINE_NATIVE) {
		if (fast_native_get(obj->store.native, &key, &obj->pending_val)) {
			ZVAL_COPY(&obj->pending_orig, &obj->pending_val);
			RETURN_ZVAL(&obj->pending_val, 0, 0);
		}
	} else {
		zval *val = zend_hash_find(obj->store.local, name);
		if (val) {
			ZVAL_COPY_DEREF(&obj->pending_val, val);
			ZVAL_COPY(&obj->pending_orig, &obj->pending_val);
			RETURN_ZVAL(&obj->pending_val, 0, 0);
		}
	}

	ZVAL_NULL(&obj->pending_val);
	ZVAL_NULL(&obj->pending_orig);
	RETURN_ZVAL(&obj->pending_val, 0, 0);
}

PHP_METHOD(Fast, __set)
{
	zend_string *name;
	zval *value;
	zval key;
	fast_object *obj = Z_FAST_P(getThis());
	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_STR(name)
		Z_PARAM_ZVAL(value)
	ZEND_PARSE_PARAMETERS_END();
	fast_assert_open(obj);
	if (EG(exception)) {
		return;
	}
	if (obj->pending_dirty) {
		fast_flush_pending(obj);
		if (EG(exception)) {
			return;
		}
	}
	ZVAL_STR(&key, name);
	{
		zval val_copy;
		ZVAL_COPY_DEREF(&val_copy, value);
		if (obj->kind == FAST_ENGINE_STRIPED) {
			fast_striped_set(obj->store.striped, &key, &val_copy);
			zval_ptr_dtor(&val_copy);
		} else if (obj->kind == FAST_ENGINE_NATIVE) {
			fast_native_set(obj->store.native, &key, &val_copy);
			zval_ptr_dtor(&val_copy);
		} else {
			fast_local_update(obj->store.local, &key, &val_copy);
		}
	}
}

PHP_METHOD(Fast, __isset)
{
	zend_string *name;
	zval key;
	fast_object *obj = Z_FAST_P(getThis());
	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_STR(name)
	ZEND_PARSE_PARAMETERS_END();
	fast_assert_open(obj);
	if (EG(exception)) {
		RETURN_FALSE;
	}
	if (obj->pending_dirty) {
		fast_flush_pending(obj);
		if (EG(exception)) {
			RETURN_FALSE;
		}
	}
	ZVAL_STR(&key, name);
	if (obj->kind == FAST_ENGINE_STRIPED) {
		RETURN_BOOL(fast_striped_has(obj->store.striped, &key));
	}
	if (obj->kind == FAST_ENGINE_NATIVE) {
		RETURN_BOOL(fast_native_has(obj->store.native, &key));
	}
	if (!obj->store.local || !zend_hash_exists(obj->store.local, name)) {
		RETURN_FALSE;
	}
	zval *val = zend_hash_find(obj->store.local, name);
	RETURN_BOOL(val && Z_TYPE_P(val) != IS_NULL);
}

PHP_METHOD(Fast, __unset)
{
	zend_string *name;
	zval key;
	fast_object *obj = Z_FAST_P(getThis());
	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_STR(name)
	ZEND_PARSE_PARAMETERS_END();
	fast_assert_open(obj);
	if (EG(exception)) {
		return;
	}
	if (obj->pending_dirty) {
		fast_flush_pending(obj);
		if (EG(exception)) {
			return;
		}
	}
	ZVAL_STR(&key, name);
	if (obj->kind == FAST_ENGINE_STRIPED) {
		fast_striped_delete(obj->store.striped, &key);
		return;
	}
	if (obj->kind == FAST_ENGINE_NATIVE) {
		fast_native_delete(obj->store.native, &key);
		return;
	}
	zend_hash_del(obj->store.local, name);
}

PHP_METHOD(Fast, count)
{
	fast_object *obj = Z_FAST_P(getThis());
	ZEND_PARSE_PARAMETERS_NONE();
	fast_assert_open(obj);
	if (EG(exception)) {
		RETURN_LONG(0);
	}
	if (obj->kind == FAST_ENGINE_STRIPED) {
		RETURN_LONG(fast_striped_count(obj->store.striped));
	}
	if (obj->kind == FAST_ENGINE_NATIVE) {
		RETURN_LONG(fast_native_count(obj->store.native));
	}
	RETURN_LONG((zend_long)fast_local_count(obj->store.local));
}

PHP_METHOD(Fast, rewind)
{
	fast_object *obj = Z_FAST_P(getThis());
	ZEND_PARSE_PARAMETERS_NONE();
	fast_assert_open(obj);
	if (EG(exception)) {
		return;
	}
	if (obj->kind == FAST_ENGINE_STRIPED) {
		fast_striped_rewind(obj->store.striped);
		return;
	}
	if (obj->kind == FAST_ENGINE_NATIVE) {
		fast_native_rewind(obj->store.native);
		return;
	}
	obj->iter_pos = 0;
}

PHP_METHOD(Fast, valid)
{
	fast_object *obj = Z_FAST_P(getThis());
	ZEND_PARSE_PARAMETERS_NONE();
	fast_assert_open(obj);
	if (EG(exception)) {
		RETURN_FALSE;
	}
	if (obj->kind == FAST_ENGINE_STRIPED) {
		RETURN_BOOL(fast_striped_valid(obj->store.striped));
	}
	if (obj->kind == FAST_ENGINE_NATIVE) {
		RETURN_BOOL(fast_native_valid(obj->store.native));
	}
	RETURN_BOOL(obj->iter_pos < fast_local_count(obj->store.local));
}

PHP_METHOD(Fast, key)
{
	fast_object *obj = Z_FAST_P(getThis());
	ZEND_PARSE_PARAMETERS_NONE();
	fast_assert_open(obj);
	if (EG(exception)) {
		return;
	}
	if (obj->kind == FAST_ENGINE_STRIPED) {
		fast_striped_key(obj->store.striped, return_value);
		return;
	}
	if (obj->kind == FAST_ENGINE_NATIVE) {
		fast_native_key(obj->store.native, return_value);
		return;
	}
	zval key;
	if (!fast_local_key_at(obj->store.local, obj->iter_pos, &key)) {
		RETURN_NULL();
	}
	RETURN_COPY_VALUE(&key);
}

PHP_METHOD(Fast, current)
{
	fast_object *obj = Z_FAST_P(getThis());
	ZEND_PARSE_PARAMETERS_NONE();
	fast_assert_open(obj);
	if (EG(exception)) {
		return;
	}
	if (obj->kind == FAST_ENGINE_STRIPED) {
		fast_striped_current(obj->store.striped, return_value);
		return;
	}
	if (obj->kind == FAST_ENGINE_NATIVE) {
		fast_native_current(obj->store.native, return_value);
		return;
	}
	zval key;
	if (!fast_local_key_at(obj->store.local, obj->iter_pos, &key)) {
		RETURN_NULL();
	}
	zval *val = fast_local_find(obj->store.local, &key);
	zval_ptr_dtor(&key);
	if (!val) {
		RETURN_NULL();
	}
	RETURN_COPY_DEREF(val);
}

PHP_METHOD(Fast, next)
{
	fast_object *obj = Z_FAST_P(getThis());
	ZEND_PARSE_PARAMETERS_NONE();
	fast_assert_open(obj);
	if (EG(exception)) {
		return;
	}
	if (obj->kind == FAST_ENGINE_STRIPED) {
		fast_striped_next(obj->store.striped);
		return;
	}
	if (obj->kind == FAST_ENGINE_NATIVE) {
		fast_native_next(obj->store.native);
		return;
	}
	obj->iter_pos++;
}

PHP_METHOD(Fast, seek)
{
	zend_long pos;
	fast_object *obj = Z_FAST_P(getThis());
	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_LONG(pos)
	ZEND_PARSE_PARAMETERS_END();
	fast_assert_open(obj);
	if (EG(exception)) {
		return;
	}
	if (obj->kind == FAST_ENGINE_STRIPED) {
		fast_striped_seek(obj->store.striped, pos);
		return;
	}
	if (obj->kind == FAST_ENGINE_NATIVE) {
		fast_native_seek(obj->store.native, pos);
		return;
	}
	uint32_t cnt = fast_local_count(obj->store.local);
	if (pos < 0 || (uint32_t)pos >= cnt) {
		zend_throw_exception_ex(spl_ce_OutOfBoundsException, 0,
			"Seek position %ld is out of range", pos);
		return;
	}
	obj->iter_pos = (uint32_t)pos;
}

static bool fast_validate_each_callable(zval *fn)
{
	if (Z_TYPE_P(fn) == IS_OBJECT && instanceof_function(Z_OBJCE_P(fn), zend_ce_closure)) {
		zend_type_error("Fast::each(): Argument #1 ($fn) must be of type array|string, Closure given");
		return false;
	}
	if (Z_TYPE_P(fn) != IS_STRING && Z_TYPE_P(fn) != IS_ARRAY) {
		zend_type_error("Fast::each(): Argument #1 ($fn) must be of type array|string, %s given",
			zend_zval_value_name(fn));
		return false;
	}

	if (Z_TYPE_P(fn) == IS_STRING) {
		if (!zend_is_callable(fn, 0, NULL)) {
			zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
				"each callable function not found: %s", Z_STRVAL_P(fn));
			return false;
		}
	} else {
		zval *target = zend_hash_index_find(Z_ARRVAL_P(fn), 0);
		zval *method = zend_hash_index_find(Z_ARRVAL_P(fn), 1);
		if (!target || !method || Z_TYPE_P(method) != IS_STRING) {
			zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
				"each callable must be a named function or [class/object, method] pair");
			return false;
		}
		if (Z_TYPE_P(target) == IS_OBJECT && instanceof_function(Z_OBJCE_P(target), zend_ce_closure)) {
			zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
				"closures are not allowed for each callables");
			return false;
		}
		if (Z_TYPE_P(target) != IS_STRING && Z_TYPE_P(target) != IS_OBJECT) {
			zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
				"each callable target must be a class-string or object");
			return false;
		}
		if (!zend_is_callable(fn, 0, NULL)) {
			zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
				"each callable is not valid");
			return false;
		}
	}

	return true;
}

static void fast_each_collect_keys(fast_object *obj, zval *keys_out)
{
	array_init(keys_out);

	if (obj->kind == FAST_ENGINE_STRIPED) {
		fast_striped_lock(obj->store.striped);
		fast_striped_rewind(obj->store.striped);
		while (fast_striped_valid(obj->store.striped)) {
			zval key;
			fast_striped_key(obj->store.striped, &key);
			add_next_index_zval(keys_out, &key);
			fast_striped_next(obj->store.striped);
		}
		fast_striped_unlock(obj->store.striped);
		return;
	}

	if (obj->kind == FAST_ENGINE_NATIVE) {
		fast_native_lock(obj->store.native);
		fast_native_rewind(obj->store.native);
		while (fast_native_valid(obj->store.native)) {
			zval key;
			fast_native_key(obj->store.native, &key);
			add_next_index_zval(keys_out, &key);
			fast_native_next(obj->store.native);
		}
		fast_native_unlock(obj->store.native);
		return;
	}

	obj->iter_pos = 0;
	while (obj->iter_pos < fast_local_count(obj->store.local)) {
		zval key;
		if (fast_local_key_at(obj->store.local, obj->iter_pos, &key)) {
			add_next_index_zval(keys_out, &key);
		}
		obj->iter_pos++;
	}
	obj->iter_pos = 0;
}

PHP_METHOD(Fast, close)
{
	fast_object *obj = Z_FAST_P(getThis());
	ZEND_PARSE_PARAMETERS_NONE();
	if (obj->closed) {
		return;
	}
	fast_flush_pending(obj);
	if (EG(exception)) {
		return;
	}
	if (obj->kind == FAST_ENGINE_STRIPED && obj->store.striped) {
		fast_striped_close(obj->store.striped);
	} else if (obj->kind == FAST_ENGINE_NATIVE && obj->store.native) {
		fast_native_close(obj->store.native);
	}
	obj->closed = true;
}

PHP_METHOD(Fast, __destruct)
{
	fast_object *obj = Z_FAST_P(getThis());
	if (obj->closed) {
		return;
	}
	fast_flush_pending(obj);
	if (obj->kind == FAST_ENGINE_STRIPED && obj->store.striped) {
		fast_striped_close(obj->store.striped);
	} else if (obj->kind == FAST_ENGINE_NATIVE && obj->store.native) {
		fast_native_close(obj->store.native);
	}
	obj->closed = true;
}

PHP_METHOD(Fast, destroy)
{
	fast_object *obj = Z_FAST_P(getThis());
	ZEND_PARSE_PARAMETERS_NONE();
	fast_assert_open(obj);
	if (EG(exception)) {
		return;
	}
	fast_flush_pending(obj);
	if (EG(exception)) {
		return;
	}
	if (obj->kind == FAST_ENGINE_STRIPED && obj->store.striped) {
		fast_striped_destroy_store(obj->store.striped);
		if (EG(exception)) {
			return;
		}
		fast_striped_free(obj->store.striped);
		obj->store.striped = NULL;
		obj->kind = FAST_ENGINE_NONE;
	} else if (obj->kind == FAST_ENGINE_NATIVE && obj->store.native) {
		fast_native_destroy_store(obj->store.native);
		if (EG(exception)) {
			return;
		}
		fast_native_free(obj->store.native);
		obj->store.native = NULL;
		obj->kind = FAST_ENGINE_NONE;
	} else if (obj->store.local) {
		zend_array_destroy(obj->store.local);
		obj->store.local = zend_new_array(0);
	}
	obj->closed = true;
}

PHP_METHOD(Fast, each)
{
	zval *fn;
	zval *extra_args = NULL;
	int extra_count = 0;
	fast_object *obj = Z_FAST_P(getThis());
	zend_long visited = 0;
	zval keys;

	ZEND_PARSE_PARAMETERS_START(1, -1)
		Z_PARAM_ZVAL(fn)
		Z_PARAM_VARIADIC('*', extra_args, extra_count)
	ZEND_PARSE_PARAMETERS_END();

	fast_assert_open(obj);
	if (EG(exception)) {
		RETURN_LONG(0);
	}
	fast_flush_pending(obj);
	if (EG(exception)) {
		RETURN_LONG(0);
	}
	if (!fast_validate_each_callable(fn)) {
		if (EG(exception)) {
			RETURN_LONG(0);
		}
		RETURN_LONG(0);
	}

	fast_each_collect_keys(obj, &keys);
	{
		zval *key_zv;
		uint32_t param_count = (uint32_t)(3 + extra_count);
		zval *params = ecalloc(param_count, sizeof(zval));

		ZVAL_COPY_VALUE(&params[0], getThis());
		ZEND_HASH_FOREACH_VAL(Z_ARRVAL(keys), key_zv) {
			zval value, retval;
			bool hit = false;

			if (obj->kind == FAST_ENGINE_STRIPED) {
				hit = fast_striped_get(obj->store.striped, key_zv, &value);
			} else if (obj->kind == FAST_ENGINE_NATIVE) {
				hit = fast_native_get(obj->store.native, key_zv, &value);
			} else {
				zval *found = fast_local_find(obj->store.local, key_zv);
				if (found) {
					ZVAL_COPY_DEREF(&value, found);
					hit = true;
				}
			}
			if (!hit) {
				continue;
			}

			ZVAL_COPY(&params[1], key_zv);
			ZVAL_COPY(&params[2], &value);
			for (int i = 0; i < extra_count; i++) {
				ZVAL_COPY(&params[3 + i], &extra_args[i]);
			}

			if (call_user_function(NULL, NULL, fn, &retval, param_count, params) == SUCCESS) {
				visited++;
				zval_ptr_dtor(&retval);
			}

			zval_ptr_dtor(&params[1]);
			zval_ptr_dtor(&params[2]);
			zval_ptr_dtor(&value);
		} ZEND_HASH_FOREACH_END();

		efree(params);
	}

	zval_ptr_dtor(&keys);
	RETURN_LONG(visited);
}

PHP_METHOD(Fast, __serialize)
{
	fast_object *obj = Z_FAST_P(getThis());
	zval payload;
	ZEND_PARSE_PARAMETERS_NONE();

	fast_assert_open(obj);
	if (EG(exception)) {
		return;
	}
	fast_flush_pending(obj);
	if (EG(exception)) {
		return;
	}

	if (obj->kind == FAST_ENGINE_NATIVE || obj->kind == FAST_ENGINE_STRIPED) {
		const char *name = NULL;
		uint32_t stripes = 1;
		bool persistent = false;

		array_init(&payload);
		add_assoc_bool(&payload, "sharedMode", 1);
		if (obj->kind == FAST_ENGINE_STRIPED) {
			name = fast_striped_store_name(obj->store.striped);
			stripes = (uint32_t)fast_striped_stripe_count(obj->store.striped);
			persistent = fast_striped_is_persistent(obj->store.striped);
			add_assoc_long(&payload, "sharedSize", (zend_long)fast_striped_segment_size(obj->store.striped));
			add_assoc_long(&payload, "directorySlots", (zend_long)fast_striped_directory_slots(obj->store.striped));
		} else {
			name = fast_native_store_name(obj->store.native);
			persistent = fast_native_is_persistent(obj->store.native);
			add_assoc_long(&payload, "sharedSize", (zend_long)fast_native_segment_size(obj->store.native));
			add_assoc_long(&payload, "directorySlots", (zend_long)fast_native_directory_slots(obj->store.native));
		}
		add_assoc_string(&payload, "sharedName", (char *)name);
		add_assoc_bool(&payload, "persistent", persistent);
		add_assoc_long(&payload, "stripes", (zend_long)stripes);

		if (obj->kind == FAST_ENGINE_STRIPED && obj->store.striped) {
			fast_striped_close(obj->store.striped);
		} else if (obj->kind == FAST_ENGINE_NATIVE && obj->store.native) {
			fast_native_close(obj->store.native);
		}
		obj->closed = true;
		RETURN_COPY_VALUE(&payload);
	}

	array_init(&payload);
	add_assoc_bool(&payload, "sharedMode", 0);
	{
		zval entries;
		array_init(&entries);
		obj->iter_pos = 0;
		while (obj->iter_pos < fast_local_count(obj->store.local)) {
			zval key, val, pair, *found;
			if (!fast_local_key_at(obj->store.local, obj->iter_pos, &key)) {
				obj->iter_pos++;
				continue;
			}
			found = fast_local_find(obj->store.local, &key);
			if (!found) {
				zval_ptr_dtor(&key);
				obj->iter_pos++;
				continue;
			}
			array_init(&pair);
			add_index_zval(&pair, 0, &key);
			ZVAL_COPY(&val, found);
			add_index_zval(&pair, 1, &val);
			add_next_index_zval(&entries, &pair);
			obj->iter_pos++;
		}
		obj->iter_pos = 0;
		add_assoc_zval(&payload, "entries", &entries);
	}
	RETURN_COPY_VALUE(&payload);
}

PHP_METHOD(Fast, __unserialize)
{
	zval *data;
	fast_object *obj = Z_FAST_P(getThis());
	zval *shared_mode;
	zval cfg;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ARRAY(data)
	ZEND_PARSE_PARAMETERS_END();

	fast_obj_teardown_store(obj);
	obj->closed = false;

	shared_mode = zend_hash_str_find(Z_ARRVAL_P(data), "sharedMode", sizeof("sharedMode") - 1);
	if (shared_mode && Z_TYPE_P(shared_mode) == IS_TRUE) {
		zval *shared_name = zend_hash_str_find(Z_ARRVAL_P(data), "sharedName", sizeof("sharedName") - 1);
		zval *shared_size = zend_hash_str_find(Z_ARRVAL_P(data), "sharedSize", sizeof("sharedSize") - 1);
		zval *directory_slots = zend_hash_str_find(Z_ARRVAL_P(data), "directorySlots", sizeof("directorySlots") - 1);
		zval *persistent = zend_hash_str_find(Z_ARRVAL_P(data), "persistent", sizeof("persistent") - 1);
		zval *stripes = zend_hash_str_find(Z_ARRVAL_P(data), "stripes", sizeof("stripes") - 1);

		if (!shared_name || Z_TYPE_P(shared_name) != IS_STRING || Z_STRLEN_P(shared_name) == 0
			|| !shared_size || Z_TYPE_P(shared_size) != IS_LONG || Z_LVAL_P(shared_size) < 1) {
			zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "invalid shared Fast wakeup payload");
			return;
		}

		array_init(&cfg);
		add_assoc_str(&cfg, "name", zend_string_copy(Z_STR_P(shared_name)));
		add_assoc_long(&cfg, "size", Z_LVAL_P(shared_size));
		if (directory_slots && Z_TYPE_P(directory_slots) == IS_LONG) {
			add_assoc_long(&cfg, "capacity", Z_LVAL_P(directory_slots));
		}
		if (persistent && (Z_TYPE_P(persistent) == IS_TRUE || Z_TYPE_P(persistent) == IS_FALSE)) {
			add_assoc_bool(&cfg, "persistent", Z_TYPE_P(persistent) == IS_TRUE);
		}
		if (stripes && Z_TYPE_P(stripes) == IS_LONG && Z_LVAL_P(stripes) > 1) {
			add_assoc_long(&cfg, "stripes", Z_LVAL_P(stripes));
		}

		if (!fast_shared_init(obj, &cfg)) {
			zval_ptr_dtor(&cfg);
			return;
		}
		zval_ptr_dtor(&cfg);
		return;
	}

	fast_local_init(obj);
	{
		zval *entries = zend_hash_str_find(Z_ARRVAL_P(data), "entries", sizeof("entries") - 1);
		if (entries && Z_TYPE_P(entries) == IS_ARRAY) {
			zval *entry;
			ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(entries), entry) {
				zval *key_zv;
				zval *val_zv;
				if (Z_TYPE_P(entry) != IS_ARRAY) {
					continue;
				}
				key_zv = zend_hash_index_find(Z_ARRVAL_P(entry), 0);
				val_zv = zend_hash_index_find(Z_ARRVAL_P(entry), 1);
				if (!key_zv || !val_zv) {
					continue;
				}
				fast_local_update(obj->store.local, key_zv, val_zv);
			} ZEND_HASH_FOREACH_END();
		}
	}
}

PHP_METHOD(Fast, compact)
{
	fast_object *obj = Z_FAST_P(getThis());
	ZEND_PARSE_PARAMETERS_NONE();
	fast_assert_open(obj);
	if (EG(exception)) {
		return;
	}
	fast_flush_pending(obj);
	if (obj->kind == FAST_ENGINE_NATIVE && obj->store.native) {
		fast_native_compact(obj->store.native);
	}
}

PHP_METHOD(Fast, stats)
{
	fast_object *obj = Z_FAST_P(getThis());
	ZEND_PARSE_PARAMETERS_NONE();
	fast_flush_pending(obj);
	if (EG(exception)) {
		return;
	}
	fast_build_stats(obj, return_value);
}

PHP_METHOD(Fast, __debugInfo)
{
	fast_object *obj = Z_FAST_P(getThis());
	ZEND_PARSE_PARAMETERS_NONE();
	fast_flush_pending(obj);
	if (EG(exception)) {
		return;
	}
	fast_build_stats(obj, return_value);
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_fast_construct, 0, 0, 0)
	ZEND_ARG_INFO(0, config)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_get, 0, 0, 1)
	ZEND_ARG_INFO(0, name)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_set, 0, 0, 2)
	ZEND_ARG_INFO(0, name)
	ZEND_ARG_INFO(0, value)
ZEND_END_ARG_INFO()

#define arginfo_has arginfo_get
#define arginfo_unset arginfo_get

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_seek, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, position, IS_LONG, 0)
ZEND_END_ARG_INFO()

#define arginfo_offset_exists arginfo_class_ArrayAccess_offsetExists
#define arginfo_offset_get arginfo_class_ArrayAccess_offsetGet
#define arginfo_offset_set arginfo_class_ArrayAccess_offsetSet
#define arginfo_offset_unset arginfo_class_ArrayAccess_offsetUnset
#define arginfo_rewind arginfo_class_Iterator_rewind
#define arginfo_current arginfo_class_Iterator_current
#define arginfo_key arginfo_class_Iterator_key
#define arginfo_next arginfo_class_Iterator_next
#define arginfo_valid arginfo_class_Iterator_valid
#define arginfo_count arginfo_class_Countable_count

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_serialize, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_unserialize, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, data, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_fast_each, 0, 1, IS_LONG, 0)
	ZEND_ARG_INFO(0, fn)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO(arginfo_fast_none, 0)
ZEND_END_ARG_INFO()

const zend_function_entry fast_methods[] = {
	PHP_ME(Fast, __construct, arginfo_fast_construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
	PHP_ME(Fast, offsetExists, arginfo_offset_exists, ZEND_ACC_PUBLIC)
	PHP_ME(Fast, offsetGet, arginfo_offset_get, ZEND_ACC_PUBLIC | ZEND_ACC_RETURN_REFERENCE)
	PHP_ME(Fast, offsetSet, arginfo_offset_set, ZEND_ACC_PUBLIC)
	PHP_ME(Fast, offsetUnset, arginfo_offset_unset, ZEND_ACC_PUBLIC)
	PHP_ME(Fast, __get, arginfo_get, ZEND_ACC_PUBLIC)
	PHP_ME(Fast, __set, arginfo_set, ZEND_ACC_PUBLIC)
	PHP_ME(Fast, __isset, arginfo_has, ZEND_ACC_PUBLIC)
	PHP_ME(Fast, __unset, arginfo_unset, ZEND_ACC_PUBLIC)
	PHP_ME(Fast, rewind, arginfo_rewind, ZEND_ACC_PUBLIC)
	PHP_ME(Fast, current, arginfo_current, ZEND_ACC_PUBLIC)
	PHP_ME(Fast, key, arginfo_key, ZEND_ACC_PUBLIC)
	PHP_ME(Fast, next, arginfo_next, ZEND_ACC_PUBLIC)
	PHP_ME(Fast, valid, arginfo_valid, ZEND_ACC_PUBLIC)
	PHP_ME(Fast, seek, arginfo_seek, ZEND_ACC_PUBLIC)
	PHP_ME(Fast, count, arginfo_count, ZEND_ACC_PUBLIC)
	PHP_ME(Fast, each, arginfo_fast_each, ZEND_ACC_PUBLIC)
	PHP_ME(Fast, compact, arginfo_fast_none, ZEND_ACC_PRIVATE)
	PHP_ME(Fast, stats, arginfo_fast_none, ZEND_ACC_PRIVATE)
	PHP_ME(Fast, close, arginfo_fast_none, ZEND_ACC_PUBLIC)
	PHP_ME(Fast, destroy, arginfo_fast_none, ZEND_ACC_PUBLIC)
	PHP_ME(Fast, __destruct, arginfo_fast_none, ZEND_ACC_PUBLIC)
	PHP_ME(Fast, __serialize, arginfo_serialize, ZEND_ACC_PUBLIC)
	PHP_ME(Fast, __unserialize, arginfo_unserialize, ZEND_ACC_PUBLIC)
	PHP_ME(Fast, __debugInfo, arginfo_fast_none, ZEND_ACC_PUBLIC)
	PHP_FE_END
};

void fast_handlers_init(void)
{
	memcpy(&fast_object_handlers, &std_object_handlers, sizeof(zend_object_handlers));
	fast_object_handlers.free_obj = fast_free_obj;
	fast_object_handlers.offset = XtOffsetOf(fast_object, zo);
	fast_object_handlers.read_property = fast_read_property;
	fast_object_handlers.write_property = fast_write_property;
	fast_object_handlers.unset_property = fast_unset_property;
	fast_object_handlers.read_dimension = fast_read_dimension;
	fast_object_handlers.write_dimension = fast_write_dimension;
	/* zend_std so offsetExists on a closed handle throws like userland Fast. */
	fast_object_handlers.has_dimension = zend_std_has_dimension;
	fast_object_handlers.unset_dimension = fast_unset_dimension;
}
