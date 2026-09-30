<?php

declare(strict_types=1);

namespace Cryjkd\HyperfXhprof;

use Cryjkd\HyperfXhprof\Aspect\ProfilerAspect;
use Cryjkd\HyperfXhprof\Aspect\WsProfilerAspect;
use Cryjkd\HyperfXhprof\Command\ProfilerCommand;
use Cryjkd\HyperfXhprof\Middleware\ProfilerMiddleware;

class ConfigProvider
{
    public function __invoke(): array
    {
        $config = [
            'aspects' => [
                ProfilerAspect::class,
                WsProfilerAspect::class,
            ],
            'middlewares' => [
                'http' => [
                    ProfilerMiddleware::class,
                ],
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The config for hyperf-xhprof profiler.',
                    'source' => __DIR__ . '/../publish/profiler.php',
                    'destination' => BASE_PATH . '/config/autoload/profiler.php',
                ],
            ],
        ];

        // Only register the command when the console stack is present.
        if (class_exists(\Hyperf\Command\Command::class)) {
            $config['commands'] = [
                ProfilerCommand::class,
            ];
        }

        return $config;
    }
}
