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
use Rade\DI\Exceptions\{CircularReferenceException, FrozenServiceException, NotFoundServiceException, ContainerResolutionException};

/**
 * Dependency injection container.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class Container extends AbstractContainer implements \ArrayAccess
{
    /** @var array<string,string> internal cached services */
    protected array $methodsMap = [];

    /** @var array<string,callable> un-shareable cached services */
    protected array $factories = [];

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
        if (\array_key_exists($id, $this->services)) {
            throw new FrozenServiceException(\sprintf('The "%s" service is already initialized.', $id));
        }

        return parent::definition($id);
    }

    /**
     * {@inheritdoc}
     */
    public function extend(string $id, callable $scope = null)
    {
        if (isset($this->methodsMap[$id])) {
            throw new FrozenServiceException(\sprintf('The internal service definition for "%s", cannot be overwritten.', $id));
        }

        return parent::extend($id, $scope);
    }

    /**
     * {@inheritdoc}
     */
    public function keys(): array
    {
        return parent::keys() + \array_keys($this->methodsMap);
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $id, int $invalidBehavior = /* self::EXCEPTION_ON_MULTIPLE_SERVICE */ 1)
    {
        if (\array_key_exists($id = $this->aliases[$id] ?? $id, $this->services)) {
            return $this->services[$id];
        }

        return self::SERVICE_CONTAINER === $id ? $this : ($this->factories[$id] ?? [$this, $this->methodsMap[$id] ?? 'doGet'])($id, $invalidBehavior);
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
     */
    protected function createDefinition(string $id, $definition)
    {
        $definition = parent::createDefinition($id, $definition);

        if ($definition instanceof DefinitionInterface || \is_callable($definition) || \is_object($definition)) {
            return $definition;
        }

        return fn () => $this->resolver->resolve($definition);
    }

    /**
     * Build an entry of the container by its name.
     *
     * @throws CircularReferenceException|NotFoundServiceException
     *
     * @return mixed
     */
    protected function doGet(string $id, int $invalidBehavior)
    {
        $createdService = parent::doGet($id, $invalidBehavior);

        if (null === $createdService) {
            $anotherService = $this->resolver->resolve($id);

            if ($id !== $anotherService) {
                return $anotherService;
            }

            if (self::NULL_ON_INVALID_SERVICE !== $invalidBehavior) {
                throw $this->createNotFound($id);
            }
        }

        return $createdService;
    }

    /**
     * @param DefinitionInterface|callable|mixed
     */
    protected function doCreate(string $id, $definition, int $invalidBehavior)
    {
        if ($definition instanceof DefinitionInterface) {
            $createdService = $definition->build($id, $this->resolver);

            if ($definition instanceof Definition) {
                // Definition service available once, else if shareable, accessed from cache.
                if (!$definition->isPublic()) {
                    $this->removeDefinition($id);
                }

                if (!$definition->isShared()) {
                    $this->factories[$id] = static fn () => $createdService;
                }
            }

            goto cache_definition;
        }

        if (\is_callable($definition)) {
            $definition = $this->resolver->resolve($definition);

            cache_definition:
            if (self::IGNORE_SERVICE_FREEZING === $invalidBehavior) {
                return $this->services[$id] = $createdService ?? $definition;
            }
        }

        return $this->definitions[$id] = $this->services[$id] = $createdService ?? $definition;
    }
}
