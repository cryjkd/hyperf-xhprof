<?php

declare(strict_types=1);

namespace Cryjkd\HyperfXhprof;

/**
 * A coroutine-scoped profiling trace: flat aggregates plus an invocation tree.
 *
 * Exclusive time is derived with a running "children time" credit on each open
 * frame, so it stays exact even with recursion and repeated calls.
 */
final class Trace
{
    public string $id;

    public string $uri = '';

    public string $method = '';

    public float $startedAt;

    public int $startedWallNs;

    public int $startedCpuUs = 0;

    public int $startedMem = 0;

    /** Total wall time in nanoseconds. */
    public int $wallNs = 0;

    /** Total CPU time in microseconds. */
    public int $cpuUs = 0;

    public int $peakMem = 0;

    public int $depth = 0;

    public int $maxDepthReached = 0;

    public bool $finished = false;

    /** @var Node[] name => Node */
    public array $nodes = [];

    /** @var TreeNode[] */
    public array $root = [];

    /** @var array<int, array<string, mixed>> open frame stack */
    public array $stack = [];

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(?array $config = null)
    {
        $config = is_array($config) ? $config : [];

        $this->config = [
            'collect' => array_merge(
                ['cpu' => true, 'memory' => true, 'max_depth' => 0],
                is_array($config['collect'] ?? null) ? $config['collect'] : []
            ),
            'exclude' => is_array($config['exclude'] ?? null) ? $config['exclude'] : [],
        ];

        $this->id = self::generateId();
        $this->startedAt = microtime(true);
        $this->startedWallNs = self::wallNs();

        if ($this->config['collect']['cpu']) {
            $this->startedCpuUs = self::cpuUs();
        }
        if ($this->config['collect']['memory']) {
            $this->startedMem = self::mem();
        }
    }

    public function begin(string $name): void
    {
        if ($this->finished) {
            return;
        }

        if (! isset($this->nodes[$name])) {
            $this->nodes[$name] = new Node($name);
        }
        $node = $this->nodes[$name];
        ++$node->calls;

        if ($this->config['collect']['memory'] && $node->memStart === 0) {
            $node->memStart = self::mem();
        }

        ++$this->depth;
        if ($this->depth > $this->maxDepthReached) {
            $this->maxDepthReached = $this->depth;
        }

        $treeNode = null;
        $maxDepth = (int) $this->config['collect']['max_depth'];
        if ($maxDepth <= 0 || $this->depth <= $maxDepth) {
            $treeNode = new TreeNode($name);
            if ($this->stack) {
                $parent = $this->stack[count($this->stack) - 1];
                $parent['treeNode']->children[] = $treeNode;
            } else {
                $this->root[] = $treeNode;
            }
        }

        $this->stack[] = [
            'name' => $name,
            'node' => $node,
            'treeNode' => $treeNode,
            'startWallNs' => self::wallNs(),
            'startCpuUs' => $this->config['collect']['cpu'] ? self::cpuUs() : 0,
            'startMem' => $this->config['collect']['memory'] ? self::mem() : 0,
            'childWallNs' => 0,
            'childCpuUs' => 0,
        ];
    }

    public function end(string $name): void
    {
        if ($this->finished || ! $this->stack) {
            return;
        }

        $frame = array_pop($this->stack);
        --$this->depth;

        $wallNs = self::wallNs() - $frame['startWallNs'];
        $exclWallNs = $wallNs - $frame['childWallNs'];
        if ($exclWallNs < 0) {
            $exclWallNs = 0;
        }

        /** @var Node $node */
        $node = $frame['node'];
        $node->wallNs += $wallNs;
        $node->exclWallNs += $exclWallNs;

        $cpuUs = 0;
        $exclCpuUs = 0;
        if ($this->config['collect']['cpu']) {
            $cpuUs = self::cpuUs() - $frame['startCpuUs'];
            $exclCpuUs = $cpuUs - $frame['childCpuUs'];
            if ($exclCpuUs < 0) {
                $exclCpuUs = 0;
            }
            $node->cpuUs += $cpuUs;
            $node->exclCpuUs += $exclCpuUs;
        }

        if ($this->config['collect']['memory']) {
            $memNow = self::mem();
            $node->memEnd = $memNow;
            if ($memNow > $node->memPeak) {
                $node->memPeak = $memNow;
            }
        }

        if ($frame['treeNode'] instanceof TreeNode) {
            $treeNode = $frame['treeNode'];
            $treeNode->wallUs = (int) floor($wallNs / 1000);
            $treeNode->exclWallUs = (int) floor($exclWallNs / 1000);
            $treeNode->cpuUs = $cpuUs;
            $treeNode->exclCpuUs = $exclCpuUs;
            $treeNode->memStart = (int) $frame['startMem'];
            $treeNode->memEnd = $this->config['collect']['memory'] ? $node->memEnd : 0;
        }

        if ($this->stack) {
            $idx = count($this->stack) - 1;
            $this->stack[$idx]['childWallNs'] += $wallNs;
            if ($this->config['collect']['cpu']) {
                $this->stack[$idx]['childCpuUs'] += $cpuUs;
            }
        }
    }

