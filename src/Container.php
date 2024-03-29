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

use Psr\Container\{ContainerInterface, NotFoundExceptionInterface};
use Rade\DI\Exceptions\{CircularReferenceException, ContainerResolutionException, NotFoundServiceException};
use Symfony\Contracts\Service\ResetInterface;

/**
 * Dependency injection container.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class Container implements \ArrayAccess, ContainerInterface, ResetInterface
{
    use Traits\ParameterTrait, Traits\DefinitionTrait, Traits\TagsTrait, Traits\TypesTrait, Traits\ExtensionTrait;

    /** @final The reserved service id for container's instance */
    public const SERVICE_CONTAINER = 'container';

    /** Sets the behaviour to ignore exception on types with multiple services */
    public const IGNORE_MULTIPLE_SERVICE = 0;

    /** Set a strict behaviour to thrown an exception on types with multiple services */
    public const EXCEPTION_ON_MULTIPLE_SERVICE = 1;

    /** Instead of throwing an exception, null will return if service not found */
    public const NULL_ON_INVALID_SERVICE = 2;

    /** @var null|\WeakMap<ContainerInterface,true> A list of PSR-11 containers */
    protected ?\WeakMap $containers = null;

    public function __construct()
    {
        if (!isset($this->types[$cl = static::class])) {
            $this->type(self::SERVICE_CONTAINER, ...\array_keys(\class_implements($c = $this) + \class_parents($c) + [$cl => $cl]));
        }
        $this->resolver = new Resolver($this->services[self::SERVICE_CONTAINER] = $c ?? $this);
    }

    /**
     * Container can not be cloned.
     */
    public function __clone()
    {
        throw new \LogicException('Container is not cloneable');
    }

    /**
     * Alias of the container resolver's resolve method.
     *
     * @param array<int|string,mixed> $args
     */
    public function __invoke(mixed $value, array $args = []): mixed
    {
        return $this->resolver->resolve($value, $args);
    }

    /**
     * Sets a new service to a unique identifier.
     *
     * @param string $offset The unique identifier for the parameter or object
     * @param mixed  $value  The value of the service assign to the $offset
     *
     * @throws Exceptions\FrozenServiceException Prevent override of a frozen service
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->autowire($offset, $value);
    }

    /**
     * Gets a registered service definition.
     *
     * @param string $offset The unique identifier for the service
     *
     * @throws NotFoundServiceException If the identifier is not defined
     *
     * @return mixed The value of the service
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }

    /**
     * Checks if a service is set.
     *
     * @param string $offset The unique identifier for the service
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->has($offset);
    }

    /**
     * Unset a service by given offset.
     *
     * @param string $offset The unique identifier for service definition
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->removeDefinition($offset);
    }

    /**
     * Resets the container.
     */
    public function reset(): void
    {
        foreach ($this->definitions as $id => $service) {
            $service = $this->services[$id] ?? $service;

            if ($service instanceof ResetInterface) {
                $service->reset();
            }
            $this->removeDefinition($id);
        }

        foreach ($this->containers ?? [] as $container => $true) {
            if ($container instanceof ResetInterface) {
                $container->reset(); // A container such as Symfony DI support reset
            }
        }

        $c = $this->services[self::SERVICE_CONTAINER];
        $t = $this->typed(self::SERVICE_CONTAINER, true);
        $this->services = $this->types = $this->tags = $this->aliases = [];

        $this->services[self::SERVICE_CONTAINER] = $c;
        $this->type(self::SERVICE_CONTAINER, ...$t);
    }

    /**
     * Attach an existing container, useful for migration purposes.
     */
    public function attach(ContainerInterface $container): void
    {
        if (null === $this->containers) {
            $this->containers = new \WeakMap();
        }
        $this->containers[$container] = true;
    }

    /**
     * Detach an existing container if it's no longer needed.
     */
    public function detach(ContainerInterface $container): void
    {
        if (null !== $this->containers) {
            unset($this->containers[$container]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $id): bool
    {
        if (static::SERVICE_CONTAINER === $id) {
            return true;
        }

        if (false !== ($this->aliases[$id] ?? $this->methodsMap[$id] ?? \array_key_exists($id, $this->definitions))) {
            return true;
        }

        foreach ($this->containers ?? [] as $container => $true) {
            if ($container->has($id)) {
                return $true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     *
     * @throws CircularReferenceException When a circular reference is detected
     * @throws NotFoundServiceException   When the service is not defined
     */
    public function get(string $id, int $invalidBehavior = /* self::EXCEPTION_ON_MULTIPLE_SERVICE */ 1): mixed
    {
        return $this->services[$id]
            ?? $this->services[$id = $this->aliases[$id] ?? $id]
            ?? $this->{$this->methodsMap[$id] ?? 'doLoad'}($id, $invalidBehavior);
    }

    /**
     * Invokes given closure or function withing specific container scope.
     * By default, container is passed into callback arguments.
     *
     * Example:
     * ```php
     * $container->autowire('movie', new Movie('Baby Driver 2023'));
     * $container->runScope(
     *    ['actor', 'director'],
     *    function (ContainerInterface $container, Movie $movie) {
     *        $container->set('director', new Director('Edgar Wright'));
     *        $container->set('actor', new Actor('John Doe'));
     *
     *        $movie->addActor($container->get('actor'));
     *        $movie->setDirector($container->get('director'));
     *
     *        return $movie;
     *    }
     * );
     * ```
     *
     * This makes the service private and cannot be use elsewhere in codebase.
     *
     * @param array<int,string> $services
     *
     * @throws ContainerResolutionException if a service id exists
     */
    public function runScope(array $services, callable $scope): mixed
    {
        $ref = new \ReflectionFunction(\Closure::fromCallable($scope));

        foreach ($services as $serviceId) {
            if ($this->has($serviceId)) {
                throw new ContainerResolutionException(\sprintf('Service with id "%s" exist in container and cannot be redeclared.', $serviceId));
            }
        }

        try {
            return 0 === $ref->getNumberOfParameters() ? $ref->invoke() : $ref->invokeArgs($this->resolver->autowireArguments($ref));
        } finally {
            foreach ($services as $alias) {
                if (!$this->has($alias)) {
                    throw new NotFoundServiceException(\sprintf('Service with id "%s" was not found, cannot remove it.', $alias));
                }
                $this->removeDefinition($alias);
            }
        }
    }

    /**
     * Return a list of definitions belonging to a type or tag.
     *
     * @return array<int,string> The list of service definitions ids
     */
    public function findBy(string $typeOrTag, callable $resolve = null): array
    {
        if (\array_key_exists($typeOrTag, $this->tags)) {
            $tags = \array_keys($this->tags[$typeOrTag]);
        }
        $definitions = $tags ?? $this->types[$typeOrTag] ?? [];

        return null === $resolve ? $definitions : \array_map($resolve, $definitions);
    }

    /**
     * Load the service definition.
     */
    protected function doLoad(string $id, int $invalidBehavior): mixed
    {
        if (null == ($definition = $this->definitions[$id] ?? null)) {
            if (\array_key_exists($id, $this->types)) {
                return $this->autowired($id, 1 === ($invalidBehavior & self::EXCEPTION_ON_MULTIPLE_SERVICE));
            }

            foreach ($this->containers ?? [] as $container => $true) {
                if ($container->has($id)) {
                    try {
                        return $container->get($id);
                    } catch (NotFoundExceptionInterface $e) {
                        // Skip error ...
                    }
                }
            }

            return 2 === ($invalidBehavior & self::NULL_ON_INVALID_SERVICE) ? null : throw $this->createNotFound($id, $e ?? null);
        }

        if ($definition->isAbstract()) {
            throw new ContainerResolutionException(\sprintf('Abstract definition "%s" cannot be instantiated.', $id));
        }

        try {
            $this->loading[$id] = !isset($this->loading[$id]) ? true : throw new CircularReferenceException($id, [...\array_keys($this->loading), $id]);
            $service = $definition->resolve($this->resolver);

            return !$definition->isShared() ? $service : $this->services[$id] = $service;
        } finally {
            unset($this->loading[$id]);

            if (!$definition->isPublic() && !$this instanceof ContainerBuilder) {
                $this->removeDefinition($id);
            }
        }
    }
}
