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
class Container extends AbstractContainer implements \ArrayAccess
{
    use Traits\AutowireTrait;

    protected array $types = [
        ContainerInterface::class => ['container'],
        Container::class => ['container'],
    ];

    /** @var array<string,string> internal cached services */
    protected array $methodsMap = ['container' => 'getServiceContainer'];

    /** @var ServiceProviderInterface[] A list of service providers */
    protected array $providers = [];


    /**
     * Instantiates the container.
     */
    public function __construct()
    {
        parent::__construct();

        // Incase this class it extended ...
        if (static::class !== __CLASS__) {
            $this->types += [static::class => ['container']];
        }

        $this->resolver = new AutowireValueResolver($this, $this->types);
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
     * This is useful when you want to autowire a callable or class string lazily.
     *
     * @see $this->definition() method
     * @deprecated Since 1.0, use Definition class or container's definition method instead.
     *
     * @param callable|string $definition A class string or a callable
     */
    public function lazy($definition): Definition
    {
        return $this->definition($definition, Definition::LAZY);
    }

    /**
     * Marks a definition as being a factory service.
     *
     * @see $this->definition() method
     * @deprecated Since 1.0, use Definition class or container's definition method instead.
     *
     * @param callable|object|string $callable A service definition to be used as a factory
     */
    public function factory($callable): Definition
    {
        return $this->definition($callable, Definition::FACTORY);
    }

    /**
     * Create a definition service.
     *
     * @param string|callable|Definition|Statement $definition $service
     * @param int|null $type of Definition::FACTORY | Definition::LAZY
     *
     * @return Definition
     */
    public function definition($service, int $type = null): Definition
    {
        $definition = new Definition($service);

        return null === $type ? $definition : $definition->should($type);
    }

    /**
     * Marks a definition from being interpreted as a service.
     *
     * @param mixed $definition from being evaluated
     */
    public function raw($definition): RawDefinition
    {
        return new RawDefinition($definition);
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
     * @return mixed The wrapped scope or Definition instance
     */
    public function extend(string $id, callable $scope)
    {
        if ($this->frozen[$id] ?? isset($this->methodsMap[$id])) {
            throw new FrozenServiceException($id);
        }

        if (null !== $extended = $this->values[$id] ?? null) {
            if ($extended instanceof RawDefinition) {
                return $this->values[$id] = new RawDefinition($scope($extended(), $this));
            }

            if (!$extended instanceof Definition && \is_callable($extended)) {
                $extended = $this->doCreate($id, $extended);
            }

            return $this->values[$id] = $scope($extended, $this);
        }

        throw $this->createNotFound($id);
    }

    /**
     * {@inheritdoc}
     */
    public function keys(): array
    {
        return \array_keys($this->keys + $this->methodsMap);
    }

    /**
     * {@inheritdoc}
     */
    public function reset(): void
    {
        parent::reset();

        foreach ($this->values as $id => $service) {
            if (isset(self::$services[$id])) {
                $service = self::$services[$id];
            }

            if ($service instanceof ResetInterface) {
                $service->reset();
            }

            unset($this->values[$id], $this->factories[$id], $this->raw[$id], $this->keys[$id], $this->frozen[$id], self::$services[$id]);
        }

        self::$services = [];
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
                return $this->get($this->aliases[$id]);
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
     * @param Definition|RawDefinition|Statement|\Closure|object $definition
     *
     * @throws FrozenServiceException Prevent override of a frozen service
     *
     * @return Definition|RawDefinition|mixed of Definition, RawService, class object or closure.
     */
    public function set(string $id, object $definition, bool $autowire = false)
    {
        if ($this->frozen[$id] ?? isset($this->methodsMap[$id])) {
            throw new FrozenServiceException($id);
        }

        // Incase new service definition exists in aliases.
        unset($this->aliases[$id]);

        if ($definition instanceof Definition) {
            $definition->attach($id, $this->resolver);
        } elseif ($definition instanceof Statement) {
            $definition = $this->resolver->resolve($definition->value, $definition->args);
        }

        $this->keys[$id] = true;
        $this->values[$id] = $definition;

        return ($autowire && !$definition instanceof RawDefinition) ? $this->autowireService($id, $definition) : $definition;
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
        $this->providers[\get_class($provider)] = $provider;

        if ([] !== $values && $provider instanceof Services\ConfigurationInterface) {
            $id = $provider->getName();
            $process = [new Processor(), 'processConfiguration'];

            $provider->setConfiguration($process($provider, isset($values[$id]) ? $values : [$id => $values]), $this);
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
     * @throws CircularReferenceException|NotFoundServiceException
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
