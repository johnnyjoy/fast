<?php declare(strict_types = 1);

/**
 * Test/bench/research bootstrap — loads the Fast library via Composer or a minimal fallback.
 *
 * When ext-fast is loaded it owns \\Fast; userland src/Fast.php is not loaded.
 */

$root = \dirname(__DIR__);
$backend = \getenv('FAST_BACKEND') ?: 'php';

if ($backend === 'ext' && !\extension_loaded('fast')) {
    $extSo = \getenv('FAST_EXT_SO') ?: $root . '/ext/fast/modules/fast.so';
    if (\is_file($extSo)) {
        /* Subprocesses (e.g. diagnostics_sink trigger) inherit FAST_BACKEND=ext
         * but are not launched with -d extension=; fall back to userland Fast. */
        \putenv('FAST_BACKEND=php');
        $backend = 'php';
    } else {
        \fwrite(STDERR, "FAST_BACKEND=ext but ext-fast is not loaded (enable extension in php.ini)\n");
        exit(2);
    }
}

$vendor = $root . '/vendor/autoload.php';

if (\is_file($vendor)) {
    require $vendor;
} else {
    \spl_autoload_register(static function (string $class) use ($root): void {
        if ($class === 'Fast') {
            if (!\extension_loaded('fast')) {
                require $root . '/src/Fast.php';
            }

            return;
        }

        if (!\str_starts_with($class, 'Fast\\')) {
            return;
        }

        $relative = \substr($class, \strlen('Fast\\'));
        $path = $root . '/src/' . \str_replace('\\', '/', $relative) . '.php';

        if (\is_file($path)) {
            require $path;
        }
    });

    if (!\extension_loaded('fast') && !\class_exists('Fast', false)) {
        require $root . '/src/Fast.php';
    }
}
