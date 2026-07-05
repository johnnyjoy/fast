<?php declare(strict_types = 1);

/**
 * Design-study spike: can FFI lift the two hard PHP/shmop constraints?
 *
 *   (A) POSIX shm resize: shm_open + ftruncate + mmap + remap to a larger size,
 *       with data surviving the grow. Gates the "shrink/grow" design (study §4).
 *   (B) Crash-safe attach count: read shm_nattch of a shmop-created SysV segment
 *       via shmget + shmctl(IPC_STAT). Gates the lifecycle design (study §1).
 *
 * Linux x86-64 / glibc assumptions are documented inline. Best-effort, reports
 * a capability matrix. This is research, not a contract test.
 */

$results = [];

function report(string $name, bool $ok, string $detail): void
{
    global $results;
    $results[] = [$name, $ok, $detail];
}

if (!extension_loaded('FFI')) {
    fwrite(STDERR, "FFI not loaded\n");
    exit(2);
}

/* ---- Linux constants (x86-64) ---- */
const O_RDWR   = 2;
const O_CREAT  = 64;     // 0100
const O_EXCL   = 128;    // 0200
const PROT_RW  = 3;      // PROT_READ|PROT_WRITE
const MAP_SHARED = 1;
const IPC_STAT = 2;

/* =====================================================================
 * (A) POSIX shm resize via FFI
 * ===================================================================== */
try {
    $libc = FFI::cdef(<<<'C'
        int shm_open(const char *name, int oflag, unsigned int mode);
        int shm_unlink(const char *name);
        int ftruncate(int fd, long length);
        void *mmap(void *addr, unsigned long length, int prot, int flags, int fd, long offset);
        int munmap(void *addr, unsigned long length);
        int close(int fd);
    C, "libc.so.6");

    $name = "/fast_ffi_spike_" . getmypid();
    @$libc->shm_unlink($name);

    $addrOf = static function ($ptr) use ($libc): int {
        // robust pointer->integer: cast to intptr_t (FFI builtin scalar)
        return (int) $libc->cast('intptr_t', $ptr)->cdata;
    };

    $fd = $libc->shm_open($name, O_RDWR | O_CREAT | O_EXCL, 0600);
    if ($fd < 0) {
        throw new RuntimeException("shm_open failed (fd=$fd)");
    }

    // size 1: 4096
    if ($libc->ftruncate($fd, 4096) !== 0) {
        throw new RuntimeException("ftruncate#1 failed");
    }
    $p1 = $libc->mmap(null, 4096, PROT_RW, MAP_SHARED, $fd, 0);
    if (FFI::isNull($p1) || $addrOf($p1) === -1) {
        throw new RuntimeException("mmap#1 failed (addr=" . $addrOf($p1) . " fd=$fd)");
    }
    $cp1 = $libc->cast('char*', $p1);
    $marker = "FAST-RESIZE-OK";
    FFI::memcpy($cp1, $marker, strlen($marker));
    $libc->munmap($p1, 4096);

    // grow to 8192 and confirm the marker survived
    if ($libc->ftruncate($fd, 8192) !== 0) {
        throw new RuntimeException("ftruncate#2 (grow) failed");
    }
    $p2 = $libc->mmap(null, 8192, PROT_RW, MAP_SHARED, $fd, 0);
    if (FFI::isNull($p2) || $addrOf($p2) === -1) {
        throw new RuntimeException("mmap#2 failed (addr=" . $addrOf($p2) . " fd=$fd)");
    }
    $cp2 = $libc->cast('char*', $p2);
    $read = FFI::string($cp2, strlen($marker));

    // write into the newly-grown tail to prove the larger region is usable
    $cp2b = $libc->cast('char*', $p2);
    FFI::memcpy(FFI::addr($cp2b[8000]), "TAIL", 4);
    $tailRead = FFI::string(FFI::addr($cp2b[8000]), 4);

    $libc->munmap($p2, 8192);
    $libc->close($fd);
    $libc->shm_unlink($name);

    $ok = ($read === $marker) && ($tailRead === "TAIL");
    report("A. POSIX shm_open+ftruncate+mmap resize", $ok,
        $ok ? "grew 4096->8192, data survived, tail writable"
            : "marker='$read' tail='$tailRead'");
} catch (Throwable $e) {
    report("A. POSIX shm_open+ftruncate+mmap resize", false, get_class($e) . ": " . $e->getMessage());
}

