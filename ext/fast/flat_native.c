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
 * flat_native.c — shared Flat engine (native XFST mmap and compat attach glue).
 * Layout: docs/extension-layout-native.md. PHP wire compat attach: flat_compat.c.
 */

#include "flat_native.h"
#include "flat_engine_internal.h"
#include "fast_layout.h"
#include "ext/standard/crc32.h"
#include "ext/hash/php_hash.h"
#include "ext/igbinary/src/php7/igbinary.h"
#include "ext/spl/spl_exceptions.h"
#include "zend_exceptions.h"
#include "Zend/zend_hrtime.h"

#include <errno.h>
#include <fcntl.h>
#include <strings.h>
#include <sys/utsname.h>
#include <sys/file.h>
#include <sys/mman.h>
#include <sys/shm.h>
#include <sys/stat.h>
#include <unistd.h>
#include <string.h>
#include <errno.h>
#include <stdio.h>

#ifdef HAVE_SYS_SEM_H
#include <sys/types.h>
#include <sys/ipc.h>
#include <sys/sem.h>
#endif

static inline uint32_t fast_region_arena_base(fast_native_t *eng)
{
	return eng->slot_count * (FAST_SLOT + eng->order_bytes);
}

static inline char *fast_rec_ptr(fast_native_t *eng, uint32_t rec_off)
{
	if (eng->compat) {
		return eng->map + FAST_HEADER + rec_off;
	}
	return eng->map + rec_off;
}

static inline uint64_t fast_frontier_val(fast_native_t *eng)
{
	return fast_read_u64(eng->map, FAST_H_FRONTIER);
}

static inline size_t fast_alloc_limit(fast_native_t *eng)
{
	if (eng->compat) {
		return (size_t)eng->payload;
	}
	return eng->map_size;
}

static HashTable fast_link_refs;
static HashTable fast_link_fps;
static int fast_link_pid = -1;

/* Parity: Flat.php tsoReads() — memoized per process, FAST_LOCKFREE overrides. */
static bool fast_cpu_tso(void)
{
	static const char *tso_machines[] = {
		"x86_64", "amd64", "i386", "i486", "i586", "i686", "x86", NULL
	};
	struct utsname uts;
	const char **m;

	if (uname(&uts) != 0) {
		return false;
	}
	for (m = tso_machines; *m; m++) {
		if (strcasecmp(uts.machine, *m) == 0) {
			return true;
		}
	}
	return false;
}

static bool fast_tso_reads(void)
{
	static int cached = -1;
	const char *env;

	if (cached >= 0) {
		return cached != 0;
	}
	env = getenv("FAST_LOCKFREE");
	if (env && env[0] != '\0') {
		cached = (strcmp(env, "0") != 0) ? 1 : 0;
		return cached != 0;
	}
	cached = fast_cpu_tso() ? 1 : 0;
	return cached != 0;
}

uint32_t fast_engine_lockfree_spin(void)
{
	return fast_tso_reads() ? FAST_SPIN : 0;
}

/* Per-process link table: LOCK_SH on fast-native-*.lock for crash-detectable attach
 * count. Kernel drops flock on exit; sole-connection probe uses LOCK_EX|LOCK_NB. */

/* ---- helpers: hash / igbinary ---- */

