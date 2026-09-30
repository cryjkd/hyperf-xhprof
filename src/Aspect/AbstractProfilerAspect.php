<?php

declare(strict_types=1);

namespace Cryjkd\HyperfXhprof\Aspect;

use Cryjkd\HyperfXhprof\Profiler;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;

/**
 * Base aspect that wraps a method invocation with wall/cpu/memory sampling.
 *
 * Extend it and set `$classes` (wildcard patterns such as `App\Service\*`)
 * and/or `$annotations` when you want to profile by class instead of (or in
 * addition to) the `#[Profile]` attribute. Register your subclass in
 * `config/autoload/aspects.php`.
 */
abstract class AbstractProfilerAspect extends AbstractAspect
{
    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        $name = $proceedingJoinPoint->className . '::' . $proceedingJoinPoint->methodName;

        if (! Profiler::shouldRecord($name)) {
            return $proceedingJoinPoint->process();
        }

        Profiler::begin($name);
        try {
            return $proceedingJoinPoint->process();
        } finally {
            Profiler::end($name);
        }
    }
}
