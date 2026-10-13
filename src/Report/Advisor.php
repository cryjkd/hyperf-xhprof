<?php

declare(strict_types=1);

namespace Cryjkd\HyperfXhprof\Report;

use Cryjkd\HyperfXhprof\Profiler;

/**
 * Turns a finished trace into a short, human-readable list of findings about
 * what is slow, CPU/memory heavy or otherwise worth optimising, each with a
 * concrete, actionable set of optimisation suggestions.
 *
 * Every threshold lives in the `analyze` config block (with defaults baked in),
 * so the heuristics can be tuned without touching this class. Times in the
 * config are expressed in milliseconds, memory in megabytes.
 */
final class Advisor
{
    private const DEFAULT_THRESHOLDS = [
        // 方法自身墙钟超过该值 -> 慢方法
        'slow_excl_ms' => 100.0,
        // 方法自身墙钟占总耗时超过该百分比 -> 热点
        'slow_pct' => 20.0,
        // 方法自身 CPU 超过该值 -> CPU 密集
        'cpu_ms' => 50.0,
        // 方法内内存增量超过该值 -> 内存增长
        'mem_mb' => 10.0,
        // 调用次数超过该值 -> 疑似 N+1 / 循环
        'calls' => 50,
        // 调用深度超过该值 -> 递归 / 嵌套过深
        'max_depth' => 30,
        // 请求总耗时超过该值 -> 总耗时偏长
        'total_wall_ms' => 1000.0,
        // 墙钟 / CPU 超过该值 -> 疑似阻塞等待
        'io_wall_cpu_ratio' => 3.0,
        // 峰值内存超过该值 -> 内存偏高
        'peak_mem_mb' => 128.0,
        // 每类最多列出多少个方法
        'top_n' => 8,
    ];

    /**
     * @param array<string, mixed> $data Trace::toArray() output.
     * @return array<int, array{level:string, title:string, detail:string, suggestions:array<int, string>}>
     */
    public function analyze(array $data): array
    {
        $t = $this->thresholds();
        $findings = [];

        $wallUs = max(0, (int) ($data['wall_us'] ?? 0));
        $cpuUs = max(0, (int) ($data['cpu_us'] ?? 0));
        $peakMem = (int) ($data['peak_mem'] ?? 0);
        $maxDepth = (int) ($data['max_depth'] ?? 0);
        $nodes = is_array($data['nodes'] ?? null) ? $data['nodes'] : [];
        $topN = max(1, (int) ($t['top_n'] ?? 8));

        $this->analyzeOverview($findings, $wallUs, $cpuUs, $peakMem, $maxDepth, $t);
        $this->analyzeMethods($findings, $nodes, $wallUs, $topN, $t);

        if ($findings === []) {
            $findings[] = [
                'level' => 'info',
                'title' => '未发现明显瓶颈',
                'detail' => '各项指标均在阈值内，暂无需优化。',
                'suggestions' => $this->tips('none'),
            ];
        }

        return $findings;
    }

    /**
     * Whole-request level checks: total time, blocking wait, peak memory, depth.
     *
     * @param array<int, array<string, mixed>> $findings
     * @param array<string, mixed> $t
     */
    private function analyzeOverview(array &$findings, int $wallUs, int $cpuUs, int $peakMem, int $maxDepth, array $t): void
    {
        $totalThresholdUs = $this->toUs((float) ($t['total_wall_ms'] ?? 1000.0));
        if ($wallUs >= $totalThresholdUs) {
            $findings[] = [
                'level' => $wallUs >= $totalThresholdUs * 3 ? 'critical' : 'warning',
                'title' => '请求总耗时偏长',
                'detail' => sprintf('本次请求总耗时 %.2f ms，超过阈值 %.0f ms。', $wallUs / 1000, (float) ($t['total_wall_ms'] ?? 1000.0)),
                'suggestions' => $this->tips('total_wall'),
            ];
        }

        if ($wallUs >= 100000 && $cpuUs > 0) {
            $ratio = $wallUs / max(1, $cpuUs);
            if ($ratio >= (float) ($t['io_wall_cpu_ratio'] ?? 3.0)) {
                $findings[] = [
                    'level' => 'warning',
                    'title' => '疑似阻塞等待（I/O / 网络 / 锁 / sleep）',
                    'detail' => sprintf('墙钟 %.2f ms 远大于 CPU %.2f ms（比值 %.1f），大量时间花在等待而非计算。', $wallUs / 1000, $cpuUs / 1000, $ratio),
                    'suggestions' => $this->tips('blocking'),
                ];
            }
        }

        if ($peakMem >= $this->toBytes((float) ($t['peak_mem_mb'] ?? 128.0))) {
            $findings[] = [
                'level' => 'warning',
                'title' => '峰值内存偏高',
                'detail' => sprintf('进程峰值内存 %.1f MB，超过阈值 %.0f MB。', $peakMem / 1048576, (float) ($t['peak_mem_mb'] ?? 128.0)),
                'suggestions' => $this->tips('peak_mem'),
            ];
        }

        if ($maxDepth >= (int) ($t['max_depth'] ?? 30)) {
            $findings[] = [
                'level' => 'warning',
                'title' => '调用栈过深',
                'detail' => sprintf('最大调用深度 %d，超过阈值 %d，注意递归或过深的嵌套。', $maxDepth, (int) ($t['max_depth'] ?? 30)),
                'suggestions' => $this->tips('depth'),
            ];
        }
    }

