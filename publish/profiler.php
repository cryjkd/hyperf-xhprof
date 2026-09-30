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

    // Report storage.
    'storage' => [
        'path' => BASE_PATH . '/runtime/profiler',
        // Maximum number of reports to keep (0 = unlimited).
        'keep' => 100,
    ],
];
