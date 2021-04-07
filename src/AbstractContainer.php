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

namespace Rade\DI;

use Psr\Container\ContainerInterface;
use Rade\DI\Exceptions\{
    CircularReferenceException, ContainerResolutionException, NotFoundServiceException
};
use Symfony\Contracts\Service\ResetInterface;

/**
 * Internal shared container.
 *
 * @method call($callback, array $args = [])
 *      Resolve a service definition, class string, invocable object or callable using autowiring.
 * @method resolveClass(string $class, array $args = []) Resolves a class string.
 * @method autowire(string $id, array $types) Resolve wiring classes + interfaces to service id.
 * @method exclude(string $type) Exclude an interface or class type from being autowired.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
abstract class AbstractContainer implements ContainerInterface, ResetInterface
{
    public const IGNORE_MULTIPLE_SERVICE = 0;

    public const EXCEPTION_ON_MULTIPLE_SERVICE = 1;

    /** @var array<string,mixed> For handling a global config around services */
    public array $parameters = [];

    /** @var array<string,mixed> A list of already loaded services (this act as a local cache) */
    protected static array $services;

    /** @var string[] alias => service name */
    protected array $aliases = [];

    /** @var array[] tag name => service name => tag value */
    private array $tags = [];

    public function __construct()
    {
        self::$services = [];
    }

    /**
     * Container can not be cloned.
     */
    public function __clone()
    {
        throw new \LogicException('Container is not cloneable');
    }
    /**
     * {@inheritdoc}
     *
     * @param string $id              Identifier of the entry to look for.
     * @param int    $invalidBehavior The behavior when multiple services returns for $id
     */
    abstract public function get(string $id, int $invalidBehavior = /* self::EXCEPTION_ON_MULTIPLE_SERVICE */ 1);

    /**
     * {@inheritdoc}
     */
    abstract public function has(string $id): bool;

    /**
     * Returns all defined value names.
     *
     * @return string[] An array of value names
     */
    abstract public function keys(): array;

    /**
     * Resets the container
     */
    public function reset(): void
    {

    /**
     * Marks an alias id to service id.
     *
     * @param string $id The alias id
     * @param string $serviceId The registered service id
     *
     * @throws ContainerResolutionException Service id is not found in container
     */
    public function alias(string $id, string $serviceId): void
    {
        if ($id === $serviceId) {
            throw new \LogicException("[{$id}] is aliased to itself.");
        }

        if (!$this->has($serviceId)) {
            throw new ContainerResolutionException("Service id '{$serviceId}' is not found in container");
        }

        $this->aliases[$id] = $this->aliases[$serviceId] ?? $serviceId;
    }

    /**
     * Assign a set of tags to service(s).
     *
     * @param string[]|string         $serviceIds
     * @param array<int|string,mixed> $tags
     */
    public function tag($serviceIds, array $tags): void
    {
        foreach ((array) $serviceIds as $service) {
            foreach ($tags as $tag => $attributes) {
                // Exchange values if $tag is an integer
                if (\is_int($tmp = $tag)) {
                    $tag = $attributes;
                    $attributes = $tmp;
                }

                $this->tags[$service][$tag] = $attributes;
            }
        }
    }

    /**
     * Resolve all of the bindings for a given tag.
     *
     * @param string $tag
     *
     * @return mixed[] of [service, attributes]
     */
    public function tagged(string $tag): array
    {
        $tags = [];

        foreach ($this->tags as $service => $tagged) {
            if (isset($tagged[$tag])) {
                $tags[] = [$this->get($service), $tagged[$tag]];
            }
        }

        return $tags;
    }

    }
}
