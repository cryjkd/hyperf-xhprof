<?php

declare(strict_types=1);

namespace Cryjkd\HyperfXhprof\Ai;

/**
 * Builds a self-contained analysis prompt from a Trace::toArray() payload.
 *
 * Pure function: no container / config / network dependency, so it can be used
 * to pre-render the prompt inside the HTML report. The user can copy the prompt
 * into any AI chat (DeepSeek / Kimi / ChatGPT ...) for a deeper analysis.
 */
final class PromptBuilder
{
    /**
     * @param array<string, mixed> $data
     */
    public function buildPrompt(array $data): string
    {
        $nodes = is_array($data['nodes'] ?? null) ? $data['nodes'] : [];
        usort($nodes, static function (array $a, array $b): int {
            return (int) ($b['wall_us'] ?? 0) <=> (int) ($a['wall_us'] ?? 0);
        });

        $lines = [];
        $lines[] = '你是资深 PHP / Swoole / Hyperf 性能优化专家。下面是某一次请求的性能采样数据，请分析性能瓶颈并给出具体、可落地的优化方案。';
        $lines[] = '';
        $lines[] = '## 采样概况';
        $lines[] = sprintf(
            '请求：%s %s',
            (string) ($data['method'] ?? ''),
            (string) ($data['uri'] ?? '')
        );
        $lines[] = sprintf(
            '总耗时 %.2f ms ｜ CPU %.2f ms ｜ 峰值内存 %.1f MB ｜ 函数数 %d ｜ 最大调用深度 %d',
            (int) ($data['wall_us'] ?? 0) / 1000,
            (int) ($data['cpu_us'] ?? 0) / 1000,
            (int) ($data['peak_mem'] ?? 0) / 1048576,
            count($nodes),
            (int) ($data['max_depth'] ?? 0)
        );
        $lines[] = '';
        $lines[] = '## 函数耗时 Top（含子调用 / 自身 / 调用次数 / CPU / 内存增量）';

        foreach (array_slice($nodes, 0, 20) as $i => $node) {
            $name = (string) ($node['name'] ?? '');
            $wallUs = (int) ($node['wall_us'] ?? 0);
            $exclUs = (int) ($node['excl_wall_us'] ?? 0);
            $calls = (int) ($node['calls'] ?? 0);
            $cpuUs = (int) ($node['cpu_us'] ?? 0);
            $memDelta = (int) ($node['mem_end'] ?? 0) - (int) ($node['mem_start'] ?? 0);
            $lines[] = sprintf(
                '%d. %s — incl %.2f ms / excl %.2f ms / calls %d / cpu %.2f ms / mem %+.1f MB',
                $i + 1,
                $name,
                $wallUs / 1000,
                $exclUs / 1000,
                $calls,
                $cpuUs / 1000,
                $memDelta / 1048576
            );
        }

        $lines[] = '';
        $lines[] = '## 请按以下结构输出（Markdown）';
        $lines[] = '1. 总体判断';
        $lines[] = '2. 主要瓶颈（按影响排序，逐个说明原因）';
        $lines[] = '3. 针对性的优化建议（具体、可落地，最好给出方向或示例代码）';
        $lines[] = '4. 潜在风险与注意事项';

        return implode("\n", $lines);
    }
}
