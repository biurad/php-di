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

use Rade\DI\Definitions\DefinitionInterface;
use Rade\DI\Exceptions\{CircularReferenceException, ContainerResolutionException};
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
    use Traits\ProviderTrait;

    /** @var array<string,bool> service name => bool */
    protected array $loading = [];

    public function __construct()
    {
        foreach (\class_parents($this, false) as $type) {
            if (\is_subclass_of($type, ContainerBuilder::class)) {
                continue;
            }
            $this->types[$type] = [self::SERVICE_CONTAINER];
        }

        if ($this instanceof Container) {
            $this->types[static::class] = [self::SERVICE_CONTAINER];
        } elseif ($this instanceof ContainerBuilder) {
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
    abstract public function get(string $id, int $invalidBehavior = /* self::EXCEPTION_ON_MULTIPLE_SERVICE */ 1);

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
     * @return array<int,Definitions\DefinitionInterface|object>
     */
    public function findBy(string $typeOrTag): array
    {
        $definitions = [];

        if (isset($this->tags[$typeOrTag])) {
            foreach (\array_keys($this->tags[$typeOrTag]) as $serviceId) {
                $definitions[] = $this->definition($serviceId);
            }
        } elseif (isset($this->types[$typeOrTag])) {
            foreach ($this->types[$typeOrTag] as $serviceId) {
                $definitions[] = $this->definition($serviceId);
            }
        }

        return $definitions;
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
     */
    abstract protected function doCreate(string $id, $definition, int $invalidBehavior);

    /**
     * Build an entry of the container by its identifier.
     *
     * @throws CircularReferenceException|NotFoundServiceException
     *
     * @return mixed
     */
    protected function doGet(string $id, int $invalidBehavior)
    {
        if (\array_key_exists($id, $this->loading)) {
            throw new CircularReferenceException($id, [...\array_keys($this->loading), $id]);
        }

        $definition = $this->definitions[$id] ?? (isset($this->types[$id]) ? $this->autowired($id, self::EXCEPTION_ON_MULTIPLE_SERVICE === $invalidBehavior) : null);

        if (!($definition instanceof DefinitionInterface || \is_callable($definition))) {
            if ($this instanceof ContainerBuilder) {
                $definition = $this->dumpObject($id, $definition, self::NULL_ON_INVALID_SERVICE === $invalidBehavior);
            }

            return null === $definition || self::IGNORE_SERVICE_INITIALIZING === $invalidBehavior ? $definition : $this->services[$id] = $definition;
        }

        $this->loading[$id] = true; // Checking if circular reference exists ...

        try {
            return $this->doCreate($id, $definition, $invalidBehavior);
        } finally {
            unset($this->loading[$id]);
        }
    }
}
