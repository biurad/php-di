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

use DivineNii\Invoker\ArgumentResolver\DefaultValueResolver;
use DivineNii\Invoker\CallableReflection;
use Nette\SmartObject;
use Psr\Container\ContainerInterface;
use Rade\DI\Exceptions\CircularReferenceException;
use Rade\DI\Exceptions\ContainerResolutionException;
use Rade\DI\Exceptions\FrozenServiceException;
use Rade\DI\Exceptions\NotFoundServiceException;
use Rade\DI\Resolvers\AutowireValueResolver;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Contracts\Service\ResetInterface;

class Container implements \ArrayAccess, ContainerInterface
{
    use SmartObject;

    /** @var array<string,mixed> service name => instance */
    private array $values = [];

    /** @var array<string,bool> service name => bool */
    private array $keys = [];

    /** @var array<string,bool> service name => bool */
    private array $loading = [];

    /** @var array<string,bool> service name => bool */
    private array $frozen = [];

    /** @var string[] alias => service name */
    private array $aliases = [];

    /** @var array[] tag name => service name => tag value */
    private array $tags = [];

    /** @var ServiceProviderInterface[] */
    protected $providers = [];

    private \SplObjectStorage $factories;

    private \SplObjectStorage $protected;

    private AutowireValueResolver $resolver;

    private Processor $process;

    /**
     * Instantiates the container.
     */
    public function __construct()
    {
        $this->factories = new \SplObjectStorage();
        $this->protected = new \SplObjectStorage();
        $this->process   = new Processor();
        $this->resolver  = new AutowireValueResolver($this);

        $this->offsetSet('container', $this);
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
        if (isset($this->frozen[$offset])) {
            throw new FrozenServiceException($offset);
        }

        // Incase new service definition exists in aliases.
        unset($this->aliases[$offset]);

        if (\is_string($value) && \class_exists($value)) {
            $value = $this->callInstance($value);
        } elseif (\is_callable($value) && !$value instanceof \Closure) {
            $value = \Closure::fromCallable($value);
        }

        // Autowire service return types of callable or class object.
        if (\is_object($value)) {
            $this->autowireService($offset, $value);
        }

        $this->values[$offset] = $value;
        $this->keys[$offset]   = true;
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
        if (isset($this->aliases[$offset])) {
            $offset = $this->aliases[$offset];
        }

        if (!isset($this->keys[$offset])) {
            throw new NotFoundServiceException(sprintf('Identifier "%s" is not defined.', $offset));
        }

        if (
            !\is_object($service = $this->values[$offset]) ||
            isset($this->protected[$service])
        ) {
            return $service;
        }

        if (isset($this->loading[$offset])) {
            throw new CircularReferenceException(
                \sprintf('Circular reference detected for services: %s.', \implode(', ', \array_keys($this->loading)))
            );
        }

        return $this->getService($offset, $service);
    }

    /**
     * Checks if a service is set.
     *
     * @param string $offset The unique identifier for the service
     *
     * @return bool
     */
    public function offsetExists($offset)
    {
        return isset($this->keys[$this->aliases[$offset] ?? $offset]);
    }

