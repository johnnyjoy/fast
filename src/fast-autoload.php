<?php declare(strict_types = 1);

/**
 * Loads userland \\Fast when ext-fast is not present.
 * When ext-fast is loaded, the extension owns class Fast (ADR 005).
 */

if (!\extension_loaded('fast')) {
    require __DIR__ . '/Fast.php';
}
