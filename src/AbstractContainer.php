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
    use Traits\ExtensionTrait;

    /** @var array<string,bool> service name => bool */
    protected array $loading = [];

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
        return $this->services[$id = $this->aliases[$id] ?? $id] ?? $this->doLoad($id, $invalidBehavior);
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $id): bool
    {
        return static::SERVICE_CONTAINER === ($id = $this->aliases[$id] ?? $id) || \array_key_exists($id, $this->definitions);
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
        $ref = new \ReflectionFunction(\Closure::fromCallable($scope));

        foreach ($services as $serviceId => $definition) {
            if ($this->has($serviceId)) {
                throw new ContainerResolutionException(\sprintf('Service with id "%s" exist in container and cannot be used using container\'s runScope method.', $serviceId));
            }
            $this->set($cleanup[] = $serviceId, $definition);
        }

        try {
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
            $tags = \array_keys($this->tags[$typeOrTag]);
        }
        $definitions = $tags ?? $this->types[$typeOrTag] ?? [];

        return null === $resolve ? $definitions : \array_map($resolve, $definitions);
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
    abstract protected function doCreate(string $id, object $definition, int $invalidBehavior);

    /**
     * Load the service definition.
     *
     * @return mixed
     */
    protected function doLoad(string $id, int $invalidBehavior)
    {
        if ($definition = $this->definitions[$id] ?? false) {
            if ($reference = &$this->loading[$id] ?? null) {
                throw new CircularReferenceException($id, [...\array_keys($this->loading), $id]);
            }
            $reference = true; // Checking if circular reference exists ...

            try {
                if (is_callable($definition)) {
                    $ref = new \ReflectionFunction(\Closure::fromCallable($definition));

                    if (!empty($refP = $ref->getParameters())) {
                        $refP = $this->resolver->autowireArguments($ref);
                    }

                    if (null === $this->resolver->getBuilder()) {
                        return $this->definitions[$id] = $this->services[$id] = $ref->invokeArgs($refP);
                    }
                }

                return $this->doCreate($id, $definition, $invalidBehavior);
            } finally {
                unset($this->loading[$id]);
            }
        } elseif (\array_key_exists($id, $this->types)) {
            return $this->autowired($id, self::EXCEPTION_ON_MULTIPLE_SERVICE === $invalidBehavior);
        } elseif (\class_exists($id) || \function_exists($id)) {
            try {
                if ($id !== $r = $this->resolver->resolve($id)) {
                    return $this->services[$id] = $r;
                }
            } catch (ContainerResolutionException $e) {
                // Skip error throwing while resolving
            }
        }

        if (self::NULL_ON_INVALID_SERVICE !== $invalidBehavior) {
            throw $this->createNotFound($id, $e ?? null);
        }

        return null;
    }
}