static void fast_xxh128_digest(const unsigned char *data, size_t len, unsigned char digest[16])
{
	zend_string *algo = zend_string_init("xxh128", 6, 0);
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

static bool fast_key_norm(zval *key, zend_string **kb_out, zend_string **hb_out, zend_string **hb2_out, uint8_t *kt_out)
{
	zend_string *kb;
	zend_string *prefixed;

	if (Z_TYPE_P(key) == IS_LONG) {
		int64_t v = (int64_t)Z_LVAL_P(key);

		/* Leading 0x00 distinguishes int keys from string keys in the hash prefix. */
		*kt_out = 0;
		kb = zend_string_alloc(8, 0);
		memcpy(ZSTR_VAL(kb), &v, 8);
		ZSTR_LEN(kb) = 8;
		ZSTR_VAL(kb)[8] = '\0';

		prefixed = zend_string_alloc(9, 0);
		ZSTR_VAL(prefixed)[0] = '\0';
		memcpy(ZSTR_VAL(prefixed) + 1, ZSTR_VAL(kb), 8);
		ZSTR_LEN(prefixed) = 9;
		ZSTR_VAL(prefixed)[9] = '\0';
	} else if (Z_TYPE_P(key) == IS_STRING) {
		*kt_out = 1;
		kb = zend_string_copy(Z_STR_P(key));

		prefixed = zend_string_alloc(1 + ZSTR_LEN(kb), 0);
		ZSTR_VAL(prefixed)[0] = '\1';
		memcpy(ZSTR_VAL(prefixed) + 1, ZSTR_VAL(kb), ZSTR_LEN(kb));
		ZSTR_LEN(prefixed) = 1 + ZSTR_LEN(kb);
		ZSTR_VAL(prefixed)[ZSTR_LEN(prefixed)] = '\0';
	} else {
		return false;
	}

	unsigned char digest[16];

	fast_xxh128_digest((const unsigned char *)ZSTR_VAL(prefixed), ZSTR_LEN(prefixed), digest);
	zend_string_release(prefixed);

	*kb_out = kb;
	*hb_out = zend_string_init((char *)digest, 8, 0);
	*hb2_out = zend_string_init((char *)(digest + 8), 8, 0);
	return true;
}

/* Non-scalars delegate to PHP igbinary (ADR 005 v1). */
static bool fast_igbinary_encode(zval *value, zend_string **out)
{
	uint8_t *buf = NULL;
	size_t len = 0;

	if (igbinary_serialize(&buf, &len, value) != 0 || !buf) {
		return false;
	}
	*out = zend_string_init((char *)buf, len, 0);
	efree(buf);
	return true;
}

static bool fast_igbinary_decode(zend_string *data, zval *out)
{
	/* igbinary header is 4 bytes: 0x00 0x00 0x00 0x01|0x02 (big-endian version). */
	if (ZSTR_LEN(data) < 4) {
		return false;
	}
	{
		const uint8_t *b = (const uint8_t *)ZSTR_VAL(data);

		if (b[0] != 0 || b[1] != 0 || b[2] != 0 || (b[3] != 1 && b[3] != 2)) {
			return false;
		}
	}
	if (igbinary_unserialize((const uint8_t *)ZSTR_VAL(data), ZSTR_LEN(data), out) != 0) {
		return false;
	}
	return true;
}

static bool fast_enc_value(zval *value, uint8_t *vt, zend_string **payload)
{
	if (Z_TYPE_P(value) == IS_NULL) {
		*vt = FAST_TYPE_NULL;
		*payload = zend_string_init("", 0, 0);
		return true;
	}
	if (Z_TYPE_P(value) == IS_TRUE || Z_TYPE_P(value) == IS_FALSE) {
		*vt = FAST_TYPE_BOOL;
		*payload = zend_string_init(Z_TYPE_P(value) == IS_TRUE ? "\1" : "\0", 1, 0);
		return true;
	}
	if (Z_TYPE_P(value) == IS_LONG) {
		*vt = FAST_TYPE_INT;
		*payload = zend_string_alloc(8, 0);
		zend_long v = Z_LVAL_P(value);
		memcpy(ZSTR_VAL(*payload), &v, 8);
		ZSTR_LEN(*payload) = 8;
		ZSTR_VAL(*payload)[8] = '\0';
		return true;
	}
	if (Z_TYPE_P(value) == IS_DOUBLE) {
		*vt = FAST_TYPE_FLOAT;
		*payload = zend_string_alloc(8, 0);
		double v = Z_DVAL_P(value);
		memcpy(ZSTR_VAL(*payload), &v, 8);
		ZSTR_LEN(*payload) = 8;
		ZSTR_VAL(*payload)[8] = '\0';
		return true;
	}
	if (Z_TYPE_P(value) == IS_STRING) {
		*vt = FAST_TYPE_STRING;
		*payload = zend_string_copy(Z_STR_P(value));
		return true;
	}
	*vt = FAST_TYPE_IGBINARY;
	return fast_igbinary_encode(value, payload);
}

static bool fast_dec_value(uint8_t vt, zend_string *payload, zval *out)
{
	switch (vt) {
		case FAST_TYPE_NULL:
			ZVAL_NULL(out);
			return true;
		case FAST_TYPE_BOOL:
			ZVAL_BOOL(out, ZSTR_LEN(payload) > 0 && ZSTR_VAL(payload)[0] != '\0');
			return true;
		case FAST_TYPE_INT: {
			zend_long v = 0;
			if (ZSTR_LEN(payload) >= 8) {
				memcpy(&v, ZSTR_VAL(payload), 8);
			}
			ZVAL_LONG(out, v);
			return true;
		}
		case FAST_TYPE_FLOAT: {
			double v = 0.0;
			if (ZSTR_LEN(payload) >= 8) {
				memcpy(&v, ZSTR_VAL(payload), 8);
			}
			ZVAL_DOUBLE(out, v);
			return true;
		}
		case FAST_TYPE_STRING:
			ZVAL_STR(out, zend_string_copy(payload));
			return true;
		case FAST_TYPE_IGBINARY:
			return fast_igbinary_decode(payload, out);
		default:
			return false;
	}
}

/* ---- semaphore ---- */

static int fast_sem_get(const char *name)
{
#ifdef HAVE_SYS_SEM_H
	char buf[256];
	unsigned long crc = 5381;
	for (const char *p = name; *p; p++) {
		crc = ((crc << 5) + crc) + (unsigned char)*p;
	}
	snprintf(buf, sizeof(buf), "fast-native-sem:%s", name);
	crc = 5381;
	for (const char *p = buf; *p; p++) {
		crc = ((crc << 5) + crc) + (unsigned char)*p;
	}
	key_t key = (key_t)(crc & 0x7fffffff);
	int id = semget(key, 1, IPC_CREAT | IPC_EXCL | 0600);
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
	return -1;
#endif
}

static void fast_sem_lock(int sem_id)
{
#ifdef HAVE_SYS_SEM_H
	struct sembuf op = {0, -1, SEM_UNDO};
	while (semop(sem_id, &op, 1) < 0 && errno == EINTR) {}
#else
	(void)sem_id;
#endif
}

static void fast_sem_unlock(int sem_id)
{
#ifdef HAVE_SYS_SEM_H
	struct sembuf op = {0, 1, 0};
	while (semop(sem_id, &op, 1) < 0 && errno == EINTR) {}
#else
	(void)sem_id;
#endif
}

static int fast_native_seg_key(const char *name, size_t len, int index)
{
	char buf[512];
	uint32_t crc;

	/* crc32('fast-flat:{name}:{index}') — matches Flat::segKey(); mmap path uses fast-native-* prefix. */
	snprintf(buf, sizeof(buf), "fast-flat:%.*s:%d", (int)len, name, index);
	crc = php_crc32_bulk_init();
	crc = php_crc32_bulk_update(crc, buf, strlen(buf));
	return (int)(php_crc32_bulk_end(crc) & 0x7fffffffU);
}

void fast_native_minit(void)
{
	zend_hash_init(&fast_link_refs, 8, NULL, NULL, 1);
	zend_hash_init(&fast_link_fps, 8, NULL, NULL, 1);
	fast_link_pid = getpid();
}

void fast_native_mshutdown(void)
{
	zend_string *key;
	zval *val;

	ZEND_HASH_FOREACH_STR_KEY_VAL(&fast_link_fps, key, val) {
		FILE *fp = (FILE *)Z_PTR_P(val);
		if (fp) {
			flock(fileno(fp), LOCK_UN);
			fclose(fp);
		}
	} ZEND_HASH_FOREACH_END();
	zend_hash_destroy(&fast_link_refs);
	zend_hash_destroy(&fast_link_fps);
}

static void fast_links_check_pid(void)
{
	int pid = getpid();

	if (fast_link_pid >= 0 && pid != fast_link_pid) {
		zend_hash_clean(&fast_link_refs);
		zend_hash_clean(&fast_link_fps);
	}
	fast_link_pid = pid;
}

static void fast_lock_path(fast_native_t *eng, const char *name, size_t len)
{
	const char *dir = "/dev/shm";
	struct stat st;

	if (stat("/dev/shm", &st) != 0 || access("/dev/shm", W_OK) != 0) {
		dir = "/tmp";
	}
	snprintf(eng->lock_path, sizeof(eng->lock_path), "%s/fast-native-%x.lock",
		dir, fast_native_seg_key(name, len, 0));
}

static FILE *fast_lock_fopen(const char *path)
{
	/* PHP Flat uses fopen(..., 'c'); glibc rejects mode "c" — use append create. */
	return fopen(path, "a+");
}

static bool fast_store_unreferenced(fast_native_t *eng)
{
	FILE *fp = fast_lock_fopen(eng->lock_path);
	bool free_store;

	if (!fp) {
		return false;
	}
	/* Non-blocking exclusive flock: succeeds only when no peer holds LOCK_SH. */
	free_store = (flock(fileno(fp), LOCK_EX | LOCK_NB) == 0);
	if (free_store) {
		flock(fileno(fp), LOCK_UN);
	}
	fclose(fp);
	return free_store;
}

static void fast_release_link_fp(fast_native_t *eng)
{
	zval *fpzv = zend_hash_str_find(&fast_link_fps, eng->name, strlen(eng->name));

	if (!fpzv) {
		return;
	}
	FILE *fp = (FILE *)Z_PTR_P(fpzv);
	if (fp) {
		flock(fileno(fp), LOCK_UN);
		fclose(fp);
	}
	zend_hash_str_del(&fast_link_fps, eng->name, strlen(eng->name));
}

static void fast_register_process_link(fast_native_t *eng)
{
	zend_long local = 0;
	zval *ref;

	fast_links_check_pid();
	ref = zend_hash_str_find(&fast_link_refs, eng->name, strlen(eng->name));
	if (ref) {
		local = Z_LVAL_P(ref);
	}
	local++;
	{
		zval zlocal;
		ZVAL_LONG(&zlocal, local);
		zend_hash_str_update(&fast_link_refs, eng->name, strlen(eng->name), &zlocal);
	}
	if (local > 1) {
		eng->linked = true;
		return;
	}

	FILE *fp = fast_lock_fopen(eng->lock_path);
	if (!fp || flock(fileno(fp), LOCK_SH) != 0) {
		if (fp) {
			fclose(fp);
		}
		zend_hash_str_del(&fast_link_refs, eng->name, strlen(eng->name));
		zend_throw_exception(NULL, "shared Fast: unable to acquire link lock file", 0);
		return;
	}
	zval zfp;
	ZVAL_PTR(&zfp, fp);
	zend_hash_str_update(&fast_link_fps, eng->name, strlen(eng->name), &zfp);
	eng->linked = true;
}

static void fast_remove_sem(fast_native_t *eng)
{
#ifdef HAVE_SYS_SEM_H
	if (eng->sem_id >= 0) {
		semctl(eng->sem_id, 0, IPC_RMID);
		eng->sem_id = -1;
	}
#else
	(void)eng;
#endif
}

static void fast_delete_segments(fast_native_t *eng)
{
	if (eng->compat) {
		if (eng->map && eng->map != MAP_FAILED) {
			shmdt(eng->map);
			eng->map = NULL;
		}
		if (eng->seg0_id >= 0) {
			shmctl(eng->seg0_id, IPC_RMID, NULL);
			eng->seg0_id = -1;
		}
		unlink(eng->lock_path);
		fast_remove_sem(eng);
		return;
	}
	if (eng->map && eng->map != MAP_FAILED) {
		munmap(eng->map, eng->map_size);
		eng->map = NULL;
	}
	if (eng->shm_fd >= 0) {
		close(eng->shm_fd);
		eng->shm_fd = -1;
	}
	shm_unlink(eng->shm_path);
	unlink(eng->lock_path);
	fast_remove_sem(eng);
}

void fast_engine_delete_segments(fast_native_t *eng)
{
	fast_delete_segments(eng);
}

static bool fast_reclaim_if_orphaned(fast_native_t *eng)
{
	bool orphaned = false;

	fast_sem_lock(eng->sem_id);
	if (fast_store_unreferenced(eng)) {
		fast_delete_segments(eng);
		orphaned = true;
	}
	fast_sem_unlock(eng->sem_id);
	return orphaned;
}

static bool fast_slot_key_valid(fast_native_t *eng, const char *slot, uint32_t rec_off, uint16_t keylen, uint32_t vallen)
{
	uint32_t total = keylen + vallen;
	uint32_t arena_min = eng->compat ? fast_region_arena_base(eng) : eng->arena_base;
	uint64_t frontier = fast_frontier_val(eng);
	unsigned char digest[16];
	unsigned char prefixed[256];
	size_t plen;
	char *rec;

	if (rec_off < arena_min || rec_off + total > frontier) {
		return false;
	}
	if (keylen == 0) {
		return false;
	}
	rec = fast_rec_ptr(eng, rec_off);
	if (((uint8_t)slot[23] >> 4) == 0) {
		if (keylen != 8) {
			return false;
		}
		prefixed[0] = '\0';
		memcpy(prefixed + 1, rec, 8);
		plen = 9;
	} else {
		if (keylen + 1 > sizeof(prefixed)) {
			return false;
		}
		prefixed[0] = '\1';
		memcpy(prefixed + 1, rec, keylen);
		plen = 1 + keylen;
	}
	fast_xxh128_digest(prefixed, plen, digest);
	return memcmp(digest, slot, 8) == 0 && memcmp(digest + 8, slot + 24, 8) == 0;
}

static inline uint32_t fast_class_cap(uint32_t need)
{
	uint32_t cap = FAST_ALLOC_MIN;

	while (cap < need) {
		cap <<= 1;
	}
	return cap;
}

static inline void fast_class_for_need(uint32_t need, uint32_t *ci_out, uint32_t *cap_out)
{
	uint32_t cap = FAST_ALLOC_MIN;
	uint32_t ci = 0;

	while (cap < need) {
		cap <<= 1;
		ci++;
	}
	*ci_out = ci;
	*cap_out = cap;
}

static inline uint32_t fast_class_index(uint32_t cap)
{
	uint32_t ci = 0;
	uint32_t c = FAST_ALLOC_MIN;

	while (c < cap) {
		c <<= 1;
		ci++;
	}
	return ci;
}

/* Parity: Flat.php freeBlock() — must hold writer lock. */
static void fast_free_block(fast_native_t *eng, uint32_t off, uint32_t cap)
{
	uint32_t ci = fast_class_index(cap);
	char *block;
	uint64_t head;

	if (off < eng->arena_base || (uint64_t)off >= (uint64_t)eng->map_size) {
		return;
	}
	block = fast_rec_ptr(eng, off);
	head = fast_read_u64(eng->map, FAST_H_FREEHEADS + (size_t)ci * 8);
	fast_wu64(eng->map, (size_t)(block - eng->map), head);
	fast_wu64(eng->map, FAST_H_FREEHEADS + (size_t)ci * 8, (uint64_t)off);
}

static void fast_compact_order(fast_native_t *eng);

/* Parity: src/Engine/Flat.php repairAfterCrash() — must hold writer lock. */
static void fast_repair_after_crash(fast_native_t *eng)
{
	uint32_t order_base = fast_order_base(eng->slot_count);
	uint32_t ob = eng->order_bytes;
	uint32_t oc = fast_ru32(eng->map, FAST_H_ORDER);
	uint32_t *covered_gen;
	uint32_t live = 0, tomb = 0;
	uint64_t caps = 0;
	struct {
		uint32_t si;
		uint32_t gen;
	} *missing = NULL;
	uint32_t missing_count = 0;
	uint32_t missing_cap = 0;

	covered_gen = ecalloc(eng->slot_count, sizeof(uint32_t));
	for (uint32_t i = 0; i < eng->slot_count; i++) {
		covered_gen[i] = 0xffffffffU;
	}

	for (uint32_t r = 0; r < oc; r++) {
		uint32_t si = fast_ru32(eng->map, order_base + r * ob);
		uint32_t gen = fast_ru32(eng->map, order_base + r * ob + 4);

		if (si < eng->slot_count) {
			covered_gen[si] = gen;
		}
	}

	for (uint32_t si = 0; si < eng->slot_count; si++) {
		char *slot = eng->map + fast_dir_off(si);
		uint8_t state = (uint8_t)slot[22];

		if (state == FAST_ST_EMPTY) {
			continue;
		}
		if (state == FAST_ST_TOMB) {
			tomb++;
			continue;
		}

		uint32_t rec_off = fast_ru32(slot, 8);
		uint32_t gen = fast_ru32(slot, 12);
		uint32_t vallen = fast_ru32(slot, 16);
		uint16_t keylen = (uint16_t)((uint8_t)slot[20] | ((uint16_t)(uint8_t)slot[21] << 8));

		if (!fast_slot_key_valid(eng, slot, rec_off, keylen, vallen)) {
			slot[22] = (char)FAST_ST_TOMB;
			tomb++;
			continue;
		}

		live++;
		caps += fast_class_cap(keylen + vallen);
		if (covered_gen[si] != gen) {
			if (missing_count >= missing_cap) {
				missing_cap = missing_cap ? missing_cap * 2 : 8;
				missing = erealloc(missing, missing_cap * sizeof(*missing));
			}
			missing[missing_count].si = si;
			missing[missing_count].gen = gen;
			missing_count++;
		}
	}

	for (uint32_t m = 0; m < missing_count; m++) {
		if (oc >= eng->slot_count) {
			fast_compact_order(eng);
			oc = fast_ru32(eng->map, FAST_H_ORDER);
		}
		fast_wu32(eng->map, order_base + oc * ob, missing[m].si);
		fast_wu32(eng->map, order_base + oc * ob + 4, missing[m].gen);
		if (ob == FAST_ORDER_TAGGED) {
			fast_wu64(eng->map, order_base + oc * ob + 8, (uint64_t)zend_hrtime());
		}
		fast_wu32(eng->map, FAST_H_ORDER, oc + 1);
		oc++;
	}

	fast_wu32(eng->map, FAST_H_LIVE, live);
	fast_wu32(eng->map, FAST_H_TOMB, tomb);
	fast_wu64(eng->map, FAST_H_LIVECAPS, caps);

	efree(covered_gen);
	if (missing) {
		efree(missing);
	}
}

static void fast_recover_if_crashed(fast_native_t *eng)
{
	uint32_t seq = fast_ru32(eng->map, FAST_H_SEQ);

	/* Odd seqlock means a writer died mid-critical-section; repair under sem. */
	if ((seq & 1U) == 0) {
		return;
	}
	fast_sem_lock(eng->sem_id);
	seq = fast_ru32(eng->map, FAST_H_SEQ);
	if ((seq & 1U) == 0) {
		fast_sem_unlock(eng->sem_id);
		return;
	}
	fast_repair_after_crash(eng);
	fast_wu32(eng->map, FAST_H_SEQ, seq + 1);
	fast_sem_unlock(eng->sem_id);
}

static void fast_release_process_link(fast_native_t *eng)
{
	zend_long local;
	zval *ref;
	bool reclaim = false;

	if (!eng->linked) {
		return;
	}
	fast_links_check_pid();
	ref = zend_hash_str_find(&fast_link_refs, eng->name, strlen(eng->name));
	if (!ref) {
		eng->linked = false;
		return;
	}
	local = Z_LVAL_P(ref);
	if (local <= 0) {
		eng->linked = false;
		return;
	}
	if (--local > 0) {
		zval zlocal;
		ZVAL_LONG(&zlocal, local);
		zend_hash_str_update(&fast_link_refs, eng->name, strlen(eng->name), &zlocal);
		eng->linked = false;
		return;
	}
	zend_hash_str_del(&fast_link_refs, eng->name, strlen(eng->name));

	fast_sem_lock(eng->sem_id);
	fast_release_link_fp(eng);
	if (!eng->persistent && fast_store_unreferenced(eng)) {
		fast_delete_segments(eng);
		reclaim = true;
	}
	fast_sem_unlock(eng->sem_id);
	eng->linked = false;
	if (reclaim) {
		fast_remove_sem(eng);
	}
}

/* ---- mmap store ---- */

static void fast_shm_path(fast_native_t *eng, const char *name, size_t len)
{
	snprintf(eng->shm_path, sizeof(eng->shm_path), "/fast-native-%x",
		fast_native_seg_key(name, len, 0));
}

static void fast_map_invalidate(fast_native_t *eng)
{
	if (!eng) {
		return;
	}
	eng->map = NULL;
	eng->map_size = 0;
}

/* Remap eng->map to new_size; on total failure the engine is left unmapped. */
static bool fast_map_resize(fast_native_t *eng, size_t new_size)
{
	char *old_map;
	size_t old_size;
	void *nm;

	if (!eng || eng->shm_fd < 0 || new_size < FAST_HEADER) {
		return false;
	}
	if (eng->map && eng->map != MAP_FAILED && new_size == eng->map_size) {
		return true;
	}

	old_map = eng->map;
	old_size = eng->map_size;

	if (old_map && old_map != MAP_FAILED && old_size > 0) {
		nm = mremap(old_map, old_size, new_size, MREMAP_MAYMOVE);
		if (nm != MAP_FAILED) {
			eng->map = (char *)nm;
			eng->map_size = new_size;
			return true;
		}
		munmap(old_map, old_size);
		fast_map_invalidate(eng);
	}

	nm = mmap(NULL, new_size, PROT_READ | PROT_WRITE, MAP_SHARED, eng->shm_fd, 0);
	if (nm == MAP_FAILED) {
		return false;
	}
	eng->map = (char *)nm;
	eng->map_size = new_size;
	return true;
}

static bool fast_map_sync_geometry(fast_native_t *eng)
{
	uint32_t slots;

	if (!eng || !eng->map || eng->map == MAP_FAILED) {
		return false;
	}
	slots = fast_ru32(eng->map, FAST_H_SLOTS);
	if (slots < 1 || (slots & (slots - 1)) != 0) {
		return false;
	}
	eng->slot_count = slots;
	eng->mask = slots - 1;
	eng->order_bytes = fast_ru32(eng->map, FAST_H_ORDERSZ);
	if (eng->order_bytes != FAST_ORDER && eng->order_bytes != FAST_ORDER_TAGGED) {
		eng->order_bytes = FAST_ORDER;
	}
	eng->arena_base = fast_arena_base(eng->slot_count, eng->order_bytes);
	return true;
}

static bool fast_native_sync_map(fast_native_t *eng)
{
	struct stat st;
	size_t actual;

	/* Remap when a peer grew the mmap arena (ftruncate in another process). */
	if (eng->compat || eng->shm_fd < 0) {
		if (!eng->map || eng->map == MAP_FAILED) {
			zend_throw_exception_ex(spl_ce_RuntimeException, 0,
				"shared Fast store mapping lost");
			return false;
		}
		return true;
	}

	if ((!eng->map || eng->map == MAP_FAILED) && eng->shm_fd >= 0) {
		if (fstat(eng->shm_fd, &st) != 0 || (size_t)st.st_size < FAST_HEADER) {
			zend_throw_exception_ex(spl_ce_RuntimeException, 0,
				"shared Fast store mapping lost");
			return false;
		}
		if (!fast_map_resize(eng, (size_t)st.st_size)) {
			zend_throw_exception_ex(spl_ce_RuntimeException, 0,
				"shared Fast store mapping lost");
			return false;
		}
		return fast_map_sync_geometry(eng);
	}

	if (!eng->map || eng->map == MAP_FAILED) {
		zend_throw_exception_ex(spl_ce_RuntimeException, 0,
			"shared Fast store mapping lost");
		return false;
	}

	if (fstat(eng->shm_fd, &st) != 0) {
		fast_map_invalidate(eng);
		zend_throw_exception_ex(spl_ce_RuntimeException, 0,
			"shared Fast store mapping lost");
		return false;
	}

	actual = (size_t)st.st_size;
	if (actual <= eng->map_size || actual < FAST_HEADER) {
		return true;
	}

	if (!fast_map_resize(eng, actual)) {
		zend_throw_exception_ex(spl_ce_RuntimeException, 0,
			"shared Fast store mapping lost while syncing segment size");
		return false;
	}
	if (!fast_map_sync_geometry(eng)) {
		zend_throw_exception_ex(spl_ce_RuntimeException, 0,
			"shared Fast store header is corrupt after remap");
		return false;
	}
	return true;
}

static bool fast_native_prepare(fast_native_t *eng)
{
	if (!eng) {
		return false;
	}
	return fast_native_sync_map(eng);
}

static bool fast_map_grow(fast_native_t *eng, size_t need)
{
	if (need <= eng->map_size) {
		return true;
	}
	size_t new_size = eng->map_size ? eng->map_size : FAST_DEFAULT_SIZE;
	while (new_size < need) {
		new_size *= 2;
	}
	if (ftruncate(eng->shm_fd, (off_t)new_size) != 0) {
		return false;
	}
	if (!fast_map_resize(eng, new_size)) {
		return false;
	}
	return true;
}

static bool fast_name_hash(const char *name, size_t len, char out[16])
{
	fast_xxh128_digest((const unsigned char *)name, len, (unsigned char *)out);
	return true;
}

static void fast_init_fresh_native(fast_native_t *eng, bool persistent)
{
	memset(eng->map, 0, eng->arena_base);
	memcpy(eng->map + FAST_H_MAGIC, FAST_NATIVE_MAGIC, 4);
	fast_wu32(eng->map, FAST_H_LAYOUT, FAST_NATIVE_LAYOUT);
	fast_wu64(eng->map, FAST_H_FRONTIER, eng->arena_base);
	fast_wu32(eng->map, FAST_H_SLOTS, eng->slot_count);
	fast_wu32(eng->map, FAST_H_PERSIST, persistent ? 1 : 0);
	fast_wu32(eng->map, FAST_H_ORDERSZ, eng->order_bytes);
	fast_name_hash(eng->name, strlen(eng->name), eng->map + FAST_H_NAMEHASH);
	eng->persistent = persistent;
}

void fast_engine_init_fresh_compat(fast_native_t *eng, bool persistent)
{
	uint32_t region_arena = fast_region_arena_base(eng);

	memset(eng->map, 0, FAST_HEADER + region_arena);
	memcpy(eng->map + FAST_H_MAGIC, FAST_COMPAT_MAGIC, 4);
	fast_wu32(eng->map, FAST_H_LAYOUT, FAST_PHP_LAYOUT);
	fast_wu64(eng->map, FAST_H_FRONTIER, region_arena);
	fast_wu32(eng->map, FAST_H_SLOTS, eng->slot_count);
	fast_wu32(eng->map, FAST_H_PERSIST, persistent ? 1 : 0);
	fast_wu32(eng->map, FAST_H_ORDERSZ, eng->order_bytes);
	fast_wu32(eng->map, FAST_H_SEQ, 0);
	fast_wu32(eng->map, FAST_H_LIVE, 0);
	fast_wu32(eng->map, FAST_H_TOMB, 0);
	fast_wu32(eng->map, FAST_H_ORDER, 0);
	fast_wu64(eng->map, FAST_H_LIVECAPS, 0);
	fast_name_hash(eng->name, strlen(eng->name), eng->map + FAST_H_NAMEHASH);
	eng->persistent = persistent;
}

void fast_engine_init_fresh_native(fast_native_t *eng, bool persistent)
{
	fast_init_fresh_native(eng, persistent);
}

static bool fast_probe(fast_native_t *eng, uint32_t base, zend_string *kb, zend_string *hb, zend_string *hb2,
	bool need_val, uint8_t *vtype_out, zend_string **payload_out)
{
	uint32_t kl = (uint32_t)ZSTR_LEN(kb);

	/* Open-address directory probe from base (hb low 32 bits). Slot layout: FAST_SLOT bytes
	 * at fast_dir_off(si); state @22, value type low nibble @23. */
	for (uint32_t i = 0; i < eng->slot_count; i++) {
		uint32_t si = (base + i) & eng->mask;
		const char *slot = eng->map + fast_dir_off(si);
		uint8_t state = (uint8_t)slot[22];
		if (state == FAST_ST_EMPTY) {
			return false;
		}
		if (state == FAST_ST_LIVE
			&& memcmp(slot, ZSTR_VAL(hb), 8) == 0
			&& memcmp(slot + 24, ZSTR_VAL(hb2), 8) == 0) {
			uint32_t rec_off = fast_ru32(slot, 8);
			uint32_t vallen = fast_ru32(slot, 16);
			uint8_t vtype = (uint8_t)slot[23] & 0xF;
			if (vtype_out) {
				*vtype_out = vtype;
			}
			if (need_val) {
				zend_string *vb;
				char *rec;
				uint32_t gen_before = fast_ru32(slot, 12);
				uint32_t rec_off_before = rec_off;
				uint32_t vallen_before = vallen;
				char hb2_before[8];

				memcpy(hb2_before, slot + 24, 8);
				rec = fast_rec_ptr(eng, rec_off);
				if (vallen > 0) {
					vb = zend_string_init(rec + kl, vallen, 0);
				} else {
					vb = zend_string_init("", 0, 0);
				}
				if (memcmp(slot + 24, hb2_before, 8) != 0
					|| fast_ru32(slot, 12) != gen_before
					|| fast_ru32(slot, 8) != rec_off_before
					|| fast_ru32(slot, 16) != vallen_before) {
					zend_string_release(vb);
					return false;
				}
				if (payload_out) {
					*payload_out = vb;
				} else {
					zend_string_release(vb);
				}
			}
			return true;
		}
	}
	return false;
}

static bool fast_alloc(fast_native_t *eng, uint32_t need, uint32_t *off_out, uint32_t *cap_out)
{
	uint32_t ci, cap;
	uint64_t frontier;
	size_t limit = fast_alloc_limit(eng);

	fast_class_for_need(need, &ci, &cap);

	/* Reuse size-class free blocks (Flat.php alloc). */
	{
		uint64_t head = fast_read_u64(eng->map, FAST_H_FREEHEADS + (size_t)ci * 8);

		if (head != 0 && head >= (uint64_t)eng->arena_base && head < (uint64_t)eng->map_size) {
			char *block = fast_rec_ptr(eng, (uint32_t)head);
			uint64_t next = fast_read_u64(block, 0);

			fast_wu64(eng->map, FAST_H_FREEHEADS + (size_t)ci * 8, next);
			*off_out = (uint32_t)head;
			*cap_out = cap;
			return true;
		}
	}

	frontier = fast_frontier_val(eng);
	if (eng->compat && eng->payload > 0) {
		uint64_t seg = frontier / eng->payload;
		uint64_t seg_end = (seg + 1) * (uint64_t)eng->payload;

		if (frontier + cap > seg_end) {
			frontier = seg_end;
			seg++;
		}
		if (seg != 0) {
			zend_throw_exception(NULL, "shared Fast segment exhausted", 0);
			return false;
		}
	}

	if (eng->compat && frontier + cap > limit) {
		zend_throw_exception(NULL, "shared Fast segment exhausted", 0);
		return false;
	}
	if (frontier + cap > eng->map_size) {
		if (!eng->compat && !fast_map_grow(eng, (size_t)(frontier + cap))) {
			zend_throw_exception(NULL, "shared Fast segment exhausted", 0);
			return false;
		}
		if (eng->compat) {
			zend_throw_exception(NULL, "shared Fast segment exhausted", 0);
			return false;
		}
	}
	*off_out = (uint32_t)frontier;
	*cap_out = cap;
	fast_wu64(eng->map, FAST_H_FRONTIER, frontier + cap);
	return true;
}

/* Parity: src/Engine/Flat.php compactOrder() — must hold writer lock. */
static void fast_compact_order(fast_native_t *eng)
{
	uint32_t ob = eng->order_bytes;
	uint32_t order_base = fast_order_base(eng->slot_count);
	uint32_t oc = fast_ru32(eng->map, FAST_H_ORDER);
	uint32_t w = 0;

	for (uint32_t r = 0; r < oc; r++) {
		uint32_t si = fast_ru32(eng->map, order_base + r * ob);
		uint32_t gen = fast_ru32(eng->map, order_base + r * ob + 4);
		const char *slot = eng->map + fast_dir_off(si);

		if ((uint8_t)slot[22] != FAST_ST_LIVE || fast_ru32(slot, 12) != gen) {
			continue;
		}
		if (w != r) {
			memcpy(eng->map + order_base + (size_t)w * ob,
				eng->map + order_base + (size_t)r * ob, ob);
		}
		w++;
	}
	fast_wu32(eng->map, FAST_H_ORDER, w);
}

static bool fast_append_order(fast_native_t *eng, uint32_t si, uint32_t gen)
{
	uint32_t oc = fast_ru32(eng->map, FAST_H_ORDER);
	uint32_t ob = eng->order_bytes;
	uint32_t order_base = fast_order_base(eng->slot_count);

	if (oc >= eng->slot_count) {
		fast_compact_order(eng);
		oc = fast_ru32(eng->map, FAST_H_ORDER);
	}
	if (oc >= eng->slot_count) {
		zend_throw_exception(NULL, "shared Fast order log is full", 0);
		return false;
	}

	fast_wu32(eng->map, order_base + oc * ob, si);
	fast_wu32(eng->map, order_base + oc * ob + 4, gen);
	if (ob == FAST_ORDER_TAGGED) {
		fast_wu64(eng->map, order_base + oc * ob + 8, (uint64_t)zend_hrtime());
	}
	fast_wu32(eng->map, FAST_H_ORDER, oc + 1);
	return true;
}

static bool fast_do_insert(fast_native_t *eng, uint32_t si, zend_string *hb, zend_string *hb2,
	zend_string *kb, zend_string *vb, uint8_t types)
{
	uint32_t kl = (uint32_t)ZSTR_LEN(kb);
	uint32_t vl = (uint32_t)ZSTR_LEN(vb);
	uint32_t off, cap;

	if (!fast_alloc(eng, kl + vl, &off, &cap)) {
		return false;
	}

	{
		char *rec = fast_rec_ptr(eng, off);
		memcpy(rec, ZSTR_VAL(kb), kl);
		memcpy(rec + kl, ZSTR_VAL(vb), vl);
	}

	const char *prev = eng->map + fast_dir_off(si);
	uint32_t gen = (((uint8_t)prev[22] == FAST_ST_EMPTY) ? 0 : fast_ru32(prev, 12)) + 1;

	char slot[FAST_SLOT];
	memcpy(slot, ZSTR_VAL(hb), 8);
	fast_wu32(slot, 8, off);
	fast_wu32(slot, 12, gen);
	fast_wu32(slot, 16, vl);
	slot[20] = (char)(kl & 0xff);
	slot[21] = (char)((kl >> 8) & 0xff);
	slot[22] = (char)FAST_ST_LIVE;
	slot[23] = (char)types;
	memcpy(slot + 24, ZSTR_VAL(hb2), 8);
	memcpy(eng->map + fast_dir_off(si), slot, FAST_SLOT);

	fast_wu64(eng->map, FAST_H_LIVECAPS, fast_read_u64(eng->map, FAST_H_LIVECAPS) + cap);
	fast_wu32(eng->map, FAST_H_LIVE, fast_ru32(eng->map, FAST_H_LIVE) + 1);
	if (!fast_append_order(eng, si, gen)) {
		return false;
	}
	return true;
}

/* Overwrite parity: src/Engine/Flat.php doOverwrite() — preserve gen, no order append. */
static bool fast_do_overwrite(fast_native_t *eng, uint32_t si, uint32_t rec_off, uint32_t kl,
	uint32_t old_vl, uint32_t gen, zend_string *kb, zend_string *vb, uint8_t types)
{
	uint32_t vl = (uint32_t)ZSTR_LEN(vb);
	uint32_t need = kl + vl;
	uint32_t old_cap = fast_class_cap(kl + old_vl);
	uint32_t need_cap = fast_class_cap(need);

	if (need <= old_cap && old_cap == need_cap) {
		char *slot = eng->map + fast_dir_off(si);
		char *rec = fast_rec_ptr(eng, rec_off);

		memcpy(rec, ZSTR_VAL(kb), kl);
		memcpy(rec + kl, ZSTR_VAL(vb), vl);
		fast_wu32(slot, 16, vl);
		slot[23] = (char)types;
		return true;
	}
	{
		uint32_t off, cap;

		if (!fast_alloc(eng, need, &off, &cap)) {
			return false;
		}
		{
			char *rec = fast_rec_ptr(eng, off);
			memcpy(rec, ZSTR_VAL(kb), kl);
			memcpy(rec + kl, ZSTR_VAL(vb), vl);
		}
		{
			char slot[FAST_SLOT];

			memcpy(slot, eng->map + fast_dir_off(si), FAST_SLOT);
			fast_wu32(slot, 8, off);
			fast_wu32(slot, 12, gen);
			fast_wu32(slot, 16, vl);
			slot[23] = (char)types;
			memcpy(eng->map + fast_dir_off(si), slot, FAST_SLOT);
		}
		fast_free_block(eng, rec_off, old_cap);
		fast_wu64(eng->map, FAST_H_LIVECAPS,
			fast_read_u64(eng->map, FAST_H_LIVECAPS) + cap - old_cap);
	}
	return true;
}

/* ---- public API ---- */

fast_native_t *fast_native_attach(
	const char *name, size_t name_len, uint32_t size, uint32_t slots, bool persistent,
	bool compat_layout, uint32_t order_bytes)
{
	(void)compat_layout;
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
	eng->arena_base = fast_arena_base(slots, order_bytes);
	eng->spin = fast_engine_lockfree_spin();
	eng->shm_fd = -1;
	eng->sem_id = -1;
	eng->seg0_id = -1;
	eng->compat = false;
	eng->linked = false;
	ZVAL_NULL(&eng->iter_key);
	ZVAL_NULL(&eng->iter_val);

	fast_shm_path(eng, name, name_len);
	fast_lock_path(eng, name, name_len);
	eng->sem_id = fast_sem_get(name);
	if (eng->sem_id < 0) {
		zend_throw_exception(NULL, "unable to obtain shared Fast semaphore", 0);
		efree(eng->name);
		efree(eng);
		return NULL;
	}

	eng->shm_fd = shm_open(eng->shm_path, O_RDWR, 0600);
	bool existing = false;

	if (eng->shm_fd < 0) {
		eng->shm_fd = shm_open(eng->shm_path, O_CREAT | O_EXCL | O_RDWR, 0600);
		if (eng->shm_fd < 0 && errno == EEXIST) {
			eng->shm_fd = shm_open(eng->shm_path, O_RDWR, 0600);
		}
		if (eng->shm_fd < 0) {
			zend_throw_exception(NULL, "unable to open native Fast shared memory", 0);
			fast_native_free(eng);
			return NULL;
		}
		if (ftruncate(eng->shm_fd, (off_t)size) != 0) {
			zend_throw_exception(NULL, "unable to size native Fast shared memory", 0);
			close(eng->shm_fd);
			shm_unlink(eng->shm_path);
			fast_native_free(eng);
			return NULL;
		}
		eng->map_size = size;
	} else {
		struct stat st;
		if (fstat(eng->shm_fd, &st) != 0) {
			zend_throw_exception(NULL, "unable to stat native Fast shared memory", 0);
			close(eng->shm_fd);
			fast_native_free(eng);
			return NULL;
		}
		eng->map_size = (size_t)st.st_size;
	}

	eng->map = mmap(NULL, eng->map_size, PROT_READ | PROT_WRITE, MAP_SHARED, eng->shm_fd, 0);
	if (eng->map == MAP_FAILED) {
		zend_throw_exception(NULL, "unable to mmap native Fast shared memory", 0);
		close(eng->shm_fd);
		fast_native_free(eng);
		return NULL;
	}

	if (eng->map_size >= 4 && memcmp(eng->map + FAST_H_MAGIC, FAST_NATIVE_MAGIC, 4) == 0) {
		if (fast_ru32(eng->map, FAST_H_LAYOUT) != FAST_NATIVE_LAYOUT) {
			zend_throw_exception(NULL, "incompatible shared Fast layout version", 0);
			munmap(eng->map, eng->map_size);
			close(eng->shm_fd);
			fast_native_free(eng);
			return NULL;
		}
		char namehash[16];
		if (!fast_name_hash(name, name_len, namehash)
			|| memcmp(eng->map + FAST_H_NAMEHASH, namehash, 16) != 0) {
			zend_throw_exception(spl_ce_RuntimeException,
				"shared Fast name collision on segment key", 0);
			munmap(eng->map, eng->map_size);
			close(eng->shm_fd);
			fast_native_free(eng);
			return NULL;
		}
		eng->slot_count = fast_ru32(eng->map, FAST_H_SLOTS);
		eng->mask = eng->slot_count - 1;
		eng->order_bytes = fast_ru32(eng->map, FAST_H_ORDERSZ);
		if (eng->order_bytes != FAST_ORDER && eng->order_bytes != FAST_ORDER_TAGGED) {
			eng->order_bytes = FAST_ORDER;
		}
		eng->arena_base = fast_arena_base(eng->slot_count, eng->order_bytes);
		eng->persistent = fast_ru32(eng->map, FAST_H_PERSIST) == 1;
		existing = true;
	}

	if (existing) {
		if (!eng->persistent && fast_reclaim_if_orphaned(eng)) {
			existing = false;
		} else {
			fast_recover_if_crashed(eng);
		}
	}

	if (!existing) {
		eng->slot_count = slots;
		eng->mask = slots - 1;
		eng->order_bytes = order_bytes;
		eng->arena_base = fast_arena_base(slots, order_bytes);
		if (eng->shm_fd < 0) {
			eng->shm_fd = shm_open(eng->shm_path, O_CREAT | O_RDWR, 0600);
		}
		if (eng->shm_fd < 0 || ftruncate(eng->shm_fd, (off_t)size) != 0) {
			zend_throw_exception(NULL, "unable to create native Fast shared memory", 0);
			fast_native_free(eng);
			return NULL;
		}
		eng->map_size = size;
		if (!eng->map || eng->map == MAP_FAILED) {
			eng->map = mmap(NULL, eng->map_size, PROT_READ | PROT_WRITE, MAP_SHARED, eng->shm_fd, 0);
		} else if (eng->map_size != size) {
			munmap(eng->map, eng->map_size);
			eng->map = mmap(NULL, eng->map_size, PROT_READ | PROT_WRITE, MAP_SHARED, eng->shm_fd, 0);
		}
		if (eng->map == MAP_FAILED) {
			zend_throw_exception(NULL, "unable to mmap native Fast shared memory", 0);
			fast_native_free(eng);
			return NULL;
		}
		fast_init_fresh_native(eng, persistent);
	}

	fast_register_process_link(eng);
	if (EG(exception)) {
		munmap(eng->map, eng->map_size);
		close(eng->shm_fd);
		fast_native_free(eng);
		return NULL;
	}

	return eng;
}

void fast_native_free(fast_native_t *eng)
{
	if (!eng) {
		return;
	}
	if (eng->linked) {
		fast_release_process_link(eng);
	}
	if (eng->map && eng->map != MAP_FAILED) {
		if (eng->compat) {
			shmdt(eng->map);
		} else {
			munmap(eng->map, eng->map_size);
		}
	}
	if (!eng->compat && eng->shm_fd >= 0) {
		close(eng->shm_fd);
	}
	zval_ptr_dtor(&eng->iter_key);
	zval_ptr_dtor(&eng->iter_val);
	efree(eng->name);
	efree(eng);
}

void fast_native_close(fast_native_t *eng)
{
	if (!eng) {
		return;
	}
	fast_release_process_link(eng);
	if (eng->map && eng->map != MAP_FAILED) {
		if (eng->compat) {
			shmdt(eng->map);
		} else {
			munmap(eng->map, eng->map_size);
		}
		eng->map = NULL;
	}
	if (!eng->compat && eng->shm_fd >= 0) {
		close(eng->shm_fd);
		eng->shm_fd = -1;
	}
}

bool fast_native_destroy_store(fast_native_t *eng)
{
	FILE *fp;
	bool destroyed = false;

	if (!eng || !eng->map) {
		return false;
	}

	fast_sem_lock(eng->sem_id);
	fp = NULL;
	{
		zval *fpzv = zend_hash_str_find(&fast_link_fps, eng->name, strlen(eng->name));
		if (fpzv) {
			fp = (FILE *)Z_PTR_P(fpzv);
			if (fp) {
				flock(fileno(fp), LOCK_UN);
			}
		}
	}
	if (!fast_store_unreferenced(eng)) {
		if (fp) {
			flock(fileno(fp), LOCK_SH);
		}
		fast_sem_unlock(eng->sem_id);
		zend_throw_exception_ex(spl_ce_RuntimeException, 0,
			"cannot destroy shared Fast \"%s\": another process is still connected", eng->name);
		return false;
	}
	fast_release_link_fp(eng);
	zend_hash_str_del(&fast_link_refs, eng->name, strlen(eng->name));
	fast_delete_segments(eng);
	destroyed = true;
	fast_sem_unlock(eng->sem_id);
	eng->linked = false;
	return destroyed;
}

static uint32_t fast_probe_base(zend_string *hb)
{
	uint64_t v = 0;
	memcpy(&v, ZSTR_VAL(hb), ZSTR_LEN(hb) >= 8 ? 8 : ZSTR_LEN(hb));
	return (uint32_t)(v & 0xffffffffU);
}

bool fast_native_get(fast_native_t *eng, zval *key, zval *value_out)
{
	zend_string *kb, *hb, *hb2;
	uint8_t kt;

	fast_native_prepare(eng);
	if (!eng->map || EG(exception)) {
		return false;
	}
	if (!fast_key_norm(key, &kb, &hb, &hb2, &kt)) {
		return false;
	}
	uint32_t base = fast_probe_base(hb) & eng->mask;
	uint32_t attempts = eng->spin > 0 ? eng->spin : 1;

	/* Snapshot read: copy payload, confirm seq stable, then decode (all types).
	 * Decode failure after a stable snapshot retries (torn igbinary), matching
	 * Flat.php treating decode errors as torn reads. After spin attempts, one
	 * locked pass uses the writer semaphore as the memory barrier. */
	for (uint32_t attempt = 0; attempt < attempts + 1; attempt++) {
		bool use_lock = (eng->spin == 0 || attempt >= eng->spin);
		uint32_t s1, s2;
		zend_string *payload = NULL;
		uint8_t vtype = 0;
		bool hit;

		if (use_lock) {
			fast_sem_lock(eng->sem_id);
		}
		s1 = fast_ru32(eng->map, FAST_H_SEQ);
		if (!use_lock && (s1 & 1U)) {
			if (use_lock) {
				fast_sem_unlock(eng->sem_id);
			}
			continue;
		}
		hit = fast_probe(eng, base, kb, hb, hb2, true, &vtype, &payload);
		s2 = fast_ru32(eng->map, FAST_H_SEQ);
		if (!use_lock && (s1 != s2 || (s2 & 1U))) {
			if (payload) {
				zend_string_release(payload);
			}
			if (use_lock) {
				fast_sem_unlock(eng->sem_id);
			}
			continue;
		}
		if (!hit) {
			if (payload) {
				zend_string_release(payload);
			}
			if (use_lock) {
				fast_sem_unlock(eng->sem_id);
			}
			zend_string_release(kb);
			zend_string_release(hb);
			zend_string_release(hb2);
			return false;
		}
		if (!payload || !fast_dec_value(vtype, payload, value_out)) {
			if (payload) {
				zend_string_release(payload);
			}
			if (use_lock) {
				fast_sem_unlock(eng->sem_id);
			}
			continue;
		}
		if (payload) {
			zend_string_release(payload);
		}
		if (use_lock) {
			fast_sem_unlock(eng->sem_id);
		}
		zend_string_release(kb);
		zend_string_release(hb);
		zend_string_release(hb2);
		return true;
	}

	zend_string_release(kb);
	zend_string_release(hb);
	zend_string_release(hb2);
	return false;
}

bool fast_native_has(fast_native_t *eng, zval *key)
{
	zend_string *kb, *hb, *hb2;
	uint8_t kt, vtype = 0;

	fast_native_prepare(eng);
	if (!eng->map || EG(exception)) {
		return false;
	}
	if (!fast_key_norm(key, &kb, &hb, &hb2, &kt)) {
		return false;
	}
	uint32_t base = fast_probe_base(hb) & eng->mask;
	bool hit = false;

	/* Seqlock readers: spin while even seq stable; fall back to sem if contended. */
	for (uint32_t spin = 0; spin < eng->spin; spin++) {
		uint32_t s1 = fast_ru32(eng->map, FAST_H_SEQ);
		if (s1 & 1U) {
			continue;
		}
		hit = fast_probe(eng, base, kb, hb, hb2, false, &vtype, NULL);
		if (s1 == fast_ru32(eng->map, FAST_H_SEQ)) {
			break;
		}
		hit = false;
	}
	if (!hit) {
		fast_sem_lock(eng->sem_id);
		hit = fast_probe(eng, base, kb, hb, hb2, false, &vtype, NULL);
		fast_sem_unlock(eng->sem_id);
	}

	zend_string_release(kb);
	zend_string_release(hb);
	zend_string_release(hb2);
	return hit && vtype != FAST_TYPE_NULL;
}

/* Writers bump seq odd→even under sem (seq+1 … work … seq+2). Readers use fast_native_get. */
void fast_native_set(fast_native_t *eng, zval *key, zval *value)
{
	zend_string *kb, *hb, *hb2, *vb;
	uint8_t kt, vt;

	fast_native_prepare(eng);
	if (!eng->map || EG(exception)) {
		return;
	}
	if (!fast_key_norm(key, &kb, &hb, &hb2, &kt)) {
		zend_throw_exception(NULL, "ext-fast: invalid key", 0);
		return;
	}
	if (!fast_enc_value(value, &vt, &vb)) {
		zend_string_release(kb);
		zend_string_release(hb);
		zend_string_release(hb2);
		zend_throw_exception(NULL, "ext-fast: unable to encode value", 0);
		return;
	}
	uint8_t types = (kt << 4) | vt;
	uint32_t base = fast_probe_base(hb) & eng->mask;

	fast_sem_lock(eng->sem_id);
	uint32_t seq = fast_ru32(eng->map, FAST_H_SEQ);
	fast_wu32(eng->map, FAST_H_SEQ, seq + 1);

	int insert_slot = -1;
	bool write_ok = false;

	for (uint32_t i = 0; i < eng->slot_count; i++) {
		uint32_t si = (base + i) & eng->mask;
		const char *slot = eng->map + fast_dir_off(si);
		uint8_t state = (uint8_t)slot[22];
		if (state == FAST_ST_EMPTY) {
			if (insert_slot < 0) {
				insert_slot = (int)si;
			}
			if (!fast_do_insert(eng, (uint32_t)insert_slot, hb, hb2, kb, vb, types)) {
				break;
			}
			write_ok = true;
			break;
		}
		if (state == FAST_ST_TOMB) {
			if (insert_slot < 0) {
				insert_slot = (int)si;
			}
			continue;
		}
		if (memcmp(slot, ZSTR_VAL(hb), 8) == 0 && memcmp(slot + 24, ZSTR_VAL(hb2), 8) == 0) {
			uint32_t rec_off = fast_ru32(slot, 8);
			uint32_t old_vl = fast_ru32(slot, 16);
			uint32_t gen = fast_ru32(slot, 12);
			if (!fast_do_overwrite(eng, si, rec_off, (uint32_t)ZSTR_LEN(kb), old_vl, gen, kb, vb, types)) {
				break;
			}
			write_ok = true;
			break;
		}
	}
	if (!write_ok && !EG(exception)) {
		zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
			"shared Fast directory is full");
	}
	fast_wu32(eng->map, FAST_H_SEQ, seq + 2);
	fast_sem_unlock(eng->sem_id);

	zend_string_release(kb);
	zend_string_release(hb);
	zend_string_release(hb2);
	zend_string_release(vb);
}

