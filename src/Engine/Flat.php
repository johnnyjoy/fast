<?php
/**
 * Flat shared-memory engine for Fast.
 *
 * @package   Fast
 * @copyright Copyright (c) 2026 johnnyjoy
 * @license   https://opensource.org/licenses/MIT MIT License
 * @link      https://github.com/johnnyjoy/fast
 */

declare(strict_types = 1);

namespace Fast\Engine;

use Fast\Contract\Engine;
use Fast\Exception\LayoutException;
use Fast\Exception\ShmExhaustedException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Flat — the Fast shared-memory engine.
 *
 * A clean-room re-architecture built directly from the measured cost floor
 * (research/experiments/05-primitives/run.php): shmop reads/writes and igbinary are cheap
 * (~55-200 ns); multi-field `unpack` is the expensive primitive. So the layout is
 * designed to touch it as little as possible.
 *
 * Per get: ONE directory-slot read + ONE record read. The 8-byte key hash is
 * compared as raw bytes (substr/===, no unpack); a single tail unpack happens only
 * on a hash match. Slots are self-describing (hash, record offset, capacity, value
 * length, key length, state, types) so reads never parse a record header nor chase
 * a secondary id table. Writes patch only the bytes that change; there is no
 * whole-header rewrite. Insertion order is a compact 8-byte-per-entry order log
 * (slot index + slot generation, so reused slots' stale entries are skipped).
 *
 * Concurrency: a single writer semaphore serializes writes; reads are lock-free
 * and validated by a seqlock (an odd sequence marks a write in progress; a reader
 * accepts a probe only if the sequence was even and unchanged across it, else it
 * retries and finally falls back to a locked read). Correct on x86/TSO; PHP/shmop
 * expose no explicit fences (documented engine caveat, inherited from the spec).
 *
 * Storage spans multiple shmop segments: the fixed regions (header, free-list
 * heads, directory, order log) live in segment 0; the value arena continues into
 * growth segments created on demand. Lifecycle is tracked with a per-store flock
 * lock file: every live connection holds a shared (LOCK_SH) lock for its lifetime,
 * so a crashed or SIGKILLed process is released by the kernel automatically — no
 * PID table to sweep, no PID-reuse phantom, no fixed connection ceiling. Last one
 * out (a non-blocking exclusive probe finds no other holder) reclaims a
 * non-persistent store; persistent stores survive; destroy() requires sole
 * ownership.
 *
 * Platform: shared mode requires a 64-bit PHP build (PHP_INT_SIZE === 8). The
 * binary layout packs 64-bit fields (`P`) and uses `unpack('P', hash) & mask` for
 * directory indexing; on a 32-bit build those values exceed the int range and
 * round-trip through float, silently corrupting the directory. attach() therefore
 * refuses a 32-bit build up front rather than corrupting data. Local (non-shared)
 * mode uses native PHP arrays and has no such requirement.
 *
 * @package Fast
 * @license https://opensource.org/licenses/MIT MIT License
 * @link    https://github.com/johnnyjoy/fast
 */
final class Flat implements Engine
{
    public const string MAGIC = 'FLT2';
    public const int LAYOUT = 1;            // sanity stamp for the (single, in-progress) on-disk format — not a release version

    public const int DEFAULT_SLOTS = 16384;
    public const int DEFAULT_SIZE = 8 * 1024 * 1024;
    public const int MAX_SEGMENTS = 4096;

    private const int SLOT = 32;            // self-describing directory slot (SC-8 dual-hash: +8B confirm tag)
    private const int ORDER = 8;            // untagged order-log entry (slot index + generation)
    private const int ORDER_TAGGED = 16;    // tagged order-log entry (+ 8-byte hrtime tag for striped merge)
    private const int HEADER = 1024;        // segment-0 reserved header

    // header field byte offsets (absolute within segment 0)
    private const int H_MAGIC = 0;
    private const int H_LAYOUT = 4;
    private const int H_SEQ = 8;
    private const int H_LIVE = 12;
    private const int H_TOMB = 16;
    private const int H_ORDER = 20;
    private const int H_FRONTIER = 24;      // 8 bytes
    private const int H_SLOTS = 32;
    private const int H_PERSIST = 36;
    // offsets 40..64 are reserved (retired PID-table link count); the old PID slot
    // region (320..576) is reused below — H_NAMEHASH takes 320..336, 336..576 stays
    // reserved. H_LIVECAPS keeps its fixed offset so the persisted layout is unchanged.
    private const int H_FREEHEADS = 64;     // 32 classes * 8 = 256 -> ends 320
    private const int FREE_CLASSES = 32;
    private const int H_NAMEHASH = 320;     // 16 bytes: xxh128 of the store name (crc32 key-collision guard)
    private const int H_LIVECAPS = 576;     // 8 bytes: sum of live block capacities (compaction trigger)
    private const int H_ORDERSZ = 584;      // 4 bytes: order-log entry size (8 untagged / 16 tagged)

    private const int ST_EMPTY = 0;
    private const int ST_LIVE = 1;
    private const int ST_TOMB = 2;

    private const int TYPE_NULL = 0;
    private const int TYPE_BOOL = 1;
    private const int TYPE_INT = 2;
    private const int TYPE_FLOAT = 3;
    private const int TYPE_STRING = 4;
    private const int TYPE_IGBINARY = 5;

    private const int SPIN = 64;
    private const int ALLOC_MIN = 16;

    /** @var array<int,\Shmop> segment index => handle */
    private array $segs = [];
    private $sem = null;                // write semaphore (SysvSemaphore|resource); null in local mode

    // Facade-facing state: the Fast facade reads these fields directly (stats /
    // serialize). They are the geometry half of the engine contract that a PHP 8.1
    // interface cannot declare (see Engine). Everything below this block is
    // engine-internal.
    public bool $sharedMode = false;
    public ?string $name = null;
    public bool $persistent = false;
    public int $size = 0;
    public int $slotCount = 0;

    private int $mask = 0;              // slotCount - 1: directory index mask (engine-internal)
    private int $payload = 0;           // usable bytes per segment (size - HEADER)
    private int $arenaBase = 0;         // region offset where the arena begins
    private int $key = 0;
    private int $lockDepth = 0;
    private int $ownerPid = 0;
    private int $dirtyBytes = 0;        // bytes allocated+freed since the last compaction threshold check
    private int $orderBytes = self::ORDER;  // 8 (untagged) or 16 (hrtime-tagged for striped global-order merge)
    private int $spin = self::SPIN;     // lock-free read attempts before the locked fallback; set to 0 on non-TSO CPUs so reads are always fence-safe (see tsoReads)

    /** @var array<int,array<int,int>> pid => key => local handle count */
    private static array $localLinks = [];

    /** @var array<int,array<int,resource>> pid => key => the process's LOCK_SH link handle */
    private static array $linkFps = [];

    /** Memoized per process: directory that holds the per-store flock lock files. */
    private static ?string $lockDir = null;

    /** Optional diagnostic sink for otherwise-silent failures (M6). null => off, zero cost. @var (callable(string,array):void)|null */
    private static $diag = null;

    /** Whether the diagnostic sink has been resolved (explicit set or env auto-install) this process. */
    private static bool $diagResolved = false;

    /** Memoized per process: is the lock-free seqlock read path safe on this CPU? */
    private static ?bool $tsoReads = null;

    // iterator cursor (foreach/each over the order log)
    private int $position = 0;
    private ?array $iterCur = null;     // [key, value] of the current live entry
    private int $iterCurTag = 0;        // hrtime tag of the current entry (0 when untagged); used by Striped's k-way merge

    // ---- local (non-shared) mode: a plain insertion-ordered PHP map ----
    /** @var array<string,array{0:int|string,1:mixed}> scratchKey => [key,value] */
    private array $localData = [];

    /**
     * Fail fast when ext-igbinary is not loaded.
     *
     * @return void
     *
     * @throws RuntimeException when igbinary is missing.
     */
    public static function requireIgbinary(): void
    {
        if (!\extension_loaded('igbinary')) {
            throw new RuntimeException('Fast requires the igbinary extension; install/enable ext-igbinary');
        }
    }

    /**
     * Whether the lock-free seqlock read path is safe on this CPU. The seqlock has
     * no explicit memory fence (PHP/shmop expose none and FFI is banned), so it is
     * only correct on Total-Store-Order CPUs (x86/x86_64). On weakly-ordered CPUs
     * (ARM/aarch64, POWER, RISC-V) a writer's data stores may be observed AFTER its
     * sequence store, so reads must instead go through the writer lock, whose
     * sem_acquire/release syscalls carry the full memory barrier. Memoized per
     * process; FAST_LOCKFREE=0/1 overrides detection (testing / ops escape
     * hatch). Cold: consulted once per attach, never on the read path itself.
     */
    private static function tsoReads(): bool
    {
        if (self::$tsoReads !== null) { return self::$tsoReads; }
        $env = \getenv('FAST_LOCKFREE');
        if ($env !== false && $env !== '') { return self::$tsoReads = ($env !== '0'); }
        static $tso = ['x86_64' => 1, 'amd64' => 1, 'i386' => 1, 'i486' => 1, 'i586' => 1, 'i686' => 1, 'x86' => 1];
        return self::$tsoReads = isset($tso[\strtolower(\php_uname('m'))]);
    }

    /**
     * Switch the engine into local (non-shared) in-process mode.
     *
     * @return void
     */
    public function initLocal(): void
    {
        $this->sharedMode = false;
        $this->localData = [];
    }

    // ===================== attach / lifecycle =====================

    /**
     * Smallest segment-0 byte size that fits the fixed regions (header + directory
     * + order log) plus one minimum allocation, for a store of $slots slots with
     * an untagged (8B) or hrtime-tagged (16B) order log. Single source of truth for
     * the size floor, shared with Striped's per-stripe pre-check so its error can
     * name the exact minimum instead of failing cryptically inside a sub-store.
     *
     * @param int  $slots     Directory slot count.
     * @param bool $orderTag  Whether the order log uses hrtime tags.
     *
     * @return int Minimum segment byte size.
     */
    public static function minSegmentSize(int $slots, bool $orderTag): int
    {
        $orderBytes = $orderTag ? self::ORDER_TAGGED : self::ORDER;
        // attach() requires size >= this; the +1 turns the old "<= threshold" reject
        // into the smallest accepted value.
        return self::HEADER + $slots * (self::SLOT + $orderBytes) + self::ALLOC_MIN + 1;
    }

    /**
     * Refuse a 32-bit PHP build before touching the binary layout. Shared mode
     * indexes the directory with `unpack('P', hash) & mask` and stores 64-bit
     * fields/pointers/hrtime tags; on a 32-bit build those exceed the int range and
     * silently round-trip through float, corrupting the directory. A loud, cold
     * refusal at attach() is correct; the check is a single comparison, never on a
     * data op. Local (non-shared) mode never calls this.
     */
    private static function assertPlatformSupported(): void
    {
        self::assertIntSize(\PHP_INT_SIZE);
    }

    /**
     * Pure platform check, split out so the refusal logic is unit-testable without
     * an actual 32-bit interpreter (a 64-bit test reflects this with $intSize = 4).
     */
    private static function assertIntSize(int $intSize): void
    {
        if ($intSize !== 8) {
            throw new RuntimeException(
                'Fast shared mode requires a 64-bit PHP build (PHP_INT_SIZE === 8); '
                . 'this build is ' . ($intSize * 8) . '-bit'
            );
        }
    }

    /**
     * Attach this engine to a named shared store, adopting an existing segment's
     * geometry or creating a fresh one with the requested size/capacity. Reclaims
     * an orphaned non-persistent store and heals a crash-interrupted writer before
     * linking this process in. Switches the engine into shared mode.
     *
     * @param string $name       Store name (also seeds the segment key + semaphore).
     * @param int    $size       Segment-0 byte size (growth segments reuse it).
     * @param int    $slots      Directory capacity (power of two).
     * @param bool   $persistent Survive after the last connected process leaves.
     * @param bool   $orderTag   Use a 16-byte hrtime-tagged order log (Striped merge clock).
     *
     * @return void
     *
     * @throws RuntimeException On a 32-bit PHP build (shared mode needs 64-bit).
     * @throws InvalidArgumentException If $slots is not a power of two, or $size is below {@see minSegmentSize()}.
     * @throws LayoutException If the segment holds an incompatible layout version or name collision occurs.
     * @throws RuntimeException If the semaphore, segment, or link lock cannot be acquired.
     */
    public function attach(string $name, int $size, int $slots, bool $persistent, bool $orderTag = false): void
    {
        self::assertPlatformSupported();   // cold: refuse a 32-bit build before any layout I/O
        if (($slots & ($slots - 1)) !== 0 || $slots < 1) {
            throw new InvalidArgumentException('capacity must be a power of two');
        }
        // Provisional order-entry size from the request; an existing store overrides
        // it from its header in adoptGeometry() so peers agree on the geometry.
        $this->orderBytes = $orderTag ? self::ORDER_TAGGED : self::ORDER;
        if ($size < self::minSegmentSize($slots, $orderTag)) {
            throw new InvalidArgumentException('segment size too small for the requested capacity');
        }

        self::resolveDiag();   // cold: resolve the diagnostic sink at most once per process
        $this->sharedMode = true;
        $this->spin = self::tsoReads() ? self::SPIN : 0;  // 0 => skip the unfenced lock-free attempts, read under the lock (fence-safe)
        $this->name = $name;
        $this->key = self::segKey($name, 0);

        $this->sem = \sem_get(\crc32('fast-flat-sem:' . $name) & 0x7fffffff, 1, 0600, true);
        if ($this->sem === false) {
            throw new RuntimeException('unable to obtain shared Fast semaphore');
        }

        // Attach an existing store first with size 0 so we adopt its REAL geometry
        // (segment size + header slot count). Opening with 'c' and a fixed size
        // would fail when a peer created the segment with a different size, and a
        // requested capacity/size that disagrees with the live store would compute
        // the wrong slot mask and make peer data invisible.
        $seg = @\shmop_open($this->key, 'w', 0600, 0);
        $existing = false;
        if ($seg !== false && \shmop_read($seg, self::H_MAGIC, 4) === self::MAGIC) {
            $this->segs[0] = $seg;
            if ($this->ru32(self::H_LAYOUT) !== self::LAYOUT) {
                throw new LayoutException('incompatible shared Fast layout version');
            }
            // M3: the segment key is a 31-bit crc32 of the name, so a different name
            // can alias this segment. Verify the stored name fingerprint; a mismatch
            // is a key collision — refuse it rather than silently serve another
            // store's data. (The semaphore may be shared with the real owner via the
            // same collision, so we must NOT remove it here.)
            if (\shmop_read($this->segs[0], self::H_NAMEHASH, 16) !== $this->nameHash()) {
                throw new LayoutException('shared Fast "' . $this->name . '": segment key 0x'
                    . \dechex($this->key) . ' is already in use by a different store (crc32 name'
                    . ' collision) — choose a different store name');
            }
            $existing = true;
        }

        if ($existing) {
            $this->adoptGeometry();
            // crash debris: every recorded owner dead and not persistent -> reclaim
            if (!$this->boolHeaderPersistent() && $this->reclaimIfOrphaned()) {
                $existing = false; // fall through to fresh create with requested geometry
            } else {
                $this->recoverIfCrashed();
            }
        }

        if (!$existing) {
            $this->applyGeometry($size, $slots);
            $seg = @\shmop_open($this->key, 'c', 0600, $size);
            if ($seg === false) {
                throw new RuntimeException('unable to open shared Fast segment');
            }
            $this->segs[0] = $seg;
            $this->initFresh($persistent);
        }

        $this->persistent = $this->boolHeaderPersistent();
        $this->registerProcessLink();
    }

    private function applyGeometry(int $size, int $slots): void
    {
        $this->size = $size;
        $this->slotCount = $slots;
        $this->mask = $slots - 1;
        $this->payload = $size - self::HEADER;
        $this->arenaBase = $slots * (self::SLOT + $this->orderBytes);
    }

    /** Adopt the live store's geometry from segment 0 (size + header slot count). */
    private function adoptGeometry(): void
    {
        $size = \shmop_size($this->segs[0]);
        $slots = $this->ru32(self::H_SLOTS);
        if ($slots < 1 || ($slots & ($slots - 1)) !== 0) {
            throw new RuntimeException('shared Fast store has an invalid slot count');
        }
        // The order-entry size is part of the persisted geometry: a peer MUST adopt
        // the creator's choice or every order-log offset would be miscomputed.
        $os = $this->ru32(self::H_ORDERSZ);
        $this->orderBytes = $os === self::ORDER_TAGGED ? self::ORDER_TAGGED : self::ORDER;
        $this->applyGeometry($size, $slots);
    }

    /**
     * 16-byte identity fingerprint of the store name. The segment key is only a
     * 31-bit crc32 of the name, so two different names can alias the same segment
     * (M3). Stamping this fingerprint at create and verifying it at attach turns a
     * silent wrong-store bind into a clear, refused collision.
     */
    private function nameHash(): string
    {
        return \hash('xxh128', (string) $this->name, true);
    }

    private function initFresh(bool $persistent): void
    {
        $this->lock();
        try {
            // zero the header + fixed regions of segment 0
            \shmop_write($this->segs[0], \str_repeat("\0", self::HEADER), 0);
            $this->wrZero(0, $this->arenaBase); // directory + order log
            \shmop_write($this->segs[0], self::MAGIC, self::H_MAGIC);
            $this->wu32(self::H_LAYOUT, self::LAYOUT);
            \shmop_write($this->segs[0], $this->nameHash(), self::H_NAMEHASH);
            $this->wu32(self::H_SEQ, 0);
            $this->wu32(self::H_LIVE, 0);
            $this->wu32(self::H_TOMB, 0);
            $this->wu32(self::H_ORDER, 0);
            $this->wu64(self::H_FRONTIER, $this->arenaBase);
            $this->wu64(self::H_LIVECAPS, 0);
            $this->wu32(self::H_ORDERSZ, $this->orderBytes);
            $this->wu32(self::H_SLOTS, $this->slotCount);
            $this->wu32(self::H_PERSIST, $persistent ? 1 : 0);
        } finally {
            $this->unlock(true);
        }
        $this->persistent = $persistent;
    }

    private function boolHeaderPersistent(): bool
    {
        return $this->ru32(self::H_PERSIST) === 1;
    }

    /**
     * Derive the SysV shmop key for a store segment index.
     *
     * @param string $name  Store name.
     * @param int    $index Zero-based segment index.
     *
     * @return int Positive 31-bit segment key.
     */
    public static function segKey(string $name, int $index): int
    {
        return \crc32('fast-flat:' . $name . ':' . $index) & 0x7fffffff;
    }

    private function ensureSeg(int $index, bool $create)
    {
        if (isset($this->segs[$index])) {
            return $this->segs[$index];
        }
        if ($index >= self::MAX_SEGMENTS) {
            throw new RuntimeException('shared Fast exceeded max segments');
        }
        $k = self::segKey($this->name, $index);
        // Always open an existing segment read-WRITE: a handle cached by a read
        // (rdSpan) may later be reused by a write (wrSpan), so a read-only handle
        // would fatally fail the write. Only create-with-size when a writer asks
        // for a segment that does not exist yet.
        $seg = @\shmop_open($k, 'w', 0600, 0);
        if ($seg === false && $create) {
            $seg = @\shmop_open($k, 'c', 0600, $this->size);
        }
        if ($seg === false) {
            return null;
        }
        $this->segs[$index] = $seg;
        return $seg;
    }

    // ---- lifecycle / link tracking (flock, crash-tolerant) ----
    //
    // Each live connection holds LOCK_SH on a per-store lock file for its whole
    // lifetime. The kernel drops that lock the instant the holder dies — clean
    // exit, exception, or SIGKILL alike — so there is no PID table to sweep, no
    // PID-reuse phantom, and no fixed connection ceiling. "Am I the last one out?"
    // is a non-blocking exclusive probe on a fresh descriptor: it succeeds only
    // when no other description (any process) still holds the shared lock. Reclaim
    // runs under the write semaphore so the release+probe+delete is serialized and
    // two departing processes never both reclaim. None of this is on the hot path —
    // it is touched only at attach / close / destroy. The process's own LOCK_SH
    // handle lives in a static keyed by [pid][key] (mirrors $localLinks), so it is
    // shared across multiple local instances and is fork-safe.

    private function lockFilePath(): string
    {
        if (self::$lockDir === null) {
            self::$lockDir = (\is_dir('/dev/shm') && \is_writable('/dev/shm'))
                ? '/dev/shm'
                : \sys_get_temp_dir();
        }
        return self::$lockDir . '/fast-flat-' . $this->key . '.lock';
    }

    /**
     * True when NO other connection holds the store: open a fresh description and
     * try a non-blocking exclusive lock. The caller must have already released its
     * own LOCK_SH (else its own shared lock would block the probe). Must hold the
     * write semaphore so the answer cannot change underneath a reclaim decision.
     * On any I/O failure it returns false (assume referenced — never reclaim blind).
     */
    private function storeUnreferenced(): bool
    {
        $fp = @\fopen($this->lockFilePath(), 'c');
        if ($fp === false) { return false; }
        $free = \flock($fp, \LOCK_EX | \LOCK_NB);
        if ($free) { \flock($fp, \LOCK_UN); }
        \fclose($fp);
        return $free;
    }

    private function registerProcessLink(): void
    {
        $pid = \getmypid();
        $this->ownerPid = $pid;
        $local = self::$localLinks[$pid][$this->key] ?? 0;
        self::$localLinks[$pid][$this->key] = $local + 1;
        if ($local > 0) { return; }            // already linked in this process

        $fp = @\fopen($this->lockFilePath(), 'c');
        if ($fp === false || !\flock($fp, \LOCK_SH)) {
            if ($fp !== false) { \fclose($fp); }
            unset(self::$localLinks[$pid][$this->key]);
            throw new RuntimeException('shared Fast "' . $this->name . '": unable to acquire link lock file');
        }
        self::$linkFps[$pid][$this->key] = $fp;   // held for the connection's lifetime
    }

    /**
     * Release this process flock link and optionally reclaim a non-persistent store.
     *
     * @return void
     */
    public function releaseProcessLink(): void
    {
        $pid = \getmypid();
        if ($this->ownerPid !== $pid) { return; }
        $local = self::$localLinks[$pid][$this->key] ?? 0;
        if ($local <= 0) { return; }
        if (--$local > 0) { self::$localLinks[$pid][$this->key] = $local; return; }
        unset(self::$localLinks[$pid][$this->key]);

        $reclaim = false;
        $this->lock();
        try {
            // Release our shared lock INSIDE the critical section, then probe: any
            // peer still attached blocks the exclusive probe, so only the genuine
            // last-one-out sees the store unreferenced and reclaims it.
            $this->releaseLinkFp($pid);
            if (!$this->persistent && $this->storeUnreferenced()) {
                $this->deleteSegments();
                @\unlink($this->lockFilePath());
                $reclaim = true;
            }
        } finally {
            $this->unlock(true);
        }
        if ($reclaim) { $this->removeSem(); }
    }

    private function releaseLinkFp(int $pid): void
    {
        $fp = self::$linkFps[$pid][$this->key] ?? null;
        if ($fp !== null) {
            \flock($fp, \LOCK_UN);
            \fclose($fp);
            unset(self::$linkFps[$pid][$this->key]);
        }
    }

    /**
     * If an existing non-persistent store has no live connection (every prior
     * owner died), delete its segments and report it orphaned so the caller
     * (attach) recreates a fresh store with the requested geometry. Runs under the
     * write semaphore, before this process takes its own shared lock.
     */
    private function reclaimIfOrphaned(): bool
    {
        $orphaned = false;
        $this->lock();
        try {
            if ($this->storeUnreferenced()) {
                $this->deleteSegments();
                @\unlink($this->lockFilePath());
                $orphaned = true;
            }
        } finally {
            $this->unlock(true);
        }
        return $orphaned;
    }

    /**
     * Heal a store whose writer was killed inside its critical section. Such a
     * writer set the seqlock odd but died before clearing it; a clean store is
     * always even. So the gate is a single 4-byte read: even -> nothing to do
     * (the universal case). Only an actual mid-write kill pays for the lock and
     * the repair scan below. Runs once, at attach, before this process links in.
     */
    private function recoverIfCrashed(): void
    {
        if ((\ord($this->rseq()[0]) & 1) === 0) { return; }
        $this->lock();
        try {
            $seq = $this->ru32(self::H_SEQ);
            if (($seq & 1) === 0) { return; }       // a peer attacher already healed it
            $this->repairAfterCrash();
            $this->wu32(self::H_SEQ, $seq + 1);      // close the window: odd -> even
        } finally {
            $this->unlock(true);
        }
    }

    /**
     * Rebuild the header counters and order log from the authoritative slot
     * table, quarantining any slot whose stored key hash no longer validates (a
     * 32-byte slot write torn by the kill). A torn *value* from an in-place
     * overwrite is undetectable here (no value checksum is stored) and stays a
     * documented bounded window. Must hold the lock.
     */
    private function repairAfterCrash(): void
    {
        $orderBase = $this->slotCount * self::SLOT;
        $ob = $this->orderBytes;
        $frontier = $this->ru64(self::H_FRONTIER);

        $oc = $this->ru32(self::H_ORDER);
        $covered = [];
        for ($r = 0; $r < $oc; $r++) {
            $e = \unpack('Vsi/Vgen', $this->rd($orderBase + $r * $ob, 8));
            $covered[$e['si']] = $e['gen'];
        }

        $live = 0; $tomb = 0; $caps = 0; $missing = [];
        for ($si = 0; $si < $this->slotCount; $si++) {
            $slot = $this->rd($si * self::SLOT, self::SLOT);
            $state = \ord($slot[22]);
            if ($state === self::ST_EMPTY) { continue; }
            if ($state === self::ST_TOMB)  { $tomb++; continue; }

            $f = \unpack('VrecOff/Vgen/Vvallen/vkeylen', $slot, 8);
            $total = $f['keylen'] + $f['vallen'];
            $ok = $f['recOff'] >= $this->arenaBase && $f['recOff'] + $total <= $frontier;
            if ($ok) {
                $kb = $f['keylen'] > 0 ? $this->rd($f['recOff'], $f['keylen']) : '';
                $h = \hash('xxh128', ((\ord($slot[23]) >> 4) === 0 ? "\0" : "\1") . $kb, true);
                $ok = \substr($h, 0, 8) === \substr($slot, 0, 8)
                   && \substr($h, 8, 8) === \substr($slot, 24, 8);
            }
            if (!$ok) {                                  // torn slot -> quarantine
                $this->wr($si * self::SLOT + 22, \chr(self::ST_TOMB));
                $tomb++;
                continue;
            }
            $live++;
            $caps += $this->classFor($total)[1];
            if (($covered[$si] ?? -1) !== $f['gen']) { $missing[] = [$si, $f['gen']]; }
        }

        foreach ($missing as [$si, $gen]) {              // insert killed after the slot write
            if ($oc >= $this->slotCount) { $this->compactOrder(); $oc = $this->ru32(self::H_ORDER); }
            $this->wr($orderBase + $oc * $ob, $ob === self::ORDER_TAGGED ? \pack('VVP', $si, $gen, \hrtime(true)) : \pack('VV', $si, $gen));
            $this->wu32(self::H_ORDER, ++$oc);
        }

        $this->wu32(self::H_LIVE, $live);
        $this->wu32(self::H_TOMB, $tomb);
        $this->wu64(self::H_LIVECAPS, $caps);
    }

    private function removeSem(): void
    {
        if ($this->sem !== null) {
            if (!@\sem_remove($this->sem)) { $this->diag('sem_remove_failed', []); }
            $this->sem = null;
        }
    }

    // ===================== diagnostics (M6, opt-in, cold paths only) =====================

    /**
     * Install (or clear) a diagnostic sink that receives otherwise-silent failures
     * as ($event, $context). Engine-level and entirely optional — it does NOT touch
     * the Fast public surface, and when no sink is installed the cost is a single
     * null check on already-cold failure branches (the hot read/write path is
     * never instrumented). An explicit call wins over FAST_DIAG.
     *
     * @param (callable(string, array): void)|null $sink Diagnostic callback or null to clear.
     *
     * @return void
     */
    public static function setDiagnostics(?callable $sink): void
    {
        self::$diag = $sink;
        self::$diagResolved = true;
    }

    /**
     * Cheap gate so a cold-path caller can skip building a diagnostic payload when
     * no sink is installed. Engine-internal; not part of the Fast public surface.
     *
     * @return bool
     */
    public static function diagnosticsActive(): bool
    {
        return self::$diag !== null;
    }

    /**
     * Emit a structured diagnostic through the shared sink on behalf of a
     * coordinator (e.g. Striped) that owns no single store name of its own. Cold /
     * maintenance paths only — never reachable from a data op. Caller supplies the
     * full context (including its own 'store' label).
     *
     * @param string               $event Event name.
     * @param array<string, mixed> $ctx   Structured context payload.
     *
     * @return void
     */
    public static function emitDiag(string $event, array $ctx): void
    {
        if (self::$diag !== null) { (self::$diag)($event, $ctx); }
    }

    /**
     * Resolve the sink once per process: an explicit setDiagnostics() wins,
     * otherwise FAST_DIAG=stderr|errorlog auto-installs a one-line JSON sink.
     * Called once from attach() (cold) — never on a data op, so getenv is read at
     * most once per process.
     */
    private static function resolveDiag(): void
    {
        if (self::$diagResolved) { return; }
        self::$diagResolved = true;
        $mode = \getenv('FAST_DIAG');
        if ($mode === false || $mode === '' || $mode === '0') { return; }
        self::$diag = static function (string $event, array $ctx) use ($mode): void {
            $line = 'Fast ' . $event . ' ' . \json_encode($ctx, \JSON_UNESCAPED_SLASHES);
            if ($mode === 'stderr') { \fwrite(\STDERR, $line . \PHP_EOL); }
            else { \error_log($line); }
        };
    }

    /** Emit a structured diagnostic if a sink is installed. Failure/cold paths only. */
    private function diag(string $event, array $ctx = []): void
    {
        if (self::$diag === null) { return; }
        $ctx['store'] = $this->name;
        (self::$diag)($event, $ctx);
    }

    // ===================== lifecycle: close / destroy =====================

    /**
     * {@inheritDoc}
     *
     * @return void
     */
    #[\Override]
    public function close(): void
    {
        if (!$this->sharedMode) { return; }
        $this->releaseProcessLink();
        foreach ($this->segs as $seg) { @\shmop_close($seg); }
        $this->segs = [];
        $this->sem = null;
        $this->sharedMode = false;
        $this->lockDepth = 0;
    }

    /**
     * True when this process is the ONLY connection to the store. Drops our shared
     * link lock, probes exclusively, then restores it. Cold path — used by Striped
     * to verify every stripe is destroyable BEFORE deleting any (atomic destroy).
     *
     * @return bool
     */
    public function isSoleConnection(): bool
    {
        if (!$this->sharedMode) { return true; }
        $pid = \getmypid();
        $this->lock();
        try {
            $fp = self::$linkFps[$pid][$this->key] ?? null;
            if ($fp !== null) { \flock($fp, \LOCK_UN); }
            $sole = $this->storeUnreferenced();
            if ($fp !== null) { \flock($fp, \LOCK_SH); }
            return $sole;
        } finally {
            $this->unlock(true);
        }
    }

    /**
     * Delete the store. By default this refuses unless we are the sole connection.
     * $force skips that gate — used only by Striped's commit phase AFTER it has
     * already verified every stripe is solely owned, so a cross-stripe destroy
     * cannot throw part-way and leave a half-torn store.
     *
     * @param bool $force Skip sole-owner check (Striped commit phase only).
     *
     * @return void
     *
     * @throws RuntimeException when another process is connected and $force is false.
     */
    #[\Override]
    public function destroy(bool $force = false): void
    {
        if (!$this->sharedMode) { $this->localData = []; return; }
        $pid = \getmypid();
        $destroyed = false;
        $this->lock();
        try {
            $fp = self::$linkFps[$pid][$this->key] ?? null;
            if (!$force) {
                // Drop our own shared lock so the probe reflects only OTHER
                // processes; refuse if any peer is still attached, then restore.
                if ($fp !== null) { \flock($fp, \LOCK_UN); }
                if (!$this->storeUnreferenced()) {
                    if ($fp !== null) { \flock($fp, \LOCK_SH); }
                    throw new RuntimeException('cannot destroy shared Fast "' . $this->name . '": another process is still connected');
                }
            }
            $this->releaseLinkFp($pid);
            $this->deleteSegments();
            @\unlink($this->lockFilePath());
            $destroyed = true;
        } finally {
            $this->unlock(true);
        }
        if ($destroyed) {
            $this->removeSem();
            unset(self::$localLinks[$pid][$this->key]);
            $this->sharedMode = false;
        }
    }

    private function deleteSegments(): void
    {
        // walk all segments (this store may not hold handles to growth segments)
        for ($i = 0; $i < self::MAX_SEGMENTS; $i++) {
            $seg = $this->segs[$i] ?? @\shmop_open(self::segKey($this->name, $i), 'a', 0600, 0);
            if ($seg === false) {
                if (!isset($this->segs[$i])) { break; }
                continue;
            }
            if (!@\shmop_delete($seg)) { $this->diag('segment_delete_failed', ['index' => $i]); }
            unset($this->segs[$i]);
        }
        $this->segs = [];
    }

    // ===================== lock / seqlock =====================

    /**
     * {@inheritDoc}
     *
     * @return void
     */
    #[\Override]
    public function lock(): void
    {
        if ($this->lockDepth++ === 0 && $this->sem !== null) {
            if (!@\sem_acquire($this->sem)) {
                throw new RuntimeException('unable to acquire shared Fast semaphore');
            }
        }
    }

    /**
     * {@inheritDoc}
     *
     * @param bool $silent When true, suppress release errors on imbalance.
     *
     * @return void
     */
    #[\Override]
    public function unlock(bool $silent = false): void
    {
        if ($this->lockDepth <= 0) { return; }
        if (--$this->lockDepth === 0 && $this->sem !== null) {
            @\sem_release($this->sem);
        }
    }

    // ===================== header field io =====================

    private function ru32(int $o): int { return \unpack('V', \shmop_read($this->segs[0], $o, 4))[1]; }
    private function wu32(int $o, int $v): void { \shmop_write($this->segs[0], \pack('V', $v), $o); }

    /**
     * Raw 4-byte read of the seqlock counter (SC-11). Readers compare the raw
     * little-endian bytes (===) and test odd-ness off the low byte, skipping two
     * unpack('V') calls per read — the seqlock value itself is never needed, only
     * its stability across the probe.
     */
    private function rseq(): string { return \shmop_read($this->segs[0], self::H_SEQ, 4); }
    private function ru64(int $o): int { return \unpack('P', \shmop_read($this->segs[0], $o, 8))[1]; }
    private function wu64(int $o, int $v): void { \shmop_write($this->segs[0], \pack('P', $v), $o); }

    // ===================== region (directory/order/arena) io =====================

    private function rd(int $regionOff, int $len): string
    {
        if ($regionOff + $len <= $this->payload) {
            $r = \shmop_read($this->segs[0], self::HEADER + $regionOff, $len);
            if ($r === false) { throw new RuntimeException('region read failed'); }
            return $r;
        }
        return $this->rdSpan($regionOff, $len);
    }

    private function rdSpan(int $regionOff, int $len): string
    {
        $out = '';
        $seg = \intdiv($regionOff, $this->payload);
        $inner = $regionOff % $this->payload;
        $rem = $len;
        while ($rem > 0) {
            $h = $this->ensureSeg($seg, false);
            if ($h === null) { throw new RuntimeException('region read: missing segment ' . $seg); }
            $chunk = \min($rem, $this->payload - $inner);
            $phys = $seg === 0 ? self::HEADER + $inner : $inner;
            $part = \shmop_read($h, $phys, $chunk);
            if ($part === false) { throw new RuntimeException('region read failed'); }
            $out .= $part;
            $rem -= $chunk;
            $seg++;
            $inner = 0;
        }
        return $out;
    }

    private function wr(int $regionOff, string $bytes): void
    {
        $len = \strlen($bytes);
        if ($regionOff + $len <= $this->payload) {
            \shmop_write($this->segs[0], $bytes, self::HEADER + $regionOff);
            return;
        }
        $this->wrSpan($regionOff, $bytes);
    }

    private function wrSpan(int $regionOff, string $bytes): void
    {
        $len = \strlen($bytes);
        $seg = \intdiv($regionOff, $this->payload);
        $inner = $regionOff % $this->payload;
        $written = 0;
        while ($written < $len) {
            $h = $this->ensureSeg($seg, true);
            if ($h === null) { throw new RuntimeException('region write: missing segment ' . $seg); }
            $chunk = \min($len - $written, $this->payload - $inner);
            $phys = $seg === 0 ? self::HEADER + $inner : $inner;
            \shmop_write($h, \substr($bytes, $written, $chunk), $phys);
            $written += $chunk;
            $seg++;
            $inner = 0;
        }
    }

    private function wrZero(int $regionOff, int $len): void
    {
        // chunked zero-fill so a large directory does not build one giant string
        $chunk = 1 << 20;
        $z = \str_repeat("\0", \min($len, $chunk));
        $done = 0;
        while ($done < $len) {
            $n = \min($chunk, $len - $done);
            $this->wr($regionOff + $done, $n === \strlen($z) ? $z : \substr($z, 0, $n));
            $done += $n;
        }
    }

    // ===================== normalize / encode =====================

    /**
     * @return array{0:int,1:string,2:string,3:string} [keyType, keyBytes, hb, hb2]
     *
     * One xxh128 call yields 128 independent bits (SC-8): the low 8 bytes (hb) are
     * the directory probe + slot tag (same role the old 64-bit xxh3 hash played);
     * the high 8 bytes (hb2) are a SECOND independent confirm tag stored in the
     * slot. A hit requires BOTH to match, so two distinct keys would have to
     * collide on 128 bits (~2^-128, never) — which makes the per-hit key re-read
     * provably unnecessary. xxh128 ~ xxh3 in speed, so this also subsumes the
     * old key-prefix hash (SC-6).
     */
    private function norm(int|string $k): array
    {
        if (\is_int($k)) { $kb = \pack('q', $k); $h = \hash('xxh128', "\0" . $kb, true); return [0, $kb, \substr($h, 0, 8), \substr($h, 8, 8)]; }
        $h = \hash('xxh128', "\1" . $k, true);
        return [1, $k, \substr($h, 0, 8), \substr($h, 8, 8)];
    }

    /** @return array{0:int,1:string} [valType, valBytes] */
    private function enc(mixed $v): array
    {
        if ($v === null) { return [self::TYPE_NULL, '']; }
        if (\is_bool($v)) { return [self::TYPE_BOOL, $v ? "\1" : "\0"]; }
        if (\is_int($v)) { return [self::TYPE_INT, \pack('q', $v)]; }
        if (\is_float($v)) { return [self::TYPE_FLOAT, \pack('e', $v)]; }
        if (\is_string($v)) { return [self::TYPE_STRING, $v]; }
        return [self::TYPE_IGBINARY, \igbinary_serialize($v)];
    }

    private function dec(int $type, string $b): mixed
    {
        switch ($type) {
            case self::TYPE_NULL: return null;
            case self::TYPE_BOOL: return $b !== "\0";
            case self::TYPE_INT: return \unpack('q', $b)[1];
            case self::TYPE_FLOAT: return \unpack('e', $b)[1];
            case self::TYPE_STRING: return $b;
            default: return \igbinary_unserialize($b);
        }
    }

    // ===================== allocator (size-class free list) =====================

    /** @return array{0:int,1:int} [classIndex, cap] */
    private function classFor(int $need): array
    {
        $cap = self::ALLOC_MIN; $ci = 0;
        while ($cap < $need) { $cap <<= 1; $ci++; }
        return [$ci, $cap];
    }

    /** @return array{0:int,1:int} [arenaRegionOffset, cap] (must hold the lock) */
    private function alloc(int $need): array
    {
        [$ci, $cap] = $this->classFor($need);
        $head = $this->ru64(self::H_FREEHEADS + $ci * 8);
        if ($head !== 0) {
            $next = \unpack('P', $this->rd($head, 8))[1];
            $this->wu64(self::H_FREEHEADS + $ci * 8, $next);
            return [$head, $cap];
        }
        $f = $this->ru64(self::H_FRONTIER);
        $seg = \intdiv($f, $this->payload);
        // never let a block straddle a segment boundary (keeps single-segment reads cheap)
        $segEnd = ($seg + 1) * $this->payload;
        if ($f + $cap > $segEnd) { $f = $segEnd; $seg++; }
        // Preflight a GROWTH segment BEFORE advancing the frontier or bumping any
        // accounting: an out-of-shared-memory failure must be atomic (no frontier
        // / LIVECAPS drift) and reported clearly — not surface as a cryptic
        // "missing segment N" thrown mid-write after the books moved. Segment 0
        // always exists (attach created it), so single-segment allocs — the hot
        // path — skip this entirely and pay nothing.
        if ($seg !== 0 && $this->ensureSeg($seg, true) === null) {
            throw new ShmExhaustedException(
                'shared Fast "' . $this->name . '": out of shared memory (growth segment '
                . $seg . ' @ ' . $this->size . ' bytes could not be created). Raise the host '
                . 'shared-memory limit (e.g. docker --shm-size) or lower the configured store size.'
            );
        }
        $this->wu64(self::H_FRONTIER, $f + $cap);
        return [$f, $cap];
    }

    private function freeBlock(int $off, int $cap): void
    {
        $ci = 0; $c = self::ALLOC_MIN; while ($c < $cap) { $c <<= 1; $ci++; }
        $this->wr($off, \pack('P', $this->ru64(self::H_FREEHEADS + $ci * 8)));
        $this->wu64(self::H_FREEHEADS + $ci * 8, $off);
    }

    // ===================== relocating compactor (reclaims RAM) =====================

    /**
     * Force a relocating compaction now (test/maintenance entry point).
     *
     * {@inheritDoc}
     *
     * @return void
     */
    #[\Override]
    public function compact(): void
    {
        if (!$this->sharedMode) { return; }
        $this->lock();
        try { $this->compactArena(); }
        finally { $this->unlock(true); }
    }

    /**
     * Decide whether the value arena is wasteful enough to relocate-compact. The
     * size-class free list reuses freed blocks of the SAME class in O(1), but it
     * cannot reuse them across classes, so under a drifting value-size workload the
     * frontier marches forward while live bytes stay flat. Without FFI we have no
     * madvise: the only way to return RAM to the OS is to delete whole trailing
     * segments, which a scattered free list pins forever. So when the arena has
     * spilled past segment 0 AND holds at least ~2x the live capacity, we repack.
     * Must hold the lock; the caller's seqlock window must already be closed.
     */
    private function maybeCompact(): void
    {
        // Hot path is a single local int compare with NO shared-memory access:
        // same-size overwrites and no-op writes add nothing to dirtyBytes, and even
        // allocation-changing writes only sample the real threshold once per
        // segment-worth of churn (alloc + free bytes). Gating on churned BYTES (not
        // op count) means a burst of large writes/deletes is checked promptly while
        // a long run of tiny ops stays cheap — and it catches delete-driven waste,
        // which an op counter that resets mid-burst would miss.
        if ($this->dirtyBytes < $this->payload) { return; }
        $this->dirtyBytes = 0;

        // Single-segment stores (the common, already-bounded case) short-circuit on
        // one frontier read — no trailing segment exists to reclaim. Only a store
        // that has spilled past segment 0 reads live capacity and considers a
        // relocate, and only when at least half the arena is waste (2x amortisation
        // keeps compaction rare relative to growth, so its O(live) cost is repaid
        // over many writes).
        $frontier = $this->ru64(self::H_FRONTIER);
        if ($frontier <= $this->payload) { return; }
        $arenaUsed = $frontier - $this->arenaBase;
        $liveCaps = $this->ru64(self::H_LIVECAPS);
        if ($arenaUsed < 2 * \max($liveCaps, self::ALLOC_MIN)) { return; }
        $this->compactArena();
    }

    /**
     * Relocate every live record densely from arenaBase, patching slot recOffs and
     * rebuilding the order log, then reset the frontier, clear the free lists, and
     * physically delete the now-empty trailing segments when we are the sole owner.
     *
     * Live records are buffered on the PHP heap first (bounded by the live byte set,
     * which is precisely what compaction keeps small) so the dense rewrite can never
     * overwrite a not-yet-relocated record. The whole rewrite runs inside one seqlock
     * window: lock-free readers whose cached recOff predates the move see the sequence
     * change and retry (or fall back to a locked read), so no torn value escapes.
     * Must hold the lock.
     */
    private function compactArena(): void
    {
        $orderBase = $this->slotCount * self::SLOT;
        $seq = $this->ru32(self::H_SEQ);
        $this->wu32(self::H_SEQ, $seq + 1);
        try {
            $oc = $this->ru32(self::H_ORDER);
            $ob = $this->orderBytes;
            $tagged = $ob === self::ORDER_TAGGED;
            // Phase 1 — read all live records (in insertion order) onto the heap.
            $live = [];
            for ($r = 0; $r < $oc; $r++) {
                $e = \unpack($tagged ? 'Vsi/Vgen/Ptag' : 'Vsi/Vgen', $this->rd($orderBase + $r * $ob, $ob));
                $slot = $this->rd($e['si'] * self::SLOT, self::SLOT);
                if (\ord($slot[22]) !== self::ST_LIVE) { continue; }
                $f = \unpack('VrecOff/Vgen/Vvallen/vkeylen/Cstate/Ctypes', $slot, 8);
                if ($f['gen'] !== $e['gen']) { continue; }
                $total = $f['keylen'] + $f['vallen'];
                $live[] = [$e['si'], $e['gen'], $this->classFor($total)[1], $this->rd($f['recOff'], $total), $tagged ? $e['tag'] : 0];
            }
            // Phase 2 — dense rewrite from arenaBase; patch slot recOff + order log.
            $cursor = $this->arenaBase;
            $w = 0;
            $liveCaps = 0;
            foreach ($live as [$si, $gen, $cap, $bytes, $tag]) {
                $segEnd = (\intdiv($cursor, $this->payload) + 1) * $this->payload;
                if ($cursor + $cap > $segEnd) { $cursor = $segEnd; }   // keep blocks within one segment
                $this->wr($cursor, $bytes);
                $this->wr($si * self::SLOT + 8, \pack('V', $cursor));  // patch recOff
                $this->wr($orderBase + $w * $ob, $tagged ? \pack('VVP', $si, $gen, $tag) : \pack('VV', $si, $gen));
                $w++;
                $cursor += $cap;
                $liveCaps += $cap;
            }
            $this->wu32(self::H_ORDER, $w);
            $this->wu64(self::H_FRONTIER, $cursor);
            $this->wu64(self::H_LIVECAPS, $liveCaps);
            // The arena is now hole-free: every free-list head is stale.
            \shmop_write($this->segs[0], \str_repeat("\0", self::FREE_CLASSES * 8), self::H_FREEHEADS);
        } finally {
            $this->wu32(self::H_SEQ, $seq + 2);
        }
        $this->reclaimTrailingSegments();
    }

    /**
     * Delete arena segments above the (now-receded) frontier and hand the RAM back
     * to the OS. Only safe when we are the SOLE attached process: shmop_delete merely
     * marks a SysV segment for removal, and without FFI we cannot read shm_nattch to
     * prove no peer still maps it. When shared, compaction has still capped frontier
     * growth (so RSS is bounded at the high-water instead of unbounded); the physical
     * release simply waits for a moment of sole ownership. Must hold the lock.
     */
    private function reclaimTrailingSegments(): void
    {
        if (!$this->segs) { return; }
        // Sole-ownership test: drop our shared link lock, probe exclusively, restore
        // it. shmop_delete on a segment a peer still maps is unsafe (no shm_nattch
        // without FFI), so only the sole attached process may physically release.
        $pid = \getmypid();
        $fp = self::$linkFps[$pid][$this->key] ?? null;
        if ($fp === null) { return; }
        \flock($fp, \LOCK_UN);
        $sole = $this->storeUnreferenced();
        \flock($fp, \LOCK_SH);
        if (!$sole) { return; }
        $frontier = $this->ru64(self::H_FRONTIER);
        $lastSeg = $frontier <= $this->payload ? 0 : \intdiv($frontier - 1, $this->payload);
        $top = \max(\array_keys($this->segs));
        for ($i = $top; $i > $lastSeg; $i--) {
            $h = $this->segs[$i] ?? null;
            if ($h === null) { continue; }
            if (!@\shmop_delete($h)) { $this->diag('segment_delete_failed', ['index' => $i]); }
            unset($this->segs[$i]);
        }
    }

    // ===================== reads =====================

    /**
     * {@inheritDoc}
     *
     * @param int|string $key   Key to read.
     * @param mixed      $value Receives the decoded value on hit.
     *
     * @return bool
     */
    #[\Override]
    public function get(int|string $key, mixed &$value): bool
    {
        if (!$this->sharedMode) {
            $sk = $this->scratch($key);
            if (isset($this->localData[$sk])) { $value = $this->localData[$sk][1]; return true; }
            return false;
        }
        [, $kb, $hb, $hb2] = $this->norm($key);
        $kl = \strlen($kb);
        $base = \unpack('P', $hb)[1] & $this->mask;

        for ($spin = 0; $spin < $this->spin; $spin++) {
            $s1 = $this->rseq();
            if (\ord($s1[0]) & 1) { continue; }
            $hit = false; $val = null; $torn = false;
            try {
                $hit = $this->probe($base, $kl, $hb, $hb2, true, $val, $vt);
            } catch (\Throwable) { $torn = true; }
            if (!$torn && $s1 === $this->rseq()) { $value = $val; return $hit; }
        }
        $this->lock();
        try { return $this->probe($base, $kl, $hb, $hb2, true, $value, $vt); }
        finally { $this->unlock(true); }
    }

    /**
     * @param mixed $value receives decoded value when $need and hit
     * @param int|null $vtype receives value type on hit
     */
    private function probe(int $base, int $kl, string $hb, string $hb2, bool $need, mixed &$value, ?int &$vtype = null): bool
    {
        for ($i = 0; $i < $this->slotCount; $i++) {
            $slot = $this->rd((($base + $i) & $this->mask) * self::SLOT, self::SLOT);
            $state = \ord($slot[22]);
            if ($state === self::ST_EMPTY) { return false; }
            // Dual-tag identity (SC-8): hb @0 AND hb2 @24 — 2^-128 collision-proof,
            // so the cold-arena key re-read is unnecessary. The value lives at
            // recOff + keylen; the caller's $kl IS the key length (the tags prove
            // the keys are identical, so keylen matches too).
            if ($state === self::ST_LIVE && \substr($slot, 0, 8) === $hb && \substr($slot, 24, 8) === $hb2) {
                $f = \unpack('VrecOff/Vgen/Vvallen', $slot, 8);
                $vtype = \ord($slot[23]) & 0xF;
                if ($need) {
                    // vallen 0 (empty-string / null value) must NOT issue a 0-length
                    // shmop_read — that reads the whole segment; the bytes are empty.
                    $vb = $f['vallen'] > 0 ? $this->rd($f['recOff'] + $kl, $f['vallen']) : '';
                    $value = $this->dec($vtype, $vb);
                }
                return true;
            }
        }
        return false;
    }

    /**
     * {@inheritDoc}
     *
     * Existence uses PHP {@see isset()} semantics (stored null is absent).
     *
     * @param int|string $key   Key to probe.
     * @param int|null   $vtype Receives stored type id on hit when provided.
     *
     * @return bool
     */
    #[\Override]
    public function has(int|string $key, ?int &$vtype = null): bool
    {
        $vtype = null;
        if (!$this->sharedMode) {
            $sk = $this->scratch($key);
            if (!isset($this->localData[$sk])) { return false; }
            [, $v] = $this->localData[$sk];
            $vtype = $v === null ? self::TYPE_NULL : 1;
            return true;
        }
        [, $kb, $hb, $hb2] = $this->norm($key);
        $kl = \strlen($kb);
        $base = \unpack('P', $hb)[1] & $this->mask;
        for ($spin = 0; $spin < $this->spin; $spin++) {
            $s1 = $this->rseq();
            if (\ord($s1[0]) & 1) { continue; }
            $hit = false; $torn = false; $vt = null; $ignored = null;
            try { $hit = $this->probe($base, $kl, $hb, $hb2, false, $ignored, $vt); }
            catch (\Throwable) { $torn = true; }
            if (!$torn && $s1 === $this->rseq()) { $vtype = $vt; return $hit; }
        }
        $this->lock();
        try { $ignored = null; $r = $this->probe($base, $kl, $hb, $hb2, false, $ignored, $vtype); return $r; }
        finally { $this->unlock(true); }
    }

    /**
     * {@inheritDoc}
     *
     * @return int
     */
    #[\Override]
    public function count(): int
    {
        if (!$this->sharedMode) { return \count($this->localData); }
        return $this->ru32(self::H_LIVE);
    }

    // ===================== writes =====================

    /**
     * {@inheritDoc}
     *
     * @param int|string $key   Key to write.
     * @param mixed      $value Value to store.
     *
     * @return void
     *
     * @throws InvalidArgumentException when the directory is full.
     */
    #[\Override]
    public function set(int|string $key, mixed $value): void
    {
        if (!$this->sharedMode) { $this->localData[$this->scratch($key)] = [$key, $value]; return; }
        [$kt, $kb, $hb, $hb2] = $this->norm($key);
        $kl = \strlen($kb);
        [$vt, $vb] = $this->enc($value);
        $vl = \strlen($vb);
        $types = ($kt << 4) | $vt;
        $base = \unpack('P', $hb)[1] & $this->mask;

        $this->lock();
        try {
            $done = false;
            $seq = $this->ru32(self::H_SEQ);
            $this->wu32(self::H_SEQ, $seq + 1);
            try {
                $insertSlot = -1;
                for ($i = 0; $i < $this->slotCount; $i++) {
                    $si = ($base + $i) & $this->mask;
                    $slot = $this->rd($si * self::SLOT, self::SLOT);
                    $state = \ord($slot[22]);
                    if ($state === self::ST_EMPTY) {
                        if ($insertSlot < 0) { $insertSlot = $si; }
                        $this->doInsert($insertSlot, $hb, $hb2, $kb, $kl, $vb, $vl, $types);
                        $done = true;
                        break;
                    }
                    if ($state === self::ST_TOMB) { if ($insertSlot < 0) { $insertSlot = $si; } continue; }
                    // Dual-tag identity (SC-8): hb @0 AND hb2 @24 confirm the key
                    // without a re-read.
                    if (\substr($slot, 0, 8) === $hb && \substr($slot, 24, 8) === $hb2) {
                        $f = \unpack('VrecOff/Vgen/Vvallen', $slot, 8);
                        $this->doOverwrite($si, $f['recOff'], $kl, $f['vallen'], $f['gen'], $kb, $vb, $vl, $types);
                        $done = true;
                        break;
                    }
                }
                if (!$done) {
                    throw new InvalidArgumentException('shared Fast directory is full: all ' . $this->slotCount . ' slots occupied (create the store with a larger capacity)');
                }
            } finally {
                $this->wu32(self::H_SEQ, $seq + 2);
            }
            $this->maybeCompact();
        } finally {
            $this->unlock(true);
        }
    }

    private function doInsert(int $si, string $hb, string $hb2, string $kb, int $kl, string $vb, int $vl, int $types): void
    {
        [$off, $cap] = $this->alloc($kl + $vl);
        $this->wu64(self::H_LIVECAPS, $this->ru64(self::H_LIVECAPS) + $cap);
        $this->dirtyBytes += $cap;
        // Bump the slot's generation so stale order-log entries for a reused
        // (previously tombstoned) slot are skipped during iteration, and the
        // reinserted key appears once, at the end of insertion order.
        $prev = $this->rd($si * self::SLOT, self::SLOT);
        $gen = (\ord($prev[22]) === self::ST_EMPTY ? 0 : \unpack('V', $prev, 12)[1]) + 1;
        $this->wr($off, $kb . $vb);
        $this->wr($si * self::SLOT, $hb . \pack('VVVvCC', $off, $gen, $vl, $kl, self::ST_LIVE, $types) . $hb2);
        // The order log is append-only and grows on every (re)insert; under
        // delete+reinsert churn that exceeds slotCount. It is fixed at slotCount
        // entries (live count can never exceed slotCount), so when it fills we
        // compact out stale (reused-slot) and dead (tombstoned) entries — the same
        // entries iteration already skips — to reclaim room before appending.
        $oc = $this->ru32(self::H_ORDER);
        if ($oc >= $this->slotCount) {
            $this->compactOrder();
            $oc = $this->ru32(self::H_ORDER);
        }
        $entry = $this->orderBytes === self::ORDER_TAGGED
            ? \pack('VVP', $si, $gen, \hrtime(true))   // tagged: stamp a global-order clock
            : \pack('VV', $si, $gen);
        $this->wr($this->slotCount * self::SLOT + $oc * $this->orderBytes, $entry);
        $this->wu32(self::H_ORDER, $oc + 1);
        $this->wu32(self::H_LIVE, $this->ru32(self::H_LIVE) + 1);
    }

    /**
     * Rewrite the order log in place, keeping only entries that point to a LIVE
     * slot whose generation still matches (the entries iteration yields), in their
     * existing order. Reclaims space taken by tombstoned/reused-slot entries.
     * Must hold the lock; the seqlock window is already open in the caller.
     */
    private function compactOrder(): void
    {
        $orderBase = $this->slotCount * self::SLOT;
        $ob = $this->orderBytes;
        $tagged = $ob === self::ORDER_TAGGED;
        $oc = $this->ru32(self::H_ORDER);
        $w = 0;
        for ($r = 0; $r < $oc; $r++) {
            $raw = $this->rd($orderBase + $r * $ob, $ob);
            $e = \unpack($tagged ? 'Vsi/Vgen/Ptag' : 'Vsi/Vgen', $raw);
            $slot = $this->rd($e['si'] * self::SLOT, self::SLOT);
            if (\ord($slot[22]) !== self::ST_LIVE) { continue; }
            if (\unpack('V', $slot, 12)[1] !== $e['gen']) { continue; }
            if ($w !== $r) {
                // Preserve the original entry bytes (incl. the tag) verbatim.
                $this->wr($orderBase + $w * $ob, $raw);
            }
            $w++;
        }
        $this->wu32(self::H_ORDER, $w);
    }

    private function doOverwrite(int $si, int $oldOff, int $kl, int $oldVl, int $gen, string $kb, string $vb, int $vl, int $types): void
    {
        $oldCap = $this->classFor($kl + $oldVl)[1];
        $need = $kl + $vl;
        if ($need <= $oldCap && $oldCap === $this->classFor($need)[1]) {
            $this->wr($oldOff, $kb . $vb);
            $this->wr($si * self::SLOT + 16, \pack('V', $vl));     // patch vallen
            $this->wr($si * self::SLOT + 23, \chr($types));        // patch types
            return;
        }
        [$off, $cap] = $this->alloc($need);
        $this->wr($off, $kb . $vb);
        // rewrite the slot tail (recOff..types); generation is preserved (same entry)
        $this->wr($si * self::SLOT + 8, \pack('VVVvCC', $off, $gen, $vl, $kl, self::ST_LIVE, $types));
        $this->freeBlock($oldOff, $oldCap);
        $this->wu64(self::H_LIVECAPS, $this->ru64(self::H_LIVECAPS) + $cap - $oldCap);
        $this->dirtyBytes += $cap + $oldCap;
    }

    /**
     * {@inheritDoc}
     *
     * @param int|string $key Key to delete.
     *
     * @return void
     */
    #[\Override]
    public function delete(int|string $key): void
    {
        if (!$this->sharedMode) { unset($this->localData[$this->scratch($key)]); return; }
        [, $kb, $hb, $hb2] = $this->norm($key);
        $kl = \strlen($kb);
        $base = \unpack('P', $hb)[1] & $this->mask;
        $this->lock();
        try {
            $freed = false;
            $seq = $this->ru32(self::H_SEQ);
            $this->wu32(self::H_SEQ, $seq + 1);
            try {
                for ($i = 0; $i < $this->slotCount; $i++) {
                    $si = ($base + $i) & $this->mask;
                    $slot = $this->rd($si * self::SLOT, self::SLOT);
                    $state = \ord($slot[22]);
                    if ($state === self::ST_EMPTY) { break; }
                    // Dual-tag identity (SC-8): hb @0 AND hb2 @24, no key re-read.
                    if ($state === self::ST_LIVE && \substr($slot, 0, 8) === $hb && \substr($slot, 24, 8) === $hb2) {
                        $f = \unpack('VrecOff/Vgen/Vvallen', $slot, 8);
                        $cap = $this->classFor($kl + $f['vallen'])[1];
                        $this->wr($si * self::SLOT + 22, \chr(self::ST_TOMB));
                        $this->freeBlock($f['recOff'], $cap);
                        $this->wu32(self::H_LIVE, $this->ru32(self::H_LIVE) - 1);
                        $this->wu32(self::H_TOMB, $this->ru32(self::H_TOMB) + 1);
                        $this->wu64(self::H_LIVECAPS, $this->ru64(self::H_LIVECAPS) - $cap);
                        $this->dirtyBytes += $cap;
                        $freed = true;
                        break;
                    }
                }
            } finally {
                $this->wu32(self::H_SEQ, $seq + 2);
            }
            if ($freed) { $this->maybeCompact(); }
        } finally {
            $this->unlock(true);
        }
    }

    // ===================== iteration (insertion order over the order log) =====================

    /**
     * {@inheritDoc}
     *
     * @return void
     */
    #[\Override]
    public function rewind(): void
    {
        if (!$this->sharedMode) { \reset($this->localData); return; }
        $this->position = 0;
        $this->iterAdvance();
    }

    private function iterAdvance(): void
    {
        $this->iterCur = null;
        $this->iterCurTag = 0;
        $orderBase = $this->slotCount * self::SLOT;
        while ($this->position < $this->ru32(self::H_ORDER)) {
            $pos = $this->position;
            $kv = null;
            $hit = false;
            $resolved = false;
            // Lock-free, seqlock-validated read of this order position: a foreach
            // must never observe a torn value while a peer writer publishes. An
            // odd sequence (write in progress) or a sequence change across the read
            // forces a retry; sustained contention falls back to a locked read.
            for ($spin = 0; $spin < $this->spin; $spin++) {
                $s1 = $this->rseq();
                if (\ord($s1[0]) & 1) { continue; }
                $torn = false;
                try { $hit = $this->readOrderPos($orderBase, $pos, $kv); }
                catch (\Throwable) { $torn = true; }
                if (!$torn && $s1 === $this->rseq()) { $resolved = true; break; }
            }
            if (!$resolved) {
                $this->lock();
                try { $hit = $this->readOrderPos($orderBase, $pos, $kv); }
                finally { $this->unlock(true); }
            }
            if ($hit) { $this->iterCur = $kv; return; }
            $this->position++;
        }
    }

    /**
     * Read one order-log position from a stable view (caller guarantees stability
     * via seqlock or the writer lock). Returns true with $kv = [key, value] for a
     * live entry whose slot generation still matches; false to skip a tombstoned
     * or reused-slot (stale) entry. May throw on a torn read; the caller retries.
     */
    private function readOrderPos(int $orderBase, int $pos, ?array &$kv): bool
    {
        $ob = $this->orderBytes;
        $e = \unpack($ob === self::ORDER_TAGGED ? 'Vsi/Vgen/Ptag' : 'Vsi/Vgen', $this->rd($orderBase + $pos * $ob, $ob));
        $slot = $this->rd($e['si'] * self::SLOT, self::SLOT);
        if (\ord($slot[22]) !== self::ST_LIVE) { return false; }
        $f = \unpack('VrecOff/Vgen/Vvallen/vkeylen/Cstate/Ctypes', $slot, 8);
        if ($f['gen'] !== $e['gen']) { return false; }
        $rec = $this->rd($f['recOff'], $f['keylen'] + $f['vallen']);
        $kb = \substr($rec, 0, $f['keylen']);
        $key = ($f['types'] >> 4) === 0 ? \unpack('q', $kb)[1] : $kb;
        $kv = [$key, $this->dec($f['types'] & 0xF, \substr($rec, $f['keylen'], $f['vallen']))];
        $this->iterCurTag = $e['tag'] ?? 0;
        return true;
    }

    /**
     * hrtime tag of the current iterator entry (0 when this engine is untagged).
     * The striped coordinator uses it to k-way-merge S sub-store cursors into one
     * global, approximately-insertion-ordered stream.
     *
     * @return int Nanosecond tag, or 0 when untagged.
     */
    public function currentTag(): int { return $this->iterCurTag; }

    /**
     * {@inheritDoc}
     *
     * @return bool
     */
    #[\Override]
    public function valid(): bool
    {
        if (!$this->sharedMode) { return \key($this->localData) !== null; }
        return $this->iterCur !== null;
    }

    /**
     * {@inheritDoc}
     *
     * @return mixed
     */
    #[\Override]
    public function current(): mixed
    {
        if (!$this->sharedMode) { $e = \current($this->localData); return $e === false ? null : $e[1]; }
        return $this->iterCur[1] ?? null;
    }

    /**
     * {@inheritDoc}
     *
     * @return mixed
     */
    #[\Override]
    public function key(): mixed
    {
        if (!$this->sharedMode) { $e = \current($this->localData); return $e === false ? null : $e[0]; }
        return $this->iterCur[0] ?? null;
    }

    /**
     * {@inheritDoc}
     *
     * @return void
     */
    #[\Override]
    public function next(): void
    {
        if (!$this->sharedMode) { \next($this->localData); return; }
        $this->position++;
        $this->iterAdvance();
    }

    /**
     * {@inheritDoc}
     *
     * @param int $position Zero-based insertion-order index.
     *
     * @return void
     */
    #[\Override]
    public function seek(int $position): void
    {
        if ($position < 0) { throw new \OutOfBoundsException('seek position must be >= 0'); }
        $this->rewind();
        for ($i = 0; $i < $position; $i++) {
            if (!$this->valid()) { throw new \OutOfBoundsException('seek position ' . $position . ' out of range'); }
            $this->next();
        }
        if (!$this->valid()) { throw new \OutOfBoundsException('seek position ' . $position . ' out of range'); }
    }

    // ===================== helpers =====================

    private function scratch(int|string $key): string
    {
        return \is_int($key) ? 'i:' . $key : 's:' . $key;
    }

    /**
     * {@inheritDoc}
     *
     * @param int $type Engine type constant.
     *
     * @return bool
     */
    #[\Override]
    public function isNullType(int $type): bool { return $type === self::TYPE_NULL; }
}