/* =====================================================================
 * (B) Read shm_nattch of a shmop-created SysV segment via FFI shmctl
 * ===================================================================== */
try {
    if (!extension_loaded('shmop')) {
        throw new RuntimeException("shmop not loaded");
    }

    $sysv = FFI::cdef(<<<'C'
        typedef int key_t;
        struct ipc_perm {
            int __key;
            unsigned int uid;
            unsigned int gid;
            unsigned int cuid;
            unsigned int cgid;
            unsigned short mode;
            unsigned short __pad1;
            unsigned short __seq;
            unsigned short __pad2;
            unsigned long __glibc_reserved1;
            unsigned long __glibc_reserved2;
        };
        struct shmid_ds {
            struct ipc_perm shm_perm;
            unsigned long shm_segsz;
            long shm_atime;
            long shm_dtime;
            long shm_ctime;
            int shm_cpid;
            int shm_lpid;
            unsigned long shm_nattch;
            unsigned long __glibc_reserved5;
            unsigned long __glibc_reserved6;
        };
        int shmget(key_t key, unsigned long size, int shmflg);
        int shmctl(int shmid, int cmd, struct shmid_ds *buf);
    C, "libc.so.6");

    $key = 0x70687261; // arbitrary SysV key for the spike
    // create + attach via shmop (this is exactly what Fast uses)
    $h = @shmop_open($key, "c", 0600, 4096);
    if ($h === false) {
        // maybe stale; try open then recreate
        $h = @shmop_open($key, "w", 0, 0);
    }
    if ($h === false) {
        throw new RuntimeException("shmop_open failed for key");
    }

    $shmid = $sysv->shmget($key, 0, 0);
    if ($shmid < 0) {
        throw new RuntimeException("shmget lookup failed (shmid=$shmid)");
    }

    $buf = $sysv->new('struct shmid_ds');
    $rc  = $sysv->shmctl($shmid, IPC_STAT, FFI::addr($buf));
    if ($rc !== 0) {
        throw new RuntimeException("shmctl(IPC_STAT) failed (rc=$rc)");
    }

    $nattch = $buf->shm_nattch;
    $segsz  = $buf->shm_segsz;

    // struct-layout sanity: segsz should be the 4096 we created
    $layoutOk = ($segsz === 4096 || $segsz >= 4096);
    $ok = $layoutOk && $nattch >= 1;
    report("B. Read shm_nattch via shmctl(IPC_STAT)", $ok,
        "nattch=$nattch segsz=$segsz" . ($layoutOk ? "" : " [LAYOUT MISMATCH]"));

    // cleanup: shmop_delete marks for removal
    if (function_exists('shmop_delete')) {
        @shmop_delete($h);
    }
} catch (Throwable $e) {
    report("B. Read shm_nattch via shmctl(IPC_STAT)", false, get_class($e) . ": " . $e->getMessage());
}

/* ---- report ---- */
echo "FFI shared-memory feasibility spike (PHP " . PHP_VERSION . ")\n";
echo str_repeat("=", 60) . "\n";
$allOk = true;
foreach ($results as [$name, $ok, $detail]) {
    $allOk = $allOk && $ok;
    printf("[%s] %s\n      %s\n", $ok ? "PASS" : "FAIL", $name, $detail);
}
echo str_repeat("=", 60) . "\n";
echo $allOk ? "RESULT: FFI can lift both constraints.\n"
            : "RESULT: at least one constraint NOT liftable as written (see above).\n";
exit($allOk ? 0 : 1);