void fast_native_delete(fast_native_t *eng, zval *key)
{
	zend_string *kb, *hb, *hb2;
	uint8_t kt;

	fast_native_prepare(eng);
	if (!eng->map || EG(exception)) {
		return;
	}
	if (!fast_key_norm(key, &kb, &hb, &hb2, &kt)) {
		return;
	}
	uint32_t base = fast_probe_base(hb) & eng->mask;

	fast_sem_lock(eng->sem_id);
	uint32_t seq = fast_ru32(eng->map, FAST_H_SEQ);
	fast_wu32(eng->map, FAST_H_SEQ, seq + 1);

	for (uint32_t i = 0; i < eng->slot_count; i++) {
		uint32_t si = (base + i) & eng->mask;
		char *slot = eng->map + fast_dir_off(si);
		if ((uint8_t)slot[22] == FAST_ST_LIVE
			&& memcmp(slot, ZSTR_VAL(hb), 8) == 0
			&& memcmp(slot + 24, ZSTR_VAL(hb2), 8) == 0) {
			uint32_t rec_off = fast_ru32(slot, 8);
			uint32_t vallen = fast_ru32(slot, 16);
			uint16_t keylen = (uint16_t)((uint8_t)slot[20] | ((uint16_t)(uint8_t)slot[21] << 8));
			uint32_t cap = fast_class_cap(keylen + vallen);

			slot[22] = (char)FAST_ST_TOMB;
			fast_wu32(eng->map, FAST_H_LIVE, fast_ru32(eng->map, FAST_H_LIVE) - 1);
			fast_wu32(eng->map, FAST_H_TOMB, fast_ru32(eng->map, FAST_H_TOMB) + 1);
			fast_free_block(eng, rec_off, cap);
			fast_wu64(eng->map, FAST_H_LIVECAPS, fast_read_u64(eng->map, FAST_H_LIVECAPS) - cap);
			break;
		}
	}
	fast_wu32(eng->map, FAST_H_SEQ, seq + 2);
	fast_sem_unlock(eng->sem_id);

	zend_string_release(kb);
	zend_string_release(hb);
	zend_string_release(hb2);
}