    public function shouldRecord(string $name): bool
    {
        foreach ($this->config['exclude'] as $pattern) {
            if (self::matchPattern((string) $pattern, $name)) {
                return false;
            }
        }

        return true;
    }

    public function finish(): void
    {
        if ($this->finished) {
            return;
        }

        $this->finished = true;
        $this->wallNs = self::wallNs() - $this->startedWallNs;
        if ($this->config['collect']['cpu']) {
            $this->cpuUs = self::cpuUs() - $this->startedCpuUs;
        }
        $this->peakMem = memory_get_peak_usage();
    }

    public function hasData(): bool
    {
        return $this->nodes !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $this->finish();

        $flat = [];
        foreach ($this->nodes as $node) {
            $flat[] = [
                'name' => $node->name,
                'calls' => $node->calls,
                'wall_us' => (int) floor($node->wallNs / 1000),
                'excl_wall_us' => (int) floor($node->exclWallNs / 1000),
                'cpu_us' => $node->cpuUs,
                'excl_cpu_us' => $node->exclCpuUs,
                'mem_start' => $node->memStart,
                'mem_end' => $node->memEnd,
                'mem_peak' => $node->memPeak,
            ];
        }
        usort($flat, static fn (array $a, array $b): int => $b['wall_us'] <=> $a['wall_us']);

        return [
            'id' => $this->id,
            'uri' => $this->uri,
            'method' => $this->method,
            'started_at' => $this->startedAt,
            'wall_us' => (int) floor($this->wallNs / 1000),
            'cpu_us' => $this->cpuUs,
            'peak_mem' => $this->peakMem,
            'max_depth' => $this->maxDepthReached,
            'nodes' => $flat,
            'tree' => array_map(fn (TreeNode $n): array => $this->treeToArray($n), $this->root),
        ];
    }

    public static function wallNs(): int
    {
        return (int) hrtime(true);
    }

    public static function cpuUs(): int
    {
        $usage = getrusage();
        $user = (int) ($usage['ru_utime.tv_sec'] * 1_000_000 + $usage['ru_utime.tv_usec']);
        $sys = (int) ($usage['ru_stime.tv_sec'] * 1_000_000 + $usage['ru_stime.tv_usec']);

        return $user + $sys;
    }

    public static function mem(): int
    {
        return memory_get_usage();
    }

    public static function generateId(): string
    {
        return date('YmdHis') . '_' . bin2hex(random_bytes(4));
    }

    /**
     * @return array<string, mixed>
     */
    private function treeToArray(TreeNode $node): array
    {
        return [
            'name' => $node->name,
            'wall_us' => $node->wallUs,
            'excl_wall_us' => $node->exclWallUs,
            'cpu_us' => $node->cpuUs,
            'excl_cpu_us' => $node->exclCpuUs,
            'mem_start' => $node->memStart,
            'mem_end' => $node->memEnd,
            'children' => array_map(fn (TreeNode $n): array => $this->treeToArray($n), $node->children),
        ];
    }

    private static function matchPattern(string $pattern, string $name): bool
    {
        if (strpos($pattern, '*') !== false) {
            $preg = str_replace(['*', '\\'], ['.*', '\\\\'], $pattern);

            return (bool) preg_match('/^' . $preg . '$/', $name);
        }

        return strpos($name, $pattern) !== false;
    }
}
