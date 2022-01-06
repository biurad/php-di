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

use Rade\DI\Definitions\{DefinitionInterface, ShareableDefinitionInterface};
use Rade\DI\Exceptions\{ContainerResolutionException, FrozenServiceException, NotFoundServiceException};

/**
 * Dependency injection container.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class Container extends AbstractContainer implements \ArrayAccess
{
    /** @var array<string,string> internal cached services */
    protected array $methodsMap = [];

    public function __construct()
    {
        if (empty($this->types)) {
            $this->type(self::SERVICE_CONTAINER, Resolvers\Resolver::autowireService(static::class));
        }

        parent::__construct();
    }

    /**
     * Sets a new service to a unique identifier.
     *
     * @param string $offset The unique identifier for the parameter or object
     * @param mixed  $value  The value of the service assign to the $offset
     *
     * @throws FrozenServiceException Prevent override of a frozen service
     */
    public function offsetSet($offset, $value): void
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
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->get($offset);
    }

    /**
     * Checks if a service is set.
     *
     * @param string $offset The unique identifier for the service
     */
    public function offsetExists($offset): bool
    {
        return $this->has($offset);
    }

    /**
     * Unset a service by given offset.
     *
     * @param string $offset The unique identifier for service definition
     */
    public function offsetUnset($offset): void
    {
        $this->removeDefinition($offset);
    }

    /**
     * {@inheritdoc}
     *
     * @throws FrozenServiceException if definition has been initialized
     */
    public function definition(string $id)
    {
        if (\array_key_exists($id, $this->privates) || isset($this->methodsMap[$id])) {
            throw new FrozenServiceException(\sprintf('The "%s" internal service is meant to be private and out of reach.', $id));
        }

        return parent::definition($id);
    }

    /**
     * {@inheritdoc}
     */
    public function keys(): array
    {
        return \array_merge(parent::keys(), \array_keys($this->methodsMap));
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $id, int $invalidBehavior = /* self::EXCEPTION_ON_MULTIPLE_SERVICE */ 1)
    {
        if (isset($this->services[$id])) {
            return $this->services[$id];
        }

        if (\array_key_exists($id, $this->aliases)) {
            return $this->services[$id = $this->aliases[$id]] ?? $this->get($id);
        }

        return self::SERVICE_CONTAINER === $id ? $this : parent::get($id, $invalidBehavior);
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $id): bool
    {
        return parent::has($id) || \array_key_exists($this->aliases[$id] ?? $id, $this->methodsMap);
    }

    /**
     * {@inheritdoc}
     *
     * @throws \ReflectionException
     */
    protected function doCreate(string $id, $definition, int $invalidBehavior)
    {
        if ($definition instanceof DefinitionInterface) {
            if ($definition instanceof ShareableDefinitionInterface) {
                if ($definition->isAbstract()) {
                    throw new ContainerResolutionException(\sprintf('Resolving an abstract definition %s is not supported.', $id));
                }

                if (!$definition->isPublic()) {
                    $this->removeDefinition($id); // Definition service available once, else if shareable, accessed from cache.
                }

                if (!$definition->isShared()) {
                    return $definition->build($id, $this->resolver);
                }
            }

            $definition = $definition->build($id, $this->resolver);
        } elseif (\is_callable($definition)) {
            $definition = $this->resolver->resolveCallable($definition);
        } elseif (!$definition) {
            try {
                if ($id !== $anotherService = $this->resolver->resolve($id)) {
                    return $this->services[$id] = $anotherService;
                }
            } catch (ContainerResolutionException $e) {
                // Skip error throwing while resolving
            }

            if (self::NULL_ON_INVALID_SERVICE !== $invalidBehavior) {
                throw $this->createNotFound($id);
            }

            return null;
        }

        return $this->definitions[$id] = $this->services[$id] = $definition;
    }
}
