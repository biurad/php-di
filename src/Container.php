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

use Rade\DI\Exceptions\{ContainerResolutionException, FrozenServiceException, NotFoundServiceException};

/**
 * Dependency injection container.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class Container extends AbstractContainer implements \ArrayAccess
{
    public function __construct()
    {
        if (empty($this->types)) {
            $this->services[self::SERVICE_CONTAINER] = $c = $this;
            $this->type(self::SERVICE_CONTAINER, \array_keys(\class_implements($c) + \class_parents($c) + [static::class => static::class]));
        }
        $this->resolver = new Resolver($s ?? $this);
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
        if (\array_key_exists($id, $this->privates)) {
            throw new FrozenServiceException(\sprintf('The "%s" internal service is meant to be private and out of reach.', $id));
        }

        return parent::definition($id);
    }

    /**
     * {@inheritdoc}
     *
     * @throws \ReflectionException
     */
    protected function doCreate(string $id, object $definition, int $invalidBehavior)
    {
        if ($definition instanceof Definitions\DefinitionInterface) {
            if ($definition instanceof Definitions\ShareableDefinitionInterface) {
                if ($definition->isAbstract()) {
                    throw new ContainerResolutionException(\sprintf('Resolving an abstract definition %s is not allowed.', $id));
                }

                if (!$definition->isPublic()) {
                    $this->removeDefinition($id);
                }

                if ($definition->isShared()) {
                    $s = &$this->services[$id] ?? null;
                }
            }
            $s = $definition->build($id, $this->resolver);
        }

        return $this->definitions[$id] = ($this->services[$id] ??= $s ?? $definition);
    }
}
