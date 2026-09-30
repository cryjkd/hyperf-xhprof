<?php

declare(strict_types=1);

namespace Cryjkd\HyperfXhprof;

use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;

/**
 * Cross-version runtime adapter.
 *
 * Hyperf 2.2 exposes the coroutine context and the application container as
 * `Hyperf\Utils\Context` / `Hyperf\Utils\ApplicationContext`, while Hyperf 3.x
 * moved them to `Hyperf\Context\Context` / `Hyperf\Context\ApplicationContext`.
 * This adapter picks whichever implementation is present so that one package
 * works against both lines without conditional code elsewhere.
 */
final class Context
{
    public static function set(string $id, $value): void
    {
        $class = self::contextClass();
        $class::set($id, $value);
    }

    public static function get(string $id, $default = null)
    {
        $class = self::contextClass();
        return $class::get($id, $default);
    }

    public static function has(string $id): bool
    {
        $class = self::contextClass();
        return $class::has($id);
    }

    public static function destroy(string $id): void
    {
        $class = self::contextClass();
        $class::destroy($id);
    }

    /**
     * Read an application config value; return the whole config object when $key is null.
     */
    public static function config(?string $key = null, $default = null)
    {
        $container = self::container();
        if ($container === null || ! $container->has(ConfigInterface::class)) {
            return $default;
        }

        /** @var ConfigInterface $config */
        $config = $container->get(ConfigInterface::class);

        if ($key === null) {
            return $config;
        }

        return $config->get($key, $default);
    }

    public static function container(): ?ContainerInterface
    {
        $class = self::applicationContextClass();
        if ($class === null) {
            return null;
        }

        $container = $class::getContainer();

        return $container instanceof ContainerInterface ? $container : null;
    }

    private static function contextClass(): string
    {
        if (class_exists(\Hyperf\Context\Context::class)) {
            return \Hyperf\Context\Context::class;
        }

        if (class_exists(\Hyperf\Utils\Context::class)) {
            return \Hyperf\Utils\Context::class;
        }

        throw new \RuntimeException('Neither Hyperf\\Context\\Context nor Hyperf\\Utils\\Context is available.');
    }

    private static function applicationContextClass(): ?string
    {
        if (class_exists(\Hyperf\Context\ApplicationContext::class)) {
            return \Hyperf\Context\ApplicationContext::class;
        }

        if (class_exists(\Hyperf\Utils\ApplicationContext::class)) {
            return \Hyperf\Utils\ApplicationContext::class;
        }

        return null;
    }
}