zend_long fast_native_count(fast_native_t *eng)
{
	if (!fast_native_prepare(eng) || !eng->map) {
		return 0;
	}
	return (zend_long)fast_ru32(eng->map, FAST_H_LIVE);
}

uint32_t fast_native_live_count(fast_native_t *eng)
{
	if (!eng || !eng->map || eng->map == MAP_FAILED) {
		return 0;
	}
	return fast_ru32(eng->map, FAST_H_LIVE);
}

uint64_t fast_native_iter_tag(fast_native_t *eng)
{
	return eng->iter_tag;
}

bool fast_engine_is_sole_connection(fast_native_t *eng)
{
	FILE *fp = NULL;
	bool sole;

	if (!eng || !eng->map) {
		return true;
	}

	fast_sem_lock(eng->sem_id);
	{
		zval *fpzv = zend_hash_str_find(&fast_link_fps, eng->name, strlen(eng->name));
		if (fpzv) {
			fp = (FILE *)Z_PTR_P(fpzv);
			if (fp) {
				flock(fileno(fp), LOCK_UN);
			}
		}
	}
	sole = fast_store_unreferenced(eng);
	if (fp) {
		flock(fileno(fp), LOCK_SH);
	}
	fast_sem_unlock(eng->sem_id);
	return sole;
}