    /**
     * Per-method checks: slow/hot methods, CPU dense, high call count, memory growth.
     *
     * @param array<int, array<string, mixed>> $findings
     * @param array<int, array<string, mixed>> $nodes
     * @param array<string, mixed> $t
     */
    private function analyzeMethods(array &$findings, array $nodes, int $wallUs, int $topN, array $t): void
    {
        if ($nodes === []) {
            return;
        }

        $totalUs = max(1, $wallUs);
        $slowExclUs = $this->toUs((float) ($t['slow_excl_ms'] ?? 100.0));
        $slowPct = (float) ($t['slow_pct'] ?? 20.0);
        $cpuUs = $this->toUs((float) ($t['cpu_ms'] ?? 50.0));
        $memBytes = $this->toBytes((float) ($t['mem_mb'] ?? 10.0));
        $callsThreshold = (int) ($t['calls'] ?? 50);

        // 慢方法 / 热点：按自身墙钟降序。
        $byExcl = $nodes;
        usort($byExcl, static function (array $a, array $b): int {
            return (int) ($b['excl_wall_us'] ?? 0) <=> (int) ($a['excl_wall_us'] ?? 0);
        });
        foreach (array_slice($byExcl, 0, $topN) as $node) {
            $excl = (int) ($node['excl_wall_us'] ?? 0);
            $pct = $totalUs > 0 ? $excl / $totalUs * 100 : 0.0;
            $isSlow = $excl >= $slowExclUs;
            $isHot = $totalUs >= 100000 && $excl >= $totalUs * $slowPct / 100;
            if (! $isSlow && ! $isHot) {
                continue;
            }

            $findings[] = [
                'level' => $pct >= 50 ? 'critical' : 'warning',
                'title' => '慢方法：' . (string) ($node['name'] ?? ''),
                'detail' => sprintf('自身耗时 %.2f ms，占本次请求 %.1f%%。', $excl / 1000, $pct),
                'suggestions' => $this->tips('slow'),
            ];
        }

        // CPU 密集：按自身 CPU 降序。
        $byCpu = $nodes;
        usort($byCpu, static function (array $a, array $b): int {
            return (int) ($b['excl_cpu_us'] ?? 0) <=> (int) ($a['excl_cpu_us'] ?? 0);
        });
        foreach (array_slice($byCpu, 0, $topN) as $node) {
            $cpu = (int) ($node['excl_cpu_us'] ?? 0);
            if ($cpu < $cpuUs) {
                continue;
            }
            $findings[] = [
                'level' => 'warning',
                'title' => 'CPU 密集：' . (string) ($node['name'] ?? ''),
                'detail' => sprintf('自身 CPU 时间 %.2f ms，超过阈值 %.0f ms。', $cpu / 1000, (float) ($t['cpu_ms'] ?? 50.0)),
                'suggestions' => $this->tips('cpu'),
            ];
        }

        // 高频调用：按调用次数降序。
        $byCalls = $nodes;
        usort($byCalls, static function (array $a, array $b): int {
            return (int) ($b['calls'] ?? 0) <=> (int) ($a['calls'] ?? 0);
        });
        foreach (array_slice($byCalls, 0, $topN) as $node) {
            $calls = (int) ($node['calls'] ?? 0);
            if ($calls < $callsThreshold) {
                continue;
            }
            $findings[] = [
                'level' => 'warning',
                'title' => '高频调用（疑似循环 / N+1）：' . (string) ($node['name'] ?? ''),
                'detail' => sprintf('被调用 %d 次，建议检查是否可批量、缓存或提前计算。', $calls),
                'suggestions' => $this->tips('calls'),
            ];
        }

        // 内存增长：按 (mem_end - mem_start) 降序。
        $byMem = $nodes;
        usort($byMem, static function (array $a, array $b): int {
            $deltaA = (int) ($a['mem_end'] ?? 0) - (int) ($a['mem_start'] ?? 0);
            $deltaB = (int) ($b['mem_end'] ?? 0) - (int) ($b['mem_start'] ?? 0);
            return $deltaB <=> $deltaA;
        });
        foreach (array_slice($byMem, 0, $topN) as $node) {
            $delta = (int) ($node['mem_end'] ?? 0) - (int) ($node['mem_start'] ?? 0);
            if ($delta < $memBytes) {
                continue;
            }
            $findings[] = [
                'level' => 'warning',
                'title' => '内存增长明显：' . (string) ($node['name'] ?? ''),
                'detail' => sprintf('执行期间内存增加 %.1f MB，建议检查是否有大数组、大对象或未释放的引用。', $delta / 1048576),
                'suggestions' => $this->tips('mem_growth'),
            ];
        }
    }

