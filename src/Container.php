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

use DivineNii\Invoker\ArgumentResolver;
use DivineNii\Invoker\CallableReflection;
use DivineNii\Invoker\CallableResolver;
use DivineNii\Invoker\Exceptions\NotCallableException;
use DivineNii\Invoker\Interfaces\ArgumentResolverInterface;
use DivineNii\Invoker\Interfaces\ArgumentValueResolverInterface;
use Nette\SmartObject;
use Psr\Container\ContainerInterface;
use Rade\DI\Exceptions\CircularReferenceException;
use Rade\DI\Exceptions\ContainerResolutionException;
use Rade\DI\Exceptions\FrozenServiceException;
use Rade\DI\Exceptions\NotFoundServiceException;
use Rade\DI\Resolvers\AutowireValueResolver;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Processor;

class Container implements \ArrayAccess, ContainerInterface
{
    use SmartObject;

    /** @var array<string,mixed> service name => instance */
    private array $values = [];

    /** @var array<string,bool> service name => bool */
    private array $keys = [];

    /** @var array<string,bool> service name => bool */
    private array $creating = [];

    /** @var array<string,bool> service name => bool */
    private array $frozen = [];

    /** @var string[] alias => service name */
    private array $aliases = [];

    /** @var ServiceProviderInterface[] */
    protected $providers = [];

    private \SplObjectStorage $factories;

    private AutowireValueResolver $resolver;

    private ArgumentResolverInterface $autowire;

    private Processor $process;

    /**
     * Instantiates the container.
     *
     * Objects and parameters can be passed as argument to the constructor.
     *
     * @param ArgumentValueResolverInterface[] $resolvers
     */
    public function __construct(array $resolvers = [])
    {
        $this->factories = new \SplObjectStorage();
        $this->process   = new Processor();

        $resolvers[]    = $this->resolver = new AutowireValueResolver($this);
        $this->autowire = new ArgumentResolver($resolvers);

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

        try {
            $value = (new CallableResolver())->resolve($value);
        } catch (NotCallableException $e) {
            if (\is_string($value) && \class_exists($value)) {
                $value = $this->callInstance($value);
            }
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
        if (!isset($this->keys[$offset])) {
            throw new NotFoundServiceException(sprintf('Identifier "%s" is not defined.', $offset));
        }

        if (!\is_object($service = $this->values[$offset])) {
            return $service;
        }

        if (isset($this->creating[$offset])) {
            throw new CircularReferenceException(
                \sprintf('Circular reference detected for services: %s.', \implode(', ', \array_keys($this->creating)))
            );
        }

        try {
            $this->creating[$offset] = true;

            if (isset($this->factories[$service])) {
                return $this->callMethod($service);
            }

            $this->frozen[$offset] = true;

            if (\is_callable($service)) {
                $service = $this->callMethod($service);
            }

            return $this->values[$offset] = $service;
        } finally {
            unset($this->creating[$offset]);
        }
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
        return isset($this->keys[$offset]);
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

            unset($this->values[$offset], $this->frozen[$offset], $this->keys[$offset]);
        }
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
    public function factory($callable)
    {
        if (!\is_object($callable) || !\method_exists($callable, '__invoke')) {
            throw new ContainerResolutionException('Service definition is not a Closure or invokable object.');
        }

        $this->factories->attach($callable);

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
    public function extend($id, callable $scope)
    {
        if (!isset($this->keys[$id])) {
            throw new NotFoundServiceException(sprintf('Identifier "%s" is not defined.', $id));
        }

        if (isset($this->frozen[$id])) {
            throw new FrozenServiceException($id);
        }

        if (\is_callable($factory = $service = $this->values[$id])) {
            $factory = $this->callMethod($factory);
        }

        $extended = $scope(...[$factory, $this]);

        if (is_object($service) && isset($this->factories[$service])) {
            $this->factories->detach($service);
            $this->factories->attach(fn () => $extended);
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
     * Creates new instance using autowiring.
     *
     * @param array<int|string,mixed> $args
     *
     * @throws ContainerResolutionException
     *
     * @return object
     */
    public function callInstance(string $class, array $args = [])
    {
        /** @var class-string $class */
        $reflection = new \ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new ContainerResolutionException("Class $class is not instantiable.");
        }

        if (null !== $constructor = $reflection->getConstructor()) {
            return $reflection->newInstanceArgs($this->autowireArguments($constructor, $args));
        }

        if (!empty($args)) {
            throw new ContainerResolutionException("Unable to pass arguments, class $class has no constructor.");
        }

        return new $class();
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
        } catch (NotFoundServiceException $e) {
            if (\class_exists($id)) {
                return $this->callInstance($id);
            }

            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function has($id)
    {
        return $this->offsetExists($id);
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
            $config = $this->process->processConfiguration($provider, $values);

            $this[$provider->getName() . '.config'] = $config;
        }

        $provider->register($this);

        return $this;
    }

    /**
     * Add a clas or interface that should be excluded from autowiring.
     *
     * @param string[] $types
     */
    public function addExcludedTypes(array $types): void
    {
        foreach ($types as $type) {
            $this->resolver->addExcludedType($type);
        }
    }

    /**
     * @param object|callable $service
     */
    private function autowireService(string $id, $service): void
    {
        // Resolving the closure of the service to return it's type hint or class.
        $type = \is_callable($service) ? CallableReflection::create($service)->getReturnType() : \get_class($service);

        if ($type instanceof \ReflectionNamedType) {
            $type = $type->getName();
        }

        // Resolving wiring so we could call the service parent classes and interfaces.
        if (!$this->offsetExists($id)) {
            $this->resolver->addReturnType($id, $type);
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
        return $this->autowire->getParameters($function, $args);
    }
}