bool fast_native_destroy_store_force(fast_native_t *eng)
{
	if (!eng || !eng->map) {
		return false;
	}

	fast_sem_lock(eng->sem_id);
	fast_release_link_fp(eng);
	zend_hash_str_del(&fast_link_refs, eng->name, strlen(eng->name));
	fast_delete_segments(eng);
	fast_sem_unlock(eng->sem_id);
	eng->linked = false;
	return true;
}

void fast_native_lock(fast_native_t *eng)
{
	if (eng) {
		fast_sem_lock(eng->sem_id);
	}
}

void fast_native_unlock(fast_native_t *eng)
{
	if (eng) {
		fast_sem_unlock(eng->sem_id);
	}
}

const char *fast_native_store_name(fast_native_t *eng)
{
	return eng ? eng->name : NULL;
}

uint32_t fast_native_directory_slots(fast_native_t *eng)
{
	return eng ? eng->slot_count : 0;
}

uint32_t fast_native_segment_size(fast_native_t *eng)
{
	return eng ? (uint32_t)eng->map_size : 0;
}

bool fast_native_is_persistent(fast_native_t *eng)
{
	return eng ? eng->persistent : false;
}

#define FAST_ORDER_STALE  0
#define FAST_ORDER_HIT    1
#define FAST_ORDER_TORN  -1

