<?php

declare(strict_types=1);

namespace Cryjkd\HyperfXhprof\Annotation;

use Attribute;
use Hyperf\Di\Annotation\AbstractAnnotation;

/**
 * Marks a class (all its public methods) or a single method for profiling.
 *
 * Usage — PHP 8.0+ (native attribute, preferred):
 *   #[Profile]
 *   class FooService { ... }
 *
 * Usage — PHP 7.4 / Hyperf 2.2 (docblock annotation):
 *   use Cryjkd\HyperfXhprof\Annotation\Profile;
 *   // 类或方法 docblock 里写 @Profile
 *
 * The class declares both the `@Annotation` docblock marker and the native
 * `#[Attribute]` marker, exactly like Hyperf 2.2's own annotations, so a single
 * file is recognised on PHP 7.4 (docblock) and PHP 8.0+ (attribute).
 *
 * @Annotation
 * @Target({"CLASS", "METHOD"})
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Profile extends AbstractAnnotation
{
}
