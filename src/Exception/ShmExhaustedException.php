<?php
/**
 * Shared-memory exhaustion exception for Fast.
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
 * Thrown when a shared Fast store cannot grow because the host is out of shared
 * memory (a growth segment could not be created — e.g. the container's
 * /dev/shm / docker --shm-size limit, or a kernel SysV shm limit, was hit).
 *
 * It is raised by the allocator BEFORE any frontier / LIVECAPS accounting is
 * mutated, so a failed write leaves the store's space accounting intact rather
 * than permanently drifting the compaction trigger. Callers can catch this
 * specific type to distinguish "out of room" from other write failures.
 *
 * @package Fast
 */
final class ShmExhaustedException extends RuntimeException
{
}
