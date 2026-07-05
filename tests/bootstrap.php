<?php declare(strict_types = 1);

/**
 * Test/bench/research bootstrap — loads the Fast library via Composer or a minimal fallback.
 */

$root = \dirname(__DIR__);
$vendor = $root . '/vendor/autoload.php';

if (\is_file($vendor)) {
    require $vendor;
} else {
    \spl_autoload_register(static function (string $class) use ($root): void {
        if ($class === 'Fast') {
            require $root . '/src/Fast.php';

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
}
