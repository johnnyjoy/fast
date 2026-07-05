<?php declare(strict_types = 1);

/**
 * Contract test: Shared Iteration Peer Mutation.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use \Fast;

// P2: a foreach must remain correct and never observe torn/corrupt data while a
// peer process mutates the same store concurrently. Iteration is snapshot-ish, so
// every observed (key, value) pair must be internally consistent: the value's
// numeric tag must match the key's, regardless of interleaving. We do not assert
// an exact value set (that depends on timing); we assert NO corruption.

if (!\function_exists('pcntl_fork')) {
    fwrite(STDERR, "skip: pcntl is required\n");
    exit(77);
}

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$name = 'fast-store-iter-peer-' . \bin2hex(\random_bytes(8));
$N = 800;

try {
    (new \Fast(['name' => $name, 'capacity' => 4096, 'size' => 16777216]))->destroy();
} catch (\Throwable) {
}

$store = new \Fast(['name' => $name, 'capacity' => 4096, 'size' => 16777216, 'persistent' => true]);
for ($i = 0; $i < $N; $i++) {
    $store['k' . $i] = 'val-' . $i;
}
$store->close();

// --- Child: hammer the store with updates/larger-replaces while parent iterates --
$pid = \pcntl_fork();
if ($pid === -1) {
    $fail('fork failed');
}
if ($pid === 0) {
    $c = new \Fast($name);
    \mt_srand(12345);
    for ($r = 0; $r < 6000; $r++) {
        $i = \mt_rand(0, $N - 1);
        if (($r & 3) === 0) {
            $c['k' . $i] = 'MUT-' . $i . '-' . \str_repeat('x', \mt_rand(0, 200));
        } else {
            $c['k' . $i] = 'val-' . $i;
        }
    }
    $c->close();
    exit(0);
}

// --- Parent: iterate repeatedly, validating consistency of every pair ------------
$reader = new \Fast($name);
$pairs = 0;
for ($pass = 0; $pass < 8; $pass++) {
    foreach ($reader as $k => $v) {
        if (!\is_string($k) || \strncmp($k, 'k', 1) !== 0) {
            $fail('torn/garbage key observed: ' . var_export($k, true));
        }
        $kn = (int) \substr($k, 1);
        // value must be "val-<kn>" or "MUT-<kn>-..." — the tag must match the key.
        if (\preg_match('/^val-(\d+)$/', (string) $v, $m)) {
            if ((int) $m[1] !== $kn) {
                $fail('torn value/key mismatch: key=' . $k . ' value=' . var_export($v, true));
            }
        } elseif (\preg_match('/^MUT-(\d+)-/', (string) $v, $m)) {
            if ((int) $m[1] !== $kn) {
                $fail('torn value/key mismatch: key=' . $k . ' value=' . var_export($v, true));
            }
        } else {
            $fail('corrupt value observed for ' . $k . ': ' . var_export($v, true));
        }
        $pairs++;
        if (($pairs & 63) === 0) {
            \usleep(50);
        }
    }
}

\pcntl_waitpid($pid, $status);
if (!\pcntl_wifexited($status) || \pcntl_wexitstatus($status) !== 0) {
    $fail('child mutator exited abnormally');
}

$reader->close();

// The store must remain fully readable and intact after concurrent churn during
// iteration. Validate on a FRESH attach that every key reads back a consistent,
// tag-matching value (no loss, no corruption from the peer-mutated walk).
$verify = new \Fast($name);
if (\count($verify) !== $N) {
    $fail('post-churn count mismatch: expected ' . $N . ', got ' . \count($verify));
}
for ($i = 0; $i < $N; $i++) {
    $v = $verify['k' . $i];
    if (\preg_match('/^val-(\d+)$/', (string) $v, $m)) {
        if ((int) $m[1] !== $i) { $fail('final value/key mismatch at k' . $i); }
    } elseif (\preg_match('/^MUT-(\d+)-/', (string) $v, $m)) {
        if ((int) $m[1] !== $i) { $fail('final value/key mismatch at k' . $i); }
    } else {
        $fail('final corrupt value at k' . $i . ': ' . var_export($v, true));
    }
}

$verify->destroy();

echo 'shared iteration peer mutation ok (pairs observed: ' . $pairs . ')' . PHP_EOL;
