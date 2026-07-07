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
 * Module bootstrap: INI, \Fast class registration, extension dependencies.
 * Engines: flat_native.c (native + shared core), flat_compat.c (PHP wire format),
 * striped_native.c. Public facade: fast_object.c.
 */

#include "php_fast.h"
#include "zend_interfaces.h"

zend_long fast_compat_ini = 0;
zend_class_entry *fast_ce;

static PHP_INI_MH(OnUpdateCompat)
{
	fast_compat_ini = zend_atol(ZSTR_VAL(new_value), ZSTR_LEN(new_value));
	return SUCCESS;
}

PHP_INI_BEGIN()
	ZEND_INI_ENTRY("fast.compat", "0", PHP_INI_ALL, OnUpdateCompat)
PHP_INI_END()

static const zend_module_dep fast_deps[] = {
	ZEND_MOD_REQUIRED("igbinary")
	ZEND_MOD_REQUIRED("hash")
	ZEND_MOD_END
};

zend_module_entry fast_module_entry = {
	STANDARD_MODULE_HEADER_EX,
	NULL,
	fast_deps,
	"fast",
	NULL,
	PHP_MINIT(fast),
	PHP_MSHUTDOWN(fast),
	NULL,
	NULL,
	PHP_MINFO(fast),
	PHP_FAST_VERSION,
	STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_FAST
ZEND_GET_MODULE(fast)
#endif

PHP_MINIT_FUNCTION(fast)
{
	REGISTER_INI_ENTRIES();
	fast_native_minit();
	fast_handlers_init();

	zend_class_entry ce;
	INIT_CLASS_ENTRY(ce, "Fast", fast_methods);
	fast_ce = zend_register_internal_class_ex(&ce, NULL);
	fast_ce->create_object = fast_create_object;

	zend_class_implements(fast_ce, 3,
		zend_ce_arrayaccess,
		zend_ce_countable,
		zend_ce_iterator);

	return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(fast)
{
	fast_native_mshutdown();
	UNREGISTER_INI_ENTRIES();
	return SUCCESS;
}

PHP_MINFO_FUNCTION(fast)
{
	php_info_print_table_start();
	php_info_print_table_header(2, "Fast support", "enabled");
	php_info_print_table_row(2, "Version", PHP_FAST_VERSION);
	php_info_print_table_row(2, "fast.compat", fast_compat_ini ? "1" : "0");
	php_info_print_table_end();

	DISPLAY_INI_ENTRIES();
}
