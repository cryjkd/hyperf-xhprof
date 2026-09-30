<?php

declare(strict_types=1);

namespace Cryjkd\HyperfXhprof\Middleware;

use Cryjkd\HyperfXhprof\Profiler;
use Cryjkd\HyperfXhprof\Report\ReportWriter;
use Cryjkd\HyperfXhprof\Trace;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Starts a trace when a trigger matches the request, saves the report and
 * attaches an `X-Profile-Id` header pointing at the generated report.
 */
final class ProfilerMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $started = Profiler::shouldStart($request);

        if ($started) {
            Profiler::start($request->getUri()->getPath(), $request->getMethod());
        }

        $profileId = null;
        try {
            $response = $handler->handle($request);
        } finally {
            $trace = Profiler::stop();
            if ($started && $trace instanceof Trace && $trace->hasData()) {
                Profiler::suspend();
                try {
                    $profileId = (new ReportWriter())->save($trace);
                } finally {
                    Profiler::resume();
                }
            }
        }

        if ($profileId !== null) {
            $response = $response->withHeader('X-Profile-Id', $profileId);
        }

        return $response;
    }
}
