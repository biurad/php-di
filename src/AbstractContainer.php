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
            if ($type instanceof ContainerBuilder) {
                continue;
            }

            $this->types[$type] = [self::SERVICE_CONTAINER];
        }

        if ($this instanceof ContainerBuilder) {
            try {
                $builderFactory = new \PhpParser\BuilderFactory();
            } catch (\Error $e) {
                throw new \RuntimeException('ContainerBuilder uses "nikic/php-parser" v4, do composer require the nikic/php-parser package.', 0, $e);
            }
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
            return $this->resolver->resolveCallable($scope);
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
        if (isset($this->loading[$id])) {
            throw new CircularReferenceException($id, [...\array_keys($this->loading), $id]);
        }

        if (\array_key_exists($id, $this->definitions)) {
            $this->loading[$id] = true; // Checking if circular reference exists ...

            try {
                return $this->doCreate($id, $this->definitions[$id], $invalidBehavior);
            } finally {
                unset($this->loading[$id]);
            }
        }

        if (\array_key_exists($id, $this->types)) {
            return $this->autowired($id, self::EXCEPTION_ON_MULTIPLE_SERVICE === $invalidBehavior);
        }

        return null; // Extend for additional context and/or exceptions.
    }
}
