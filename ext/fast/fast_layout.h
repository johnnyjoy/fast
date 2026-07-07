/*
  +----------------------------------------------------------------------+
  | Copyright (c) 2025-2026 James Dornan                                  |
  +----------------------------------------------------------------------+
  | Licensed under the MIT License, see the LICENSE file for details.    |
  +----------------------------------------------------------------------+
 */

#ifndef FAST_LAYOUT_H
#define FAST_LAYOUT_H

#include <stdint.h>

#define FAST_NATIVE_MAGIC     "XFST"
#define FAST_NATIVE_LAYOUT    1

#define FAST_COMPAT_MAGIC     "FLT2"
#define FAST_PHP_LAYOUT       1

#define FAST_HEADER           1024
#define FAST_SLOT             32   /* directory entry; see docs/extension-layout-native.md */
#define FAST_ORDER            8
#define FAST_ORDER_TAGGED     16
#define FAST_ALLOC_MIN        16
#define FAST_FREE_CLASSES     32

#define FAST_ST_EMPTY         0
#define FAST_ST_LIVE          1
#define FAST_ST_TOMB          2

#define FAST_TYPE_NULL        0
#define FAST_TYPE_BOOL        1
#define FAST_TYPE_INT         2
#define FAST_TYPE_FLOAT       3
#define FAST_TYPE_STRING      4
#define FAST_TYPE_IGBINARY    5

#define FAST_H_MAGIC          0
#define FAST_H_LAYOUT         4
#define FAST_H_SEQ            8
#define FAST_H_LIVE           12
#define FAST_H_TOMB           16
#define FAST_H_ORDER          20
#define FAST_H_FRONTIER       24
#define FAST_H_SLOTS          32
#define FAST_H_PERSIST        36
#define FAST_H_FREEHEADS      64
#define FAST_H_NAMEHASH       320
#define FAST_H_LIVECAPS       576
#define FAST_H_ORDERSZ        584

#define FAST_DEFAULT_SLOTS    16384
#define FAST_DEFAULT_SIZE     (8 * 1024 * 1024)

#define FAST_SPIN             64

static inline uint32_t fast_ru32(const char *base, size_t off)
{
	const unsigned char *p = (const unsigned char *)(base + off);
	return (uint32_t)p[0] | ((uint32_t)p[1] << 8) | ((uint32_t)p[2] << 16) | ((uint32_t)p[3] << 24);
}

static inline void fast_wu32(char *base, size_t off, uint32_t v)
{
	base[off]     = (char)(v & 0xff);
	base[off + 1] = (char)((v >> 8) & 0xff);
	base[off + 2] = (char)((v >> 16) & 0xff);
	base[off + 3] = (char)((v >> 24) & 0xff);
}

static inline uint64_t fast_read_u64(const char *base, size_t off)
{
	return (uint64_t)fast_ru32(base, off) | ((uint64_t)fast_ru32(base, off + 4) << 32);
}

static inline void fast_wu64(char *base, size_t off, uint64_t v)
{
	fast_wu32(base, off, (uint32_t)(v & 0xffffffffU));
	fast_wu32(base, off + 4, (uint32_t)(v >> 32));
}

static inline uint32_t fast_order_base(uint32_t slot_count)
{
	return FAST_HEADER + slot_count * FAST_SLOT;
}

static inline uint32_t fast_arena_base(uint32_t slot_count, uint32_t order_bytes)
{
	return FAST_HEADER + slot_count * (FAST_SLOT + order_bytes);
}

static inline size_t fast_dir_off(uint32_t si)
{
	return (size_t)FAST_HEADER + (size_t)si * (size_t)FAST_SLOT;
}

static inline uint32_t fast_min_segment_size(uint32_t slots, uint32_t order_bytes)
{
	return FAST_HEADER + slots * (FAST_SLOT + order_bytes) + FAST_ALLOC_MIN + 1;
}

#endif
