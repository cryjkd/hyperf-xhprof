<?php

declare(strict_types=1);

namespace Cryjkd\HyperfXhprof\Aspect;

use Cryjkd\HyperfXhprof\Profiler;
use Hyperf\Di\Aop\AroundInterface;
use Hyperf\Di\Aop\ProceedingJoinPoint;

/**
 * Base aspect that wraps a method invocation with wall/cpu/memory sampling.
 *
 * Extend it and set `$classes` (wildcard patterns such as `App\Service\*`)
 * and/or `$annotations` when you want to profile by class instead of (or in
 * addition to) the `#[Profile]` attribute. Register your subclass in
 * `config/autoload/aspects.php`.
 *
 * NOTE: this class deliberately implements AroundInterface instead of
 * extending Hyperf's AbstractAspect. Hyperf 2.2 declares those properties
 * untyped while Hyperf 3.1 declares them typed, so redeclaring `$annotations`
 * in a subclass would trip PHP's typed-property invariance check on one of the
 * two lines. Owning the properties here keeps both lines compatible.
 */
abstract class AbstractProfilerAspect implements AroundInterface
{
    public $classes = [];

    public $annotations = [];

    public $priority = null;

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
