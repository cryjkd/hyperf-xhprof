<?php

declare(strict_types=1);

namespace Cryjkd\HyperfXhprof;

/**
 * One node in the per-invocation call tree (flame-graph friendly).
 */
final class TreeNode
{
    public string $name;

    /** Inclusive wall time of this invocation, in microseconds. */
    public int $wallUs = 0;

    /** Exclusive wall time of this invocation, in microseconds. */
    public int $exclWallUs = 0;

    /** Inclusive CPU time of this invocation, in microseconds. */
    public int $cpuUs = 0;

    /** Exclusive CPU time of this invocation, in microseconds. */
    public int $exclCpuUs = 0;

    public int $memStart = 0;

    public int $memEnd = 0;

    /** @var TreeNode[] */
    public array $children = [];

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
