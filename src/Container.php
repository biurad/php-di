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

use Nette\Utils\Helpers;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Rade\DI\Exceptions\CircularReferenceException;
use Rade\DI\Exceptions\ContainerResolutionException;
use Rade\DI\Exceptions\FrozenServiceException;
use Rade\DI\Exceptions\NotFoundServiceException;
use Rade\DI\Resolvers\AutowireValueResolver;
use Rade\DI\Services\ServiceProviderInterface;
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

    protected array $types = [
        ContainerInterface::class => ['container'],
        Container::class => ['container'],
    ];

    /** @var array<string,string> internal cached services */
    protected array $methodsMap = ['container' => 'getServiceContainer'];

    /** @var array<string,mixed> A list of already loaded services (this act as a local cache) */
    private static array $services;

    /** @var ServiceProviderInterface[] A list of service providers */
    protected array $providers = [];

    /** @var ContainerInterface[] A list of fallback PSR-11 containers */
    protected array $fallback = [];

    /**
     * Instantiates the container.
     */
    public function __construct()
    {
        static::$services = [];

        // Incase this class it extended ...
        if (static::class !== __CLASS__) {
            $this->types += [static::class => ['container']];
        }

        $this->resolver = new AutowireValueResolver($this, $this->types);
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
        return self::$services[$offset] ?? $this->raw[$offset] ?? $this->fallback[$offset]
            ?? ($this->factories[$offset] ?? [$this, 'getService'])($offset);
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
        if ($this->keys[$offset] ?? isset($this->methodsMap[$offset])) {
            return true;
        }

        if ([] !== $this->fallback) {
            if (isset($this->fallback[$offset])) {
                return true;
            }

            foreach ($this->fallback as $container) {
                try {
                    return $container->has($offset);
                } catch (NotFoundExceptionInterface $e) {
                }
            }
        }

        return isset($this->aliases[$offset]) ? $this->offsetExists($this->aliases[$offset]) : false;
    }

    /**
     * Unsets a service by given offset.
     *
     * @param string $offset The unique identifier for service definition
     */
    public function offsetUnset($offset): void
    {
        if ($this->offsetExists($offset)) {
            unset($this->values[$offset], $this->factories[$offset], $this->frozen[$offset], $this->raw[$offset], $this->keys[$offset], self::$services[$offset]);
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

        if (!$this->offsetExists($serviceId)) {
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
        $this->extendable($id);

        // Extended factories should always return new instance ...
        if (isset($this->factories[$id])) {
            $factory = $this->factories[$id];

            return $this->factories[$id] = fn () => $scope($factory(), $this);
        }

        if (\is_object($extended = $this->values[$id] ?? null)) {
            // This is a hungry implementation to autowire $extended if it's an object.
            $this->autowireService($id, $extended);

            if (\is_callable($extended)) {
                $extended = $this->doCreate($id, $this->values[$id]);

                // Unfreeze service if frozen ...
                unset($this->frozen[$id]);
            }
        }

        // We bare in mind that $extended could return anyting, and does want to exist in $this->values.
        return $this->values[$id] = $scope($extended, $this);
    }

    /**
     * Check if servie if can be extended.
     *
     * @param string $id
     *
     * @throws NotFoundServiceException If the identifier is not defined
     * @throws FrozenServiceException   If the service is frozen
     *
     * @return bool
     */
    public function extendable(string $id): bool
    {
        if (!isset($this->keys[$id])) {
            throw new NotFoundServiceException(sprintf('Identifier "%s" is not defined.', $id));
        }

        if ($this->frozen[$id] ?? isset($this->methodsMap[$id])) {
            throw new FrozenServiceException($id);
        }

        if (isset($this->raw[$id])) {
            throw new ContainerResolutionException("Service definition '{$id}' cannot be extended, was not meant to be resolved.");
        }

        return true;
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

            unset($this->values[$id], $this->factories[$id], $this->raw[$id], $this->keys[$id], $this->frozen[$id], self::$services[$id]);
        }

        // A container such as Symfony DI support reset ...
        foreach ($this->fallback as $fallback) {
            if ($fallback instanceof ResetInterface) {
                $fallback->reset();
            }
        }

        $this->tags = $this->aliases = self::$services = $this->fallback = [];
    }

    /**
     * {@inheritdoc}
     *
     * @param string                  $id — Identifier of the entry to look for.
     * @param array<int|string,mixed> $arguments
     */
    public function get($id, array $arguments = [])
    {
        // If service has already been requested and cached ...
        if (isset(self::$services[$id])) {
            return self::$services[$id];
        }

        try {
            if (\is_callable($protected = $this->raw[$id] ?? null) && [] !== $arguments) {
                $protected = $this->call($protected, $arguments);
            }

            return $protected ?? $this->fallback[$id] ?? ($this->factories[$id] ?? [$this, 'getService'])($id);
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

            if (isset($this->aliases[$id])) {
                return $this->get($this->aliases[$id], $arguments);
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
        if ($this->frozen[$id] ?? isset($this->methodsMap[$id])) {
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

        if ([] !== $values && $provider instanceof Services\ConfigurationInterface) {
            $id = $provider->getName();
            $process = [new Processor(), 'processConfiguration'];

            $this->parameters[$id] = $process($provider, isset($values[$id]) ? $values : [$id => $values]);
        }

        // If service provider depends on other providers ...
        if ($provider instanceof Services\DependedInterface) {
            foreach ($provider->dependencies() as $dependency) {
                $dependency = $this->autowireClass($dependency, []);

                if ($dependency instanceof ServiceProviderInterface) {
                    $this->register($dependency);
                }
            }
        }

        $provider->register($this);

        return $this;
    }

    /**
     * Register a PSR-11 fallback container.
     *
     * @param ContainerInterface $fallback
     *
     * @return static
     */
    public function fallback(ContainerInterface $fallback)
    {
        $this->fallback[$name = \get_class($fallback)] = $fallback;

        // Autowire $fallback, incase services parametes
        // requires it, container is able to serve it.
        $this->resolver->autowire($name, [$name]);

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
        if (isset($this->methodsMap[$id])) {
            return self::$services[$id] = $this->{$this->methodsMap[$id]}();
        } elseif (isset($this->values[$id])) {
            // If we found the real instance of $service, lets cache that ...
            if (!\is_callable($service = $this->values[$id])) {
                $this->frozen[$id] ??= true;

                return self::$services[$id] = $service;
            }

            // we have to create the object and avoid infinite lopp.
            return $this->doCreate($id, $service);
        }

        if ([] !== $this->fallback) {
            // A bug is discovered here, if fallback is a dynamically autowired container like this one.
            // Instead a return of fallback container, main container should be used.
            if ($id === ContainerInterface::class) {
                return $this;
            }

            foreach ($this->fallback as $container) {
                try {
                    return self::$services[$id] = $container->get($id);
                } catch (ContainerExceptionInterface $e) {
                }
            }
        } elseif (isset($this->aliases[$id])) {
            return $this->offsetGet($this->aliases[$id]);
        }

        if (null !== $suggest = Helpers::getSuggestion($this->keys(), $id)) {
            $suggest = " Did you mean: \"{$suggest}\" ?";
        }

        throw new NotFoundServiceException(\sprintf('Identifier "%s" is not defined.' . $suggest, $id), 0, $e ?? null);
    }

    /**
     * This is a performance sensitive method, please do not modify.
     *
     * @return mixed
     */
    protected function doCreate(string $id, callable $service)
    {
        // Checking if circular reference exists ...
        if (isset($this->loading[$id])) {
            throw new CircularReferenceException($id, [...\array_keys($this->loading), $id]);
        }
        $this->loading[$id] = true;

        try {
            $this->values[$id] = $this->call($service);
            $this->frozen[$id] = true; // Freeze resolved service ...

            return $this->values[$id];
        } finally {
            unset($this->loading[$id]);
        }
    }
}
