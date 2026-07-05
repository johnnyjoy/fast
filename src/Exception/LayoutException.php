<?php
/**
 * Layout mismatch exception for Fast shared segments.
 *
 * @package   Fast
 * @copyright Copyright (c) 2026 johnnyjoy
 * @license   https://opensource.org/licenses/MIT MIT License
 * @link      https://github.com/johnnyjoy/fast
 */

declare(strict_types = 1);

namespace Fast\Exception;

use RuntimeException;

/**
 * Thrown when a shared-memory segment cannot be safely attached because its
 * header is missing, malformed, or describes an incompatible on-wire layout.
 *
 * Attaching to such a segment and reinterpreting its bytes would corrupt reads
 * and the allocator, so the runtime refuses and requires an explicit
 * destroy/recreate instead of silently reusing stale or foreign memory.
 *
 * @package Fast
 */
final class LayoutException extends RuntimeException
{
}
