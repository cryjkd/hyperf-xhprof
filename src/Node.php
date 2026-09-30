<?php

declare(strict_types=1);

namespace Cryjkd\HyperfXhprof;

/**
 * Aggregated, flat metrics for one unique function/method name.
 */
final class Node
{
    public string $name;

    public int $calls = 0;

    /** Inclusive wall time in nanoseconds. */
    public int $wallNs = 0;

    /** Exclusive wall time in nanoseconds. */
    public int $exclWallNs = 0;

    /** Inclusive CPU time in microseconds. */
    public int $cpuUs = 0;

    /** Exclusive CPU time in microseconds. */
    public int $exclCpuUs = 0;

    /** memory_get_usage() at the first entry, in bytes (0 = not collected). */
    public int $memStart = 0;

    /** memory_get_usage() at the last exit, in bytes. */
    public int $memEnd = 0;

    /** Highest memory observed while the function was on the stack, in bytes. */
    public int $memPeak = 0;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
