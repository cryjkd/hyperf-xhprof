<?php

declare(strict_types=1);

namespace Cryjkd\HyperfXhprof\Aspect;

use Cryjkd\HyperfXhprof\Annotation\ProfileWs;

/**
 * Default WebSocket aspect: opens a per-message trace for every method
 * annotated with `#[ProfileWs]` (typically the onMessage handler).
 *
 * Registered through the ConfigProvider so it behaves identically on Hyperf
 * 2.2 and 3.1.
 */
final class WsProfilerAspect extends AbstractWsProfilerAspect
{
    public $annotations = [
        ProfileWs::class,
    ];
}