/* Snapshot one order-log position and decode from copies (Flat.php readOrderPos).
 * Caller must hold seqlock stability or the writer semaphore. */
static int fast_try_order_pos(fast_native_t *eng, uint32_t pos, zval *key_out, zval *val_out)
{
	uint32_t ob = eng->order_bytes;
	uint32_t order_base = fast_order_base(eng->slot_count);
	char entry[FAST_ORDER_TAGGED];
	uint32_t si, gen, rec_off, vallen, slot_gen;
	uint16_t keylen;
	uint8_t types, kt, vt;
	uint64_t tag = 0;
	char slot[FAST_SLOT];
	char *rec = NULL;
	size_t rec_len;

	memcpy(entry, eng->map + order_base + (size_t)pos * ob, ob);
	si = fast_ru32(entry, 0);
	gen = fast_ru32(entry, 4);
	tag = ob == FAST_ORDER_TAGGED ? fast_read_u64(entry, 8) : 0;

	memcpy(slot, eng->map + fast_dir_off(si), FAST_SLOT);
	if ((uint8_t)slot[22] != FAST_ST_LIVE || fast_ru32(slot, 12) != gen) {
		return FAST_ORDER_STALE;
	}

	rec_off = fast_ru32(slot, 8);
	vallen = fast_ru32(slot, 16);
	slot_gen = fast_ru32(slot, 12);
	keylen = (uint16_t)((uint8_t)slot[20] | ((uint16_t)(uint8_t)slot[21] << 8));
	types = (uint8_t)slot[23];
	kt = types >> 4;
	vt = types & 0xF;
	rec_len = (size_t)keylen + (size_t)vallen;

	if (rec_len > 0) {
		rec = emalloc(rec_len);
		memcpy(rec, fast_rec_ptr(eng, rec_off), rec_len);
	}

	{
		const char *slot_live = eng->map + fast_dir_off(si);

		if ((uint8_t)slot_live[22] != FAST_ST_LIVE
			|| fast_ru32(slot_live, 12) != slot_gen
			|| fast_ru32(slot_live, 8) != rec_off
			|| fast_ru32(slot_live, 16) != vallen) {
			if (rec) {
				efree(rec);
			}
			return FAST_ORDER_TORN;
		}
	}

	if (kt == 0) {
		zend_long v = 0;

		if (keylen >= 8 && rec) {
			memcpy(&v, rec, 8);
		}
		ZVAL_LONG(key_out, v);
	} else {
		ZVAL_STRINGL(key_out, rec ? rec : "", keylen);
	}

	{
		zend_string *vb = vallen > 0
			? zend_string_init(rec ? rec + keylen : "", vallen, 0)
			: zend_string_init("", 0, 0);

		if (!fast_dec_value(vt, vb, val_out)) {
			zend_string_release(vb);
			if (rec) {
				efree(rec);
			}
			zval_ptr_dtor(key_out);
			ZVAL_NULL(key_out);
			return FAST_ORDER_TORN;
		}
		zend_string_release(vb);
	}
	if (rec) {
		efree(rec);
	}
	eng->iter_tag = tag;
	return FAST_ORDER_HIT;
}