    /**
     * Actionable optimisation suggestions for each finding kind.
     *
     * @return array<int, string>
     */
    private function tips(string $kind): array
    {
        $map = [
            'total_wall' => [
                '结合上方「慢方法」条目，优先优化自身耗时占比最高的方法。',
                '把非关键路径（通知、埋点、日志落盘等）改成异步 / 队列，缩短主请求链路。',
                '若存在串行的多次远程调用，评估是否可以并行或合并。',
            ],
            'blocking' => [
                '定位具体阻塞点（数据库 / Redis / 外部 HTTP / 锁），优先消除等待。',
                '把无依赖关系的 I/O 用协程并发执行（WaitGroup / parallel），而不是串行等待。',
                '减小锁粒度、缩短持锁时间，避免在锁内做 I/O。',
            ],
            'peak_mem' => [
                '检查是否有进程级静态 / 全局大变量长期驻留。',
                '大数据集改为流式 / 分批处理，避免一次性全部加载到内存。',
            ],
            'depth' => [
                '确认递归有明确的终止条件，并评估最坏深度。',
                '深度过大的递归改写为迭代或显式栈模拟。',
                '检查是否存在多层互相调用的耦合，扁平化调用链。',
            ],
            'slow' => [
                '优先排查方法内部的数据库查询 / 外部 HTTP 调用（通常是阻塞热点）。',
                '对结果稳定、调用频繁的方法加缓存（Redis / 本地缓存）。',
                '拆分职责过重的大方法，便于精确定位与复用。',
            ],
            'cpu' => [
                '检查循环内是否重复计算了相同表达式，可提到循环外。',
                '评估算法复杂度，考虑用哈希表 / 预计算 / 查表替代重复遍历。',
                '对纯计算且输入有限的结果做缓存或预计算。',
            ],
            'calls' => [
                '把循环内的单条查询改成一次批量查询（WHERE IN / 批量接口）。',
                '用预加载（join / with）替代逐条懒加载，消除 N+1。',
                '结果可复用时加缓存，避免反复请求。',
            ],
            'mem_growth' => [
                '检查是否构建了大数组 / 大对象（例如全量取出后再过滤）。',
                '大数据量改用分页 / 游标 / 流式处理。',
                '及时 unset 不再使用的临时变量与引用。',
            ],
            'none' => [
                '保持现状即可；建议仅在压测或上线前按需开启采样复核。',
            ],
        ];

        return $map[$kind] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function thresholds(): array
    {
        $user = Profiler::config()['analyze'] ?? [];

        return array_merge(self::DEFAULT_THRESHOLDS, is_array($user) ? $user : []);
    }

    private function toUs(float $ms): int
    {
        return (int) round($ms * 1000);
    }

    private function toBytes(float $mb): int
    {
        return (int) round($mb * 1024 * 1024);
    }
}
