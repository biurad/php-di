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

use Nette\SmartObject;
use Nette\Utils\Helpers;
use Psr\Container\ContainerInterface;
use Rade\DI\Exceptions\CircularReferenceException;
use Rade\DI\Exceptions\ContainerResolutionException;
use Rade\DI\Exceptions\FrozenServiceException;
use Rade\DI\Exceptions\NotFoundServiceException;
use Rade\DI\Resolvers\AutowireValueResolver;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Dependency injection container.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class Container implements \ArrayAccess, ContainerInterface, ResetInterface
{
    use Traits\AutowireTrait;
    use SmartObject;

    protected array $types = [
        ContainerInterface::class => ['container'],
        Container::class => ['container'],
    ];

    /** @var array<string,string> internal cached services */
    protected array $methodsMap = ['container' => 'getServiceContainer'];

    /** @var array<string,mixed> For handling a global config around services */
    public array $parameters = [];

    /**
     * Instantiates the container.
     */
    public function __construct()
    {
        $typesWiring = $this->types;

        // Incase this class it extended ...
        if (static::class !== __CLASS__) {
            $typesWiring += [static::class => ['container']];
        }

        $this->resolver = new AutowireValueResolver($this, $typesWiring);
    }

    /**
     * Container can not be cloned.
     */
    public function __clone()
    {
        throw new \LogicException('Container is not clonable');
    }

    /**
     * Dynamically access container services.
     *
     * @param string $key
     *
     * @return mixed
     */
    public function __get($key)
    {
        return $this->offsetGet($key);
    }

    /**
     * Dynamically set container services.
     *
     * @param string $key
     * @param object $value
     */
    public function __set($key, $value): void
    {
        $this->offsetSet($key, $value);
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
        $this->set($offset, $value, true);
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
        // If alias is set
        $id = $this->aliases[$offset] ?? $offset;

        return isset($this->frozen[$id]) ? $this->values[$id] : $this->raw[$id]
            ?? ($this->factories[$id] ?? [$this, 'getService'])($id);
    }

    /**
     * Checks if a service is set.
     *
     * @param string $offset The unique identifier for the service
     *
     * @return bool
     */
    public function offsetExists($offset): bool
    {
        return $this->keys[$id = $this->aliases[$offset] ?? $offset] ?? isset($this->methodsMap[$id]);
    }

    /**
     * Unsets a service by given offset.
     *
     * @param string $offset The unique identifier for service definition
     */
    public function offsetUnset($offset): void
    {
        if ($this->offsetExists($offset)) {
            unset($this->values[$offset], $this->factories[$offset], $this->frozen[$offset], $this->raw[$offset], $this->keys[$offset]);
        }
    }

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

        // Incase alias is found linking to another alias that exist
        $serviceId = $this->aliases[$serviceId] ?? $serviceId;

        if (!isset($this->keys[$serviceId])) {
            throw new ContainerResolutionException('Service id is not found in container');
        }

        $this->aliases[$id] = $serviceId;
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

    /**
     * Wraps a class string or callable with a `call` method.
     *
     * This is useful when you want to autowire a callable or class string lazily.
     *
     * @param callable|string $definition A class string or a callable
     */
    public function lazy($definition): ScopedDefinition
    {
        return new ScopedDefinition($definition, ScopedDefinition::LAZY);
    }

    /**
     * Marks a callable as being a factory service.
     *
     * @param callable $callable A service definition to be used as a factory
     */
    public function factory($callable): ScopedDefinition
    {
        return new ScopedDefinition(fn () => $this->call($callable));
    }

    /**
     * Marks a definition from being interpreted as a service.
     *
     * @param mixed $definition from being evaluated
     */
    public function raw($definition): ScopedDefinition
    {
        return new ScopedDefinition($definition, ScopedDefinition::RAW);
    }

    /**
     * Extends an object definition.
     *
     * Useful when you want to extend an existing object definition,
     * without necessarily loading that object.
     *
     * @param string   $id    The unique identifier for the object
     * @param callable $scope A service definition to extend the original
     *
     * @throws NotFoundServiceException If the identifier is not defined
     * @throws FrozenServiceException   If the service is frozen
     * @throws CircularReferenceException If infinite loop among service is detected
     *
     * @return mixed The wrapped scope
     */
    public function extend(string $id, callable $scope)
    {
        if (!isset($this->keys[$id])) {
            throw new NotFoundServiceException(sprintf('Identifier "%s" is not defined.', $id));
        }

        if (isset($this->frozen[$id]) || isset($this->methodsMap[$id])) {
            throw new FrozenServiceException($id);
        }

        if (isset($this->raw[$id])) {
            throw new ContainerResolutionException(
                "Service definition '{$id}' cannot be extended, was not meant to be resolved."
            );
        }

        // Extended factories should always return new instance ...
        if (isset($this->factories[$id])) {
            $factory = $this->factories[$id];

            return $this->factories[$id] = fn () => $scope($factory(), $this);
        }
        $extended = $scope($this->getService($id), $this);

        // Unfreeze sevice if frozen ...
        unset($this->frozen[$id]);

        // We bare in mind that $extended could return anyting, and does want to exist in $this->values.
        // This is a hungry implementation to autowire $extended if it's an object.
        return $this->values[$id] = is_object($extended) ? $this->autowireService($id, $extended) : fn () => $extended;
    }

    /**
     * Returns all defined value names.
     *
     * @return string[] An array of value names
     */
    public function keys(): array
    {
        return \array_keys($this->keys + $this->methodsMap);
    }

    /**
     * Resets the container
     */
    public function reset(): void
    {
        foreach ($this->values as $id => $service) {
            if ($service instanceof ResetInterface) {
                $service->reset();
            }

            unset($this->values[$id], $this->factories[$id], $this->raw[$id], $this->keys[$id], $this->frozen[$id]);
        }

        $this->tags = $this->aliases = [];
    }

    /**
     * {@inheritdoc}
     *
     * @param string                  $id — Identifier of the entry to look for.
     * @param array<int|string,mixed> $arguments
     */
    public function get($id, array $arguments = [])
    {
        if (null !== $protected = $this->raw[$this->aliases[$id] ?? $id] ?? null) {
            return (\is_callable($protected) && [] !== $arguments) ? $this->call($protected, $arguments) : $protected;
        }

        try {
            return $this->offsetGet($id);
        } catch (NotFoundServiceException $serviceError) {
            try {
                return $this->resolver->getByType($id);
            } catch (NotFoundServiceException $typeError) {
                if (\class_exists($id)) {
                    try {
                        return $this->autowireClass($id, $arguments);
                    } catch (ContainerResolutionException $e) {
                    }
                }
            }

            throw $serviceError;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function has($id): bool
    {
        if ($this->offsetExists($id)) {
            return true;
        }

        throw new NotFoundServiceException(sprintf('Identifier "%s" is not defined.', $id));
    }

    /**
     * Set a service definition
     *
     * @param object $definition
     *
     * @throws FrozenServiceException Prevent override of a frozen service
     */
    public function set(string $id, object $definition, bool $autowire = false): void
    {
        if (isset($this->frozen[$id]) || isset($this->methodsMap[$id])) {
            throw new FrozenServiceException($id);
        }

        // Incase new service definition exists in aliases.
        unset($this->aliases[$id]);

        if (!$definition instanceof ScopedDefinition) {
           // Resolving the closure of the service to return it's type hint or class.
            $this->values[$id] = !$autowire ? $definition : $this->autowireService($id, $definition);
        } else {
            // Lazy class-string service $definition
            if (\class_exists($property = $definition->property)) {
                if ($autowire) {
                    $this->resolver->autowire($id, [$property]);
                }
                $property = 'values';
            }

            $this->{$property}[$id] = $definition->service;
        }

        $this->keys[$id] = true;
    }

    /**
     * Registers a service provider.
     *
     * @param ServiceProviderInterface $provider A ServiceProviderInterface instance
     * @param array                    $values   An array of values that customizes the provider
     *
     * @return static
     */
    public function register(ServiceProviderInterface $provider, array $values = [])
    {
        $this->providers[] = $provider;

        if (
            ([] !== $values && \method_exists($provider, 'getName')) && 
            $provider instanceof ConfigurationInterface
        ) {
            $id = $provider->getName();
            $process = [new Processor(), 'processConfiguration'];

            $this->parameters[$id] = $process($provider, isset($values[$id]) ? $values : [$id => $values]);
        }

        $provider->register($this);

        return $this;
    }

    /**
     * @internal
     *
     * Get the mapped service container instance
     */
    protected function getServiceContainer(): self
    {
        return $this;
    }

    /**
     * Build an entry of the container by its name.
     *
     * @param string $id
     *
     * @throws CircularReferenceException
     * @throws NotFoundServiceException
     *
     * @return mixed
     */
    protected function getService(string $id)
    {
        // Checking if circular reference exists ...
        if (isset($this->loading[$id])) {
            throw new CircularReferenceException($id, [...\array_keys($this->loading), $id]);
        }

        $this->loading[$id] = true;

        try {
            if (isset($this->keys[$id])) {
                if (\is_callable($service = $this->values[$id])) {
                    $service = $this->call($service);
                }
                $this->frozen[$id] = true; // Freeze resolved service ...

                return $this->values[$id] = $service;
            } elseif (isset($this->methodsMap[$id])) {
                return $this->{$this->methodsMap[$id]}();
            }
        } finally {
            unset($this->loading[$id]);
        }

        if (null !== $suggest = Helpers::getSuggestion($this->keys(), $id)) {
            $suggest = " Did you mean: \"{$suggest}\" ?";
        }

        throw new NotFoundServiceException(\sprintf('Identifier "%s" is not defined.' . $suggest, $id));
    }
}
