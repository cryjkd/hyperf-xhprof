<?php

declare(strict_types=1);

namespace Cryjkd\HyperfXhprof\Aspect;

use Cryjkd\HyperfXhprof\Annotation\Profile;

/**
 * Default aspect: profiles every class/method annotated with `#[Profile]`.
 *
 * Registered through the ConfigProvider so it behaves identically on Hyperf
 * 2.2 and 3.1 without depending on the version-specific #[Aspect] annotation.
 */
final class ProfilerAspect extends AbstractProfilerAspect
{
    public $annotations = [
        Profile::class,
    ];
}
