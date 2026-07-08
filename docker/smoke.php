<?php declare(strict_types = 1);

if (!class_exists('Fast')) {
    fwrite(STDERR, "Fast class missing\n");
    exit(1);
}

$s = new Fast(['name' => 'docker-smoke-' . getmypid(), 'capacity' => 64, 'size' => 1048576]);
$s['k'] = ['ok' => true];

if (($s['k']['ok'] ?? null) !== true) {
    fwrite(STDERR, "read failed\n");
    exit(1);
}

$s->destroy();
echo "docker smoke ok\n";
