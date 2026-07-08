/*
  +----------------------------------------------------------------------+
  | Copyright (c) 2025-2026 James Dornan                                  |
  +----------------------------------------------------------------------+
  | Licensed under the MIT License, see the LICENSE file for details.    |
  +----------------------------------------------------------------------+
 */

#ifndef FLAT_ENGINE_INTERNAL_H
#define FLAT_ENGINE_INTERNAL_H

#include "flat_native.h"
#include "fast_layout.h"

/* Shared engine state. compat=true uses SysV shm + FLT2 layout (flat_compat.c);
 * compat=false uses POSIX mmap + XFST layout. See docs/extension-layout-native.md. */
struct fast_native_s {
	char *name;
	char *map;
	size_t map_size;
	int shm_fd;
	char shm_path[128];
	char lock_path[256];
	int sem_id;
	uint32_t slot_count;
	uint32_t mask;
	uint32_t order_bytes;
	uint32_t arena_base;
	bool persistent;
	bool linked;
	bool compat;
	int store_key;
	int seg0_id;
	uint32_t payload;
	uint32_t iter_pos;
	uint64_t iter_tag;
	zval iter_key;
	zval iter_val;
	bool iter_ready;
	uint32_t spin;
};

void fast_engine_register_link(fast_native_t *eng);
void fast_engine_release_link(fast_native_t *eng);
bool fast_engine_reclaim_if_orphaned(fast_native_t *eng);
void fast_engine_recover_if_crashed(fast_native_t *eng);
void fast_engine_init_fresh_native(fast_native_t *eng, bool persistent);
void fast_engine_init_fresh_compat(fast_native_t *eng, bool persistent);
void fast_engine_adopt_geometry(fast_native_t *eng);
bool fast_engine_name_hash_match(fast_native_t *eng, const char *name, size_t len);
void fast_engine_delete_segments(fast_native_t *eng);
bool fast_engine_is_sole_connection(fast_native_t *eng);
uint32_t fast_engine_lockfree_spin(void);

#endif
