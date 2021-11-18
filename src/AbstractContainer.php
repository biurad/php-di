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

use Rade\DI\Exceptions\{CircularReferenceException, ContainerResolutionException, NotFoundServiceException};
use Symfony\Contracts\Service\ResetInterface;

/**
 * Internal shared container.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
abstract class AbstractContainer implements ContainerInterface, ResetInterface
{
    use Traits\ParameterTrait;
    use Traits\DefinitionTrait;
    use Traits\TagsTrait;
    use Traits\TypesTrait;

    /** @var array<string,bool> service name => bool */
    protected array $loading = [];

    public function __construct()
    {
        if ($this instanceof ContainerBuilder) {
            $builderFactory = new \PhpParser\BuilderFactory();
        }

        $this->resolver = new Resolvers\Resolver($this, $builderFactory ?? null);
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
     */
    public function get(string $id, int $invalidBehavior = /* self::EXCEPTION_ON_MULTIPLE_SERVICE */ 1)
    {
        if (\array_key_exists($id, $this->loading)) {
            throw new CircularReferenceException($id, [...\array_keys($this->loading), $id]);
        }

        $this->loading[$id] = true; // Checking if circular reference exists ...

        try {
            if (isset($this->methodsMap, $this->methodsMap[$id])) {
                return $this->{$this->methodsMap[$id]}();
            }

            if (true === $definition = $this->definitions[$id] ?? \array_key_exists($id, $this->types)) {
                return $this->autowired($id, self::EXCEPTION_ON_MULTIPLE_SERVICE === $invalidBehavior);
            }

            return $this->doCreate($id, $definition, $invalidBehavior);
        } finally {
            unset($this->loading[$id]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $id): bool
    {
        return static::SERVICE_CONTAINER === $id || \array_key_exists($this->aliases[$id] ?? $id, $this->definitions);
    }

    /**
     * Return true if service has been loaded for a possible circular reference error.
     *
     * @param string $id The service identifier
     *
     * @return bool if service has already been initialized, false otherwise
     */
    public function created(string $id): bool
    {
        return $this->loading[$id] ?? false;
    }

    /**
     * Invokes given closure or function withing specific container scope.
     * By default, container is passed into callback arguments.
     *
     * Example:
     * ```php
     * $container->runScope(['actor' => new Actor()], function (ContainerInterface $container, Actor $actor) {
     *    assert($container->get('actor') instanceof $actor);
     *
     *    return $actor;
     * });
     * ```
     *
     * This makes the service private and cannot be use elsewhere in codebase.
     *
     * @param array<string,mixed> $services
     *
     * @throws ContainerResolutionException if a service id exists
     *
     * @return mixed
     */
    public function runScope(array $services, callable $scope)
    {
        $cleanup = [];

        foreach ($services as $serviceId => $definition) {
            if ($this->has($serviceId)) {
                throw new ContainerResolutionException(\sprintf('Service with id "%s" exist in container and cannot be used using container\'s runScope method.', $serviceId));
            }

            $this->set($cleanup[] = $serviceId, $definition);
        }

        try {
            $ref = new \ReflectionFunction($scope);

            return $ref->invokeArgs($this->resolver->autowireArguments($ref));
        } finally {
            foreach ($cleanup as $alias) {
                $this->removeDefinition($alias);
            }
        }
    }

    /**
     * Alias of the container resolver's resolve method.
     *
     * @param mixed                   $value
     * @param array<int|string,mixed> $args
     *
     * @return mixed
     */
    public function call($value, array $args = [])
    {
        return $this->resolver->resolve($value, $args);
    }

    /**
     * Return a list of definitions belonging to a type or tag.
     *
     * @return array<int,string> The list of service definitions ids
     */
    public function findBy(string $typeOrTag, callable $resolve = null): array
    {
        if (\array_key_exists($typeOrTag, $this->tags)) {
            return null === $resolve ? \array_keys($this->tags[$typeOrTag]) : \array_map($resolve, \array_keys($this->tags[$typeOrTag]));
        }

        return null === $resolve ? $this->types[$typeOrTag] ?? [] : \array_map($resolve, $this->types[$typeOrTag] ?? []);
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

        $this->services = $this->types = $this->tags = $this->aliases = [];
    }

    /**
     * @param mixed $definition
     *
     * @throws NotFoundServiceException
     */
    abstract protected function doCreate(string $id, $definition, int $invalidBehavior);
}
