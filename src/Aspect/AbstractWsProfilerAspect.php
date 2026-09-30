<?php

declare(strict_types=1);

namespace Cryjkd\HyperfXhprof\Aspect;

use Cryjkd\HyperfXhprof\Profiler;
use Cryjkd\HyperfXhprof\Report\ReportWriter;
use Cryjkd\HyperfXhprof\Trace;
use Hyperf\Di\Aop\AroundInterface;
use Hyperf\Di\Aop\ProceedingJoinPoint;

/**
 * Base aspect for WebSocket per-message profiling.
 *
 * It wraps a message handler (typically onMessage): opens a trace before the
 * message is processed and saves the report afterwards. Extend it and set
 * `$classes` wildcards when you want to profile by class instead of using the
 * `#[ProfileWs]` attribute.
 *
 * NOTE: implements AroundInterface directly (instead of extending AbstractAspect)
 * to avoid Hyperf 2.2 / 3.1 typed-property variance — see AbstractProfilerAspect.
 */
abstract class AbstractWsProfilerAspect implements AroundInterface
{
    public $classes = [];

    public $annotations = [];

    public $priority = null;

    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        $name = $proceedingJoinPoint->className . '::' . $proceedingJoinPoint->methodName;
        $alreadyProfiling = Profiler::trace() instanceof Trace;

        // Fast path: no per-message overhead when profiling is disabled.
        if (! $alreadyProfiling && ! Profiler::shouldStartWs()) {
            return $proceedingJoinPoint->process();
        }

        if (! $alreadyProfiling) {
            Profiler::start('ws:' . $name, 'WS');
        }

        Profiler::begin($name);
        try {
            return $proceedingJoinPoint->process();
        } finally {
            Profiler::end($name);
            if (! $alreadyProfiling) {
                $trace = Profiler::stop();
                if ($trace instanceof Trace && $trace->hasData()) {
                    $this->saveReport($trace);
                }
            }
        }
    }

    private function saveReport(Trace $trace): void
    {
        Profiler::suspend();
        try {
            (new ReportWriter())->save($trace);
        } catch (\Throwable $throwable) {
            // Profiling must never break the WebSocket message flow.
        } finally {
            Profiler::resume();
        }
    }
}
