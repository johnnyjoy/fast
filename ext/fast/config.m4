PHP_ARG_ENABLE([fast],,
  [whether to enable Fast shared-memory support], [no])

if test "$PHP_FAST" != "no"; then
  AC_DEFINE(HAVE_FAST, 1, [Have Fast extension])

  AC_CHECK_HEADERS([sys/mman.h sys/shm.h sys/sem.h])

  PHP_NEW_EXTENSION(fast, fast.c fast_object.c flat_native.c flat_compat.c striped_native.c, $ext_shared)
  PHP_ADD_EXTENSION_DEP(fast, igbinary)
  PHP_ADD_EXTENSION_DEP(fast, hash)
  PHP_ADD_EXTENSION_DEP(fast, spl)
  PHP_ADD_LIBRARY(rt,, FAST_SHARED_LIBADD)
  PHP_SUBST(FAST_SHARED_LIBADD)
fi
