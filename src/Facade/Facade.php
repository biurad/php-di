<?php

declare(strict_types=1);

/*
 * This file is part of DivineNii opensource projects.
 *
 * PHP version 7.4 and above required
 *
 * @author    Divine Niiquaye Ibok <divineibok@gmail.com>
 * @copyright 2021 DivineNii (https://divinenii.com/)
 * @license   https://opensource.org/licenses/BSD-3-Clause License
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Rade\DI\Facade;

use Psr\Container\ContainerInterface;
use Rade\DI\{Container, Exceptions\ContainerResolutionException};

/**
 * Represents a Static Proxy logic using `__callStatic()`.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class Facade
{
    /**
     * @var array<string,string>
     *
     * @internal Do not use this property directly.
     */
    public static array $proxies = [];

    protected static ContainerInterface $container;

    /**
     * Sets the Container that will be used to retrieve the Proxies.
     */
    public static function setContainer(ContainerInterface $container): void
    {
        static::$container = $container;
    }

    /**
     * Performs the proxying of the statically called method from the container.
     *
     * @param array<int|string,mixed> $arguments
     *
     * @return mixed
     */
    public static function __callStatic(string $name, array $arguments = [])
    {
        if (!\array_key_exists($name, self::$proxies)) {
            throw new ContainerResolutionException(\sprintf('Subject "%s" is not a supported proxy service.', $name));
        }
        $di = self::$container;

        if (\is_callable($service = $di->get(self::$proxies[$name]))) {
            return $di instanceof Container ? $di->call($service, $arguments) : \call_user_func_array($service, $arguments);
        }

        return $service;
    }
}
