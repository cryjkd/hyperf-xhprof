<?php

declare(strict_types=1);

namespace Cryjkd\HyperfXhprof;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Static entry point for the profiler.
 *
 * Per-coroutine state lives in the coroutine context (safe under Swoole);
 * configuration is immutable for the lifetime of a worker process and is
 * therefore cached in a static property after the first read.
 */
final class Profiler
{
    private const TRACE_KEY = 'fenxi.profiler.trace';

    private const SUSPEND_KEY = 'fenxi.profiler.suspend';

    /** @var array<string, mixed>|null */
    private static ?array $config = null;

    private static bool $configLoaded = false;

    /**
     * Open a new trace for the current coroutine (no-op when one is already open).
     */
    public static function start(?string $uri = null, ?string $method = null): Trace
    {
        $trace = self::trace();
        if (! $trace instanceof Trace) {
            $trace = new Trace(self::config());
            Context::set(self::TRACE_KEY, $trace);
        }

        if ($uri !== null) {
            $trace->uri = $uri;
        }
        if ($method !== null) {
            $trace->method = $method;
        }

        return $trace;
    }

    /**
     * Close the current trace, finalise its aggregates and return it.
     */
    public static function stop(): ?Trace
    {
        $trace = Context::get(self::TRACE_KEY);
        Context::destroy(self::TRACE_KEY);

        if ($trace instanceof Trace) {
            $trace->finish();
        }

        return $trace;
    }

    public static function trace(): ?Trace
    {
        $trace = Context::get(self::TRACE_KEY);

        return $trace instanceof Trace ? $trace : null;
    }

    /**
     * Whether a trace is currently open for this coroutine.
     */
    public static function enabled(): bool
    {
        return self::trace() instanceof Trace && self::suspendDepth() === 0;
    }

    /**
     * Whether a method with the given name should be recorded right now.
     */
    public static function shouldRecord(string $name): bool
    {
        $trace = self::trace();
        if (! $trace instanceof Trace || self::suspendDepth() > 0) {
            return false;
        }

        return $trace->shouldRecord($name);
    }

    public static function begin(string $name): void
    {
        $trace = self::trace();
        if ($trace instanceof Trace) {
            $trace->begin($name);
        }
    }

    public static function end(string $name): void
    {
        $trace = self::trace();
        if ($trace instanceof Trace) {
            $trace->end($name);
        }
    }

    /**
     * Temporarily disable recording for the current coroutine so the profiler
     * never records its own serialisation / rendering work.
     */
    public static function suspend(): void
    {
        Context::set(self::SUSPEND_KEY, self::suspendDepth() + 1);
    }

    public static function resume(): void
    {
        $depth = self::suspendDepth() - 1;
        if ($depth <= 0) {
            Context::destroy(self::SUSPEND_KEY);
        } else {
            Context::set(self::SUSPEND_KEY, $depth);
        }
    }

    /**
     * Decide whether to start profiling for the current HTTP request.
     */
    public static function shouldStart(ServerRequestInterface $request): bool
    {
        $config = self::config();
        if (empty($config['enable'])) {
            return false;
        }

        if (! empty($config['profile_all'])) {
            return true;
        }

        $trigger = $config['trigger'] ?? [];

        $header = $trigger['header'] ?? 'X-Profile';
        if ($header && self::truthy($request->getHeaderLine((string) $header))) {
            return true;
        }

        $query = $trigger['query'] ?? '_profile';
        if ($query && self::truthy((string) ($request->getQueryParams()[$query] ?? ''))) {
            return true;
        }

        $rate = (float) ($trigger['sample_rate'] ?? 0.0);
        if ($rate > 0.0 && (mt_rand() / mt_getrandmax()) < $rate) {
            return true;
        }

        return false;
    }

    /**
     * Decide whether to start profiling for the current WebSocket message.
     *
     * WebSocket has no per-message header/query trigger, so activation relies
     * on the `ws` config block (enable + sample_rate) and the global enable flag.
     */
    public static function shouldStartWs(): bool
    {
        $config = self::config();
        if (empty($config['enable'])) {
            return false;
        }

        $ws = $config['ws'] ?? [];
        if (empty($ws['enable'])) {
            return false;
        }

        $rate = (float) ($ws['sample_rate'] ?? 1.0);
        if ($rate >= 1.0) {
            return true;
        }

        return $rate > 0.0 && (mt_rand() / mt_getrandmax()) < $rate;
    }

    /**
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        if (self::$configLoaded) {
            return self::$config ?? [];
        }

        self::$configLoaded = true;
        $config = Context::config('profiler');
        self::$config = is_array($config) ? $config : [];

        return self::$config;
    }

    private static function suspendDepth(): int
    {
        return (int) Context::get(self::SUSPEND_KEY, 0);
    }

    private static function truthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
    }
}
