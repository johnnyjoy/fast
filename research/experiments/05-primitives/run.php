<?php declare(strict_types = 1);
/**
 * Design study — Experiment 5: primitive cost floor.
 *
 * Clean-room. Before designing the new engine's layout we must know what the
 * irreducible primitives actually cost on THIS box, because the whole point-op
 * budget is built from them. If a single shmop read already costs ~1.5 µs then a
 * 3-read get cannot compete and the layout must collapse to 1 read. Measure, don't guess.
 *
 * Measures, in ns/op:
 *   - shmop_read small (64B) and value-sized (per payload)
 *   - shmop_write small (8B header patch) and value-sized
 *   - sem_acquire + sem_release cycle
 *   - igbinary_serialize + igbinary_unserialize of the bench value shapes
 *   - hash('xxh3') of a typical key
 *   - pack/unpack of a slot vs a 40-field header
 *
 * Usage: php research/experiments/05-primitives/run.php [iters=1000000]
 */

namespace Fast\Research;

$iters = (int) ($argv[1] ?? 1000000);

$bench = static function (string $label, int $iters, callable $fn): array {
    // warm
    for ($i = 0; $i < 1000; $i++) { $fn($i); }
    $t = \hrtime(true);
    for ($i = 0; $i < $iters; $i++) { $fn($i); }
    $ns = (\hrtime(true) - $t) / $iters;
    \printf("  %-44s %8.1f ns/op   %12.0f op/s\n", $label, $ns, $ns > 0 ? 1e9 / $ns : 0);
    return [$label, $ns];
};

\printf("Experiment 5 — primitive cost floor (PHP %s, iters=%s)\n\n", \PHP_VERSION, \number_format($iters));

/* ---- shmop ---- */
$size = 64 * 1024 * 1024;
$key = \ftok(__FILE__, 'p');
$shm = @\shmop_open($key, 'c', 0600, $size);
if ($shm === false) {
    // try a private-ish key
    $shm = @\shmop_open(\mt_rand(1, 0x7ffffff0), 'c', 0600, $size);
}
if ($shm === false) {
    echo "shmop_open failed\n";
    exit(1);
}

$slot64 = \str_repeat('S', 64);
$val32  = \str_repeat('V', 32);
$val300 = \str_repeat('V', 300);
$val4096 = \str_repeat('V', 4096);
\shmop_write($shm, $slot64, 0);
\shmop_write($shm, $val4096, 1024);

echo "shmop (segment {$size} bytes):\n";
$bench('shmop_read 8B  (header field)', $iters, static fn () => \shmop_read($shm, 0, 8));
$bench('shmop_read 64B (one slot/bucket)', $iters, static fn () => \shmop_read($shm, 0, 64));
$bench('shmop_read 300B (mid value)', $iters, static fn () => \shmop_read($shm, 1024, 300));
$bench('shmop_read 4096B (big value)', $iters, static fn () => \shmop_read($shm, 1024, 4096));
$bench('shmop_write 8B (header patch)', $iters, static fn ($i) => \shmop_write($shm, \pack('VV', $i, $i), 0));
$bench('shmop_write 64B (slot)', $iters, static fn () => \shmop_write($shm, $slot64, 4096));
$bench('shmop_write 300B (value)', $iters, static fn () => \shmop_write($shm, $val300, 8192));

/* ---- semaphore ---- */
echo "\nsysv semaphore:\n";
$sem = \sem_get(\ftok(__FILE__, 's'), 1, 0600, true);
if ($sem !== false) {
    $bench('sem_acquire + sem_release cycle', $iters, static function () use ($sem) {
        \sem_acquire($sem);
        \sem_release($sem);
    });
}

/* ---- igbinary ---- */
echo "\nigbinary (bench value shapes):\n";
$mk = static function (int $payloadLen): array {
    $v = ['k' => "user:12345", 'm' => 42, 'flag' => true];
    if ($payloadLen > 0) { $v['p'] = \str_repeat('A', $payloadLen); }
    return $v;
};
foreach ([0, 32, 300, 4096] as $pl) {
    $v = $mk($pl);
    $enc = \igbinary_serialize($v);
    $bench("igbinary_serialize  (payload {$pl}B -> " . \strlen($enc) . "B)", $iters, static fn () => \igbinary_serialize($v));
    $bench("igbinary_unserialize(payload {$pl}B)", $iters, static fn () => \igbinary_unserialize($enc));
}

/* ---- hashing ---- */
echo "\nhashing / pack:\n";
$bench("hash('xxh3', 'user:12345', true)", $iters, static fn () => \hash('xxh3', 'user:12345', true));
$bench('pack slot (PPPPCCvV)', $iters, static fn ($i) => \pack('PPPPCCvV', $i, $i, $i, $i, 1, 1, 0, 8));
$slotBytes = \pack('PPPPCCvV', 1, 2, 3, 4, 1, 1, 0, 8);
$bench('unpack slot (8 fields)', $iters, static fn () => \unpack('Pkey_hash/Pid/Porder_off/Pgeneration/Cstate/Ckey_kind/vflags/Vkey_len', $slotBytes));
$hdr512 = \str_repeat("\0", 512);
$bench('unpack 40-field header (512B)', $iters, static fn () => \unpack('a4m/Vv/Vl/Vr/Vs/Vlc/Vss/Pdb/Vds/Vdby/Pib/Vic/Viu/Viby/Pob/Voc/Vou/Voby/Voh/Vot/Pab/Vab2/Vaf/Vlv/Vtb/Vf0/Vf1/Vf2/Vf3/Vf4/Vf5/Vf6/Vf7/Vf8/Vf9/Vf10/Vf11/Vf12/Vhb', $hdr512));

\shmop_delete($shm);
echo "\ndone\n";
