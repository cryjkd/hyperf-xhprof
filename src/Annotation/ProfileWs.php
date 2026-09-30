<?php

declare(strict_types=1);

namespace Cryjkd\HyperfXhprof\Annotation;

use Attribute;
use Hyperf\Di\Annotation\AbstractAnnotation;

/**
 * Marks a WebSocket `onMessage` method as a per-message profiling window.
 *
 * The WsProfilerAspect wraps the annotated method, opening a trace before the
 * message is handled and saving the report afterwards. Put #[Profile] on the
 * inner business methods to get a function-level breakdown.
 *
 * Usage — PHP 8.0+ (native attribute):
 *   #[ProfileWs]
 *   public function onMessage($server, Frame $frame): void { ... }
 *
 * Usage — PHP 7.4 / Hyperf 2.2 (docblock annotation):
 *   use Cryjkd\HyperfXhprof\Annotation\ProfileWs;
 *   // 方法 docblock 里写 @ProfileWs
 *
 * @Annotation
 * @Target({"METHOD"})
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class ProfileWs extends AbstractAnnotation
{
}
