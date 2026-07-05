<?php declare(strict_types = 1);

/**
 * Contract test: Examples Run.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

$dir = __DIR__;
\chdir($dir);

$examples = [
    'example_blocks.php',
];

$failed = 0;

foreach ($examples as $script) {
    $path = $dir . '/' . $script;

    if (!\is_file($path)) {
        \fwrite(STDERR, 'missing example script: ' . $script . PHP_EOL);
        exit(1);
    }

    echo '=== ' . $script . ' ===' . PHP_EOL;
    \passthru('php ' . \escapeshellarg($path), $code);

    if ($code === 77) {
        echo 'skip: ' . $script . PHP_EOL;
        continue;
    }

    if ($code !== 0) {
        $failed++;
        break;
    }
}

exit($failed > 0 ? 1 : 0);
