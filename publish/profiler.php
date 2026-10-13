<?php

declare(strict_types=1);

return [
    // Master switch. When false the profiler is fully disabled (no per-request overhead).
    'enable' => false,

    // When true (and enable is true) every request is profiled, ignoring triggers.
    'profile_all' => false,

    // Per-request activation triggers.
    'trigger' => [
        // Trigger through an HTTP header, e.g. `X-Profile: 1`.
        'header' => 'X-Profile',
        // Trigger through a query string, e.g. `?_profile=1`.
        'query' => '_profile',
        // Randomly profile this fraction of requests (0.0 - 1.0).
        'sample_rate' => 0.0,
    ],

    // WebSocket per-message profiling (see the `#[ProfileWs]` annotation).
    'ws' => [
        // Master switch for WebSocket profiling (global `enable` must also be true).
        'enable' => false,
        // Fraction of messages to sample (1.0 = every message, 0.1 = 10%).
        'sample_rate' => 1.0,
    ],

    // What to collect.
    'collect' => [
        // CPU time via getrusage() (process-wide on Swoole, therefore approximate).
        'cpu' => true,
        // Memory usage via memory_get_usage().
        'memory' => true,
        // 0 = unlimited; >0 caps the call-tree depth (flat totals stay exact).
        'max_depth' => 0,
    ],

    // Class::method names or wildcard patterns that are always skipped.
    // Example: ['App\\Service\\HealthCheck*'].
    'exclude' => [],

    // Optimization advice / anomaly thresholds used by the "优化建议" section.
    // Times are milliseconds, memory is megabytes.
    'analyze' => [
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
    ],

    // Report storage.
    'storage' => [
        'path' => BASE_PATH . '/runtime/profiler',
        // Maximum number of reports to keep (0 = unlimited).
        'keep' => 100,
    ],
];