    /**
     * Unsets a service by given offset.
     *
     * @param string $offset The unique identifier for service definition
     */
    public function offsetUnset($offset): void
    {
        if (isset($this->keys[$offset])) {
            if (\is_object($service = $this->values[$offset])) {
                unset($this->factories[$service]);
            }

            unset($this->values[$offset], $this->frozen[$offset], $this->aliases[$offset], $this->keys[$offset]);
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
        } elseif (isset($this->aliases[$serviceId])) {
            $serviceId = $this->aliases[$serviceId];
        }

        if (!isset($this[$serviceId])) {
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
     * Marks a callable as being a factory service.
     *
     * @param callable $callable A service definition to be used as a factory
     *
     * @throws ContainerResolutionException Service definition has to be a closure or an invokable object
     *
     * @return callable The passed callable
     */
    public function factory($callable): callable
    {
        if (!\is_object($callable) || !\method_exists($callable, '__invoke')) {
            throw new ContainerResolutionException('Service definition is not a Closure or invokable object.');
        }

        $this->factories->attach($callable);

        return $callable;
    }

    /**
     * Protects a callable from being interpreted as a service.
     *
     * This is useful when you want to store a callable as a parameter.
     *
     * @param callable $callable A callable to protect from being evaluated
     *
     * @return callable The passed callable
     *
     * @throws ContainerResolutionException Service definition has to be a closure or an invokable object
     */
    public function protect($callable): callable
    {
        if (!\is_object($callable) || !\method_exists($callable, '__invoke')) {
            throw new ContainerResolutionException('Callable is not a Closure or invokable object.');
        }

        $this->protected->attach($callable);

        return $callable;
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
     *
     * @return mixed The wrapped scope
     */
    public function extend(string $id, callable $scope)
    {
        if (!isset($this->keys[$id])) {
            throw new NotFoundServiceException(sprintf('Identifier "%s" is not defined.', $id));
        }

        if (isset($this->frozen[$id])) {
            throw new FrozenServiceException($id);
        }

        if (\is_callable($factory = $service = $this->values[$id])) {
            if (isset($this->protected[$service])) {
                throw new ContainerResolutionException(
                    "Protected callable service '{$id}' cannot be extended, cause it has parameters which cannot be resolved."
                );
            }

            $factory = $this->callMethod($factory);
        }

        $extended = $scope(...[$factory, $this]);

        if (\is_object($service) && isset($this->factories[$service])) {
            $this->factories->detach($service);
            $this->factories->attach($extended = fn () => $extended);
        }

        return $this[$id] = $extended;
    }

    /**
     * Returns all defined value names.
     *
     * @return array An array of value names
     */
    public function keys()
    {
        return \array_keys($this->values);
    }

    /**
     * Resets the container
     */
    public function reset(): void
    {
        foreach ($this->values as $id => $service) {
            if ($service instanceof self) {
                continue;
        }

            if ($service instanceof ResetInterface) {
                $service->reset();
        }

            unset($this->values[$id], $this->keys[$id], $this->frozen[$id]);
        }
        unset($this->tags, $this->aliases, $this->loading);

        $this->protected->removeAll($this->protected);
        $this->factories->removeAll($this->factories);
    }

    /**
     * Calls method using autowiring.
     *
     * @param array<int|string,mixed> $args
     *
     * @return mixed
     */
    public function callMethod(callable $function, array $args = [])
    {
        return $function(...$this->autowireArguments(CallableReflection::create($function), $args));
    }

    /**
     * {@inheritdoc}
     */
    public function get($id)
    {
        try {
            return $this->offsetGet($id);
        } catch (NotFoundServiceException $serviceError) {
                try {
                    return $this->resolver->getByType($id);
            } catch (NotFoundServiceException $typeError) {
                if (\class_exists($id)) {
                    try {
                        return $this->autowireClass($id, []);
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
    public function has($id)
    {
        if (isset($this->keys[$this->aliases[$id] ?? $id])) {
            return true;
        }

        throw new NotFoundServiceException(sprintf('Identifier "%s" is not defined.', $id));
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

        if ($provider instanceof ConfigurationInterface && !empty($values)) {
            $providerId = $provider->getName() . '.config';

            $this->offsetSet($providerId, $this->process->processConfiguration($provider, $values));
        }

        $provider->register($this);

        return $this;
    }

    /**
     * Add a clas or interface that should be excluded from autowiring.
     *
     * @param string ...$types
     */
    public function exclude(string ...$types): void
    {
        foreach ($types as $type) {
            $this->resolver->exclude($type);
        }
    }

    /**
     * @param string          $id
     * @param callable|object $service
     *
     * @return mixed
     */
    private function getService(string $id, $service)
    {
        $this->loading[$id] = true;

        try {
            if (isset($this->factories[$service])) {
                return $this->callMethod($service);
            }

            $this->frozen[$id] = true;

            if (\is_callable($service)) {
                $service = $this->callMethod($service);
            }

            return $this->values[$id] = $service;
        } finally {
            unset($this->loading[$id]);
        }
    }

    /**
     * @param object|callable $service
     */
    private function autowireService(string $id, $service): void
    {
        // Resolving the closure of the service to return it's type hint or class.
        $type = \is_callable($service) ? CallableReflection::create($service)->getReturnType() : \get_class($service);

        if ($type instanceof \ReflectionType) {
            $types = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];

            $type = \array_map(fn (\ReflectionNamedType $type): string => $type->getName(), $types);
        }

        // Resolving wiring so we could call the service parent classes and interfaces.
        if (!isset($this->keys[$id])) {
            $this->resolver->autowire($id, $type);
        }
    }

    /**
     * Resolves arguments for callables
     *
     * @param \ReflectionFunctionAbstract $function
     * @param array<int|string,mixed> $args
     *
     * @return array<int,mixed>
     */
    private function autowireArguments(\ReflectionFunctionAbstract $function, array $args = []): array
    {
        $resolvedParameters   = [];
        $reflectionParameters = $function->getParameters();

        foreach ($reflectionParameters as $parameter) {
            $position = $parameter->getPosition();

            if (null !== $resolved = $this->resolver->resolve($parameter, $args)) {
                if ($resolved === DefaultValueResolver::class) {
                    $resolved = null;
                }

                if (null !== $resolved && $parameter->isVariadic()) {
                    foreach (\array_chunk($resolved, 1) as $index => [$value]) {
                        $resolvedParameters[$index + 1] = $value;
                    }

                    continue;
                }

                $resolvedParameters[$position] = $resolved;
            }

            if (empty(\array_diff_key($reflectionParameters, $resolvedParameters))) {
                // Stop traversing: all parameters are resolved
                return $resolvedParameters;
            }
        }

        return $resolvedParameters;
    }
}