/* Parity: Flat.php iterAdvance() — seqlock spin, sem fallback. */
static bool fast_resolve_order_pos(fast_native_t *eng, uint32_t pos, zval *key_out, zval *val_out)
{
	int r;

	for (uint32_t spin = 0; spin < eng->spin; spin++) {
		uint32_t s1 = fast_ru32(eng->map, FAST_H_SEQ);

		if (s1 & 1U) {
			continue;
		}
		r = fast_try_order_pos(eng, pos, key_out, val_out);
		{
			uint32_t s2 = fast_ru32(eng->map, FAST_H_SEQ);

			if (s1 != s2 || (s2 & 1U)) {
				if (r == FAST_ORDER_HIT) {
					zval_ptr_dtor(key_out);
					zval_ptr_dtor(val_out);
					ZVAL_NULL(key_out);
					ZVAL_NULL(val_out);
				}
				continue;
			}
		}
		if (r == FAST_ORDER_TORN) {
			continue;
		}
		return r == FAST_ORDER_HIT;
	}

	fast_sem_lock(eng->sem_id);
	r = fast_try_order_pos(eng, pos, key_out, val_out);
	fast_sem_unlock(eng->sem_id);
	return r == FAST_ORDER_HIT;
}

static void fast_iter_advance(fast_native_t *eng)
{
	zval_ptr_dtor(&eng->iter_key);
	zval_ptr_dtor(&eng->iter_val);
	ZVAL_NULL(&eng->iter_key);
	ZVAL_NULL(&eng->iter_val);
	eng->iter_ready = false;

	if (!fast_native_prepare(eng) || !eng->map) {
		return;
	}
	uint32_t order_count = fast_ru32(eng->map, FAST_H_ORDER);
	while (eng->iter_pos < order_count) {
		zval k, v;

		if (fast_resolve_order_pos(eng, eng->iter_pos, &k, &v)) {
			ZVAL_COPY_VALUE(&eng->iter_key, &k);
			ZVAL_COPY_VALUE(&eng->iter_val, &v);
			eng->iter_ready = true;
			return;
		}
		eng->iter_pos++;
	}
}

