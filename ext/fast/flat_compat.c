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
 * PHP wire-format (FLT2) shared attach via SysV shm + fast-flat-* namespace.
 * Byte layout matches src/Engine/Flat.php; see docs/extension-compat.md.
 */

#include "flat_compat.h"
#include "flat_engine_internal.h"
#include "fast_layout.h"
#include "ext/standard/crc32.h"
#include "ext/spl/spl_exceptions.h"
#include "zend_exceptions.h"

#include <errno.h>
#include <stdio.h>
#include <string.h>
#include <sys/shm.h>
#include <sys/stat.h>
#include <unistd.h>

#ifdef HAVE_SYS_SEM_H
#include <sys/ipc.h>
#include <sys/sem.h>
#endif

static int fast_php_crc32(const char *str)
{
	uint32_t crc = php_crc32_bulk_init();

	crc = php_crc32_bulk_update(crc, str, strlen(str));
	return (int)(php_crc32_bulk_end(crc) & 0x7fffffffU);
}

static int fast_compat_seg_key(const char *name, size_t len, int index)
{
	char buf[512];

	snprintf(buf, sizeof(buf), "fast-flat:%.*s:%d", (int)len, name, index);
	return fast_php_crc32(buf);
}

static int fast_compat_sem_get(const char *name, size_t len)
{
#ifdef HAVE_SYS_SEM_H
	char buf[256];
	key_t key;
	int id;

	snprintf(buf, sizeof(buf), "fast-flat-sem:%.*s", (int)len, name);
	key = (key_t)fast_php_crc32(buf);
	id = semget(key, 1, IPC_CREAT | IPC_EXCL | 0600);
	if (id >= 0) {
		union semun {
			int val;
		} arg;
		arg.val = 1;
		semctl(id, 0, SETVAL, arg);
		return id;
	}
	if (errno == EEXIST) {
		id = semget(key, 1, 0600);
	}
	return id;
#else
	(void)name;
	(void)len;
	return -1;
#endif
}

static void fast_compat_lock_path(fast_native_t *eng)
{
	const char *dir = "/dev/shm";
	struct stat st;

	if (stat("/dev/shm", &st) != 0 || access("/dev/shm", W_OK) != 0) {
		dir = "/tmp";
	}
	snprintf(eng->lock_path, sizeof(eng->lock_path), "%s/fast-flat-%d.lock", dir, eng->store_key);
}

static bool fast_compat_map_seg0(fast_native_t *eng, uint32_t size, bool create)
{
	key_t key = (key_t)eng->store_key;
	int id;
	void *map;
	struct shmid_ds ds;

	if (create) {
		id = shmget(key, (size_t)size, IPC_CREAT | IPC_EXCL | 0600);
		if (id < 0 && errno == EEXIST) {
			id = shmget(key, 0, 0600);
		}
	} else {
		id = shmget(key, 0, 0600);
	}
	if (id < 0) {
		return false;
	}
	map = shmat(id, NULL, 0);
	if (map == (void *)-1) {
		return false;
	}
	if (shmctl(id, IPC_STAT, &ds) != 0) {
		shmdt(map);
		return false;
	}
	eng->seg0_id = id;
	eng->map = (char *)map;
	eng->map_size = (size_t)ds.shm_segsz;
	eng->payload = (uint32_t)(eng->map_size - FAST_HEADER);
	if (create && size > 0 && eng->map_size < size) {
		shmdt(map);
		eng->map = NULL;
		eng->seg0_id = -1;
		return false;
	}
	(void)create;
	return true;
}

fast_native_t *fast_compat_attach(
	const char *name, size_t name_len, uint32_t size, uint32_t slots, bool persistent)
{
	uint32_t order_bytes = FAST_ORDER;
	bool existing = false;

	if ((slots & (slots - 1)) != 0 || slots < 1) {
		zend_throw_exception_ex(zend_ce_value_error, 0, "capacity must be a power of two");
		return NULL;
	}
	if (size < fast_min_segment_size(slots, order_bytes)) {
		zend_throw_exception_ex(zend_ce_value_error, 0, "segment size too small for the requested capacity");
		return NULL;
	}

	fast_native_t *eng = ecalloc(1, sizeof(fast_native_t));
	eng->name = estrndup(name, name_len);
	eng->slot_count = slots;
	eng->mask = slots - 1;
	eng->order_bytes = order_bytes;
	eng->arena_base = FAST_HEADER + slots * (FAST_SLOT + order_bytes);
	eng->spin = FAST_SPIN;
	eng->shm_fd = -1;
	eng->seg0_id = -1;
	eng->compat = true;
	eng->linked = false;
	eng->store_key = fast_compat_seg_key(name, name_len, 0);
	ZVAL_NULL(&eng->iter_key);
	ZVAL_NULL(&eng->iter_val);

	fast_compat_lock_path(eng);
	eng->sem_id = fast_compat_sem_get(name, name_len);
	if (eng->sem_id < 0) {
		zend_throw_exception(NULL, "unable to obtain shared Fast semaphore", 0);
		efree(eng->name);
		efree(eng);
		return NULL;
	}

	if (fast_compat_map_seg0(eng, size, false)
		&& eng->map_size >= 4
		&& memcmp(eng->map + FAST_H_MAGIC, FAST_COMPAT_MAGIC, 4) == 0) {
		if (fast_ru32(eng->map, FAST_H_LAYOUT) != FAST_PHP_LAYOUT) {
			zend_throw_exception(NULL, "incompatible shared Fast layout version", 0);
			shmdt(eng->map);
			efree(eng->name);
			efree(eng);
			return NULL;
		}
		if (!fast_engine_name_hash_match(eng, name, name_len)) {
			zend_throw_exception(spl_ce_RuntimeException,
				"shared Fast name collision on segment key", 0);
			shmdt(eng->map);
			efree(eng->name);
			efree(eng);
			return NULL;
		}
		fast_engine_adopt_geometry(eng);
		existing = true;
	}

	if (existing) {
		if (!eng->persistent && fast_engine_reclaim_if_orphaned(eng)) {
			existing = false;
			eng->map = NULL;
			eng->seg0_id = -1;
		} else {
			fast_engine_recover_if_crashed(eng);
		}
	}

	if (!existing) {
		if (!eng->map) {
			if (!fast_compat_map_seg0(eng, size, true)) {
				zend_throw_exception(NULL, "unable to open shared Fast segment", 0);
				fast_native_free(eng);
				return NULL;
			}
		}
		eng->slot_count = slots;
		eng->mask = slots - 1;
		eng->order_bytes = order_bytes;
		eng->arena_base = FAST_HEADER + slots * (FAST_SLOT + order_bytes);
		eng->payload = (uint32_t)(eng->map_size - FAST_HEADER);
		fast_engine_init_fresh_compat(eng, persistent);
	}

	fast_engine_register_link(eng);
	if (EG(exception)) {
		fast_native_free(eng);
		return NULL;
	}

	return eng;
}