void fast_native_rewind(fast_native_t *eng)
{
	eng->iter_pos = 0;
	fast_iter_advance(eng);
}

bool fast_native_valid(fast_native_t *eng)
{
	return eng->iter_ready;
}

void fast_native_key(fast_native_t *eng, zval *key_out)
{
	if (eng->iter_ready) {
		ZVAL_COPY(key_out, &eng->iter_key);
	} else {
		ZVAL_NULL(key_out);
	}
}

void fast_native_current(fast_native_t *eng, zval *value_out)
{
	if (eng->iter_ready) {
		ZVAL_COPY(value_out, &eng->iter_val);
	} else {
		ZVAL_NULL(value_out);
	}
}

void fast_native_next(fast_native_t *eng)
{
	eng->iter_pos++;
	fast_iter_advance(eng);
}

void fast_native_seek(fast_native_t *eng, zend_long pos)
{
	zend_long cnt = fast_native_count(eng);
	if (pos < 0 || pos >= cnt) {
		zend_throw_exception_ex(spl_ce_OutOfBoundsException, 0,
			"Seek position %ld is out of range", pos);
		return;
	}

	eng->iter_pos = 0;
	zend_long idx = -1;
	zval_ptr_dtor(&eng->iter_key);
	zval_ptr_dtor(&eng->iter_val);
	ZVAL_NULL(&eng->iter_key);
	ZVAL_NULL(&eng->iter_val);
	eng->iter_ready = false;

	if (!fast_native_prepare(eng) || !eng->map) {
		return;
	}
	uint32_t order_count = fast_ru32(eng->map, FAST_H_ORDER);

	while (eng->iter_pos < order_count) {
		zval k, v;

		if (fast_resolve_order_pos(eng, eng->iter_pos, &k, &v)) {
			idx++;
			if (idx == pos) {
				ZVAL_COPY_VALUE(&eng->iter_key, &k);
				ZVAL_COPY_VALUE(&eng->iter_val, &v);
				eng->iter_ready = true;
				return;
			}
			zval_ptr_dtor(&k);
			zval_ptr_dtor(&v);
		}
		eng->iter_pos++;
	}

	zend_throw_exception_ex(zend_ce_value_error, 0,
		"Seek position %ld is out of range", pos);
}

void fast_engine_register_link(fast_native_t *eng)
{
	fast_register_process_link(eng);
}

void fast_engine_release_link(fast_native_t *eng)
{
	fast_release_process_link(eng);
}

bool fast_engine_reclaim_if_orphaned(fast_native_t *eng)
{
	return fast_reclaim_if_orphaned(eng);
}

void fast_engine_recover_if_crashed(fast_native_t *eng)
{
	fast_recover_if_crashed(eng);
}

bool fast_engine_name_hash_match(fast_native_t *eng, const char *name, size_t len)
{
	char namehash[16];

	if (!fast_name_hash(name, len, namehash)) {
		return false;
	}
	return memcmp(eng->map + FAST_H_NAMEHASH, namehash, 16) == 0;
}

void fast_engine_adopt_geometry(fast_native_t *eng)
{
	if (!fast_native_prepare(eng) || !eng->map) {
		return;
	}
	eng->slot_count = fast_ru32(eng->map, FAST_H_SLOTS);
	eng->mask = eng->slot_count - 1;
	eng->order_bytes = fast_ru32(eng->map, FAST_H_ORDERSZ);
	if (eng->order_bytes != FAST_ORDER && eng->order_bytes != FAST_ORDER_TAGGED) {
		eng->order_bytes = FAST_ORDER;
	}
	if (eng->compat) {
		eng->payload = (uint32_t)(eng->map_size - FAST_HEADER);
		eng->arena_base = FAST_HEADER + fast_region_arena_base(eng);
	} else {
		eng->arena_base = fast_arena_base(eng->slot_count, eng->order_bytes);
	}
	eng->persistent = fast_ru32(eng->map, FAST_H_PERSIST) == 1;
}
