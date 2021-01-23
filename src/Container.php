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
     * @param iterable<ArgumentValueResolverInterface> $resolvers
     */
    public function __construct(iterable $resolvers = [])
    {
        $this->factories = new \SplObjectStorage();
        $this->process   = new Processor();

        $resolvers[]    = $this->resolver = new AutowireValueResolver($this);
        $this->autowire = new ArgumentResolver($resolvers);

        $this->offsetSet('container', $this);
    }

    /**
     * Sets a parameter or an object.
     *
     * Objects must be defined as Closures.
     *
     * Allowing any PHP callable leads to difficult to debug problems
     * as function names (strings) are callable (creating a function with
     * the same name as an existing parameter would break your container).
     *
     * @param string $id    The unique identifier for the parameter or object
     * @param mixed  $value The value of the parameter or a closure to define an object
     *
     * @throws FrozenServiceException Prevent override of a frozen service
     */
    public function offsetSet($id, $value): void
    {
        if (isset($this->frozen[$id])) {
            throw new FrozenServiceException($id);
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
            $this->autowireService($id, $value);
        }

        $this->values[$id] = $value;
        $this->keys[$id]   = true;
    }

    /**
     * Gets a parameter or an object.
     *
     * @param string $id The unique identifier for the parameter or object
     *
     * @throws NotFoundServiceException If the identifier is not defined
     *
     * @return mixed The value of the parameter or an object
     */
    public function offsetGet($id)
    {
        if (!isset($this->keys[$id])) {
            throw new NotFoundServiceException($id);
        }

        if (!\is_object($service = $this->values[$id])) {
            return $service;
        }

        if (isset($this->factories[$service])) {
            return $this->callMethod($service);
        }

        if (\is_callable($service)) {
            $service = $this->callMethod($service);
        }

        if (isset($this->creating[$id])) {
            throw new ContainerResolutionException(
                \sprintf('Circular reference detected for services: %s.', \implode(', ', \array_keys($this->creating)))
            );
        }

        try {
            $this->frozen[$id]   = true;
            $this->creating[$id] = true;

            return $this->values[$id] = $service;
        } finally {
            unset($this->creating[$id]);
        }
    }

    /**
     * Checks if a parameter or an object is set.
     *
     * @param string $id The unique identifier for the parameter or object
     *
     * @return bool
     */
    public function offsetExists($id)
    {
        return isset($this->keys[$id]);
    }

    /**
     * Unsets a parameter or an object.
     *
     * @param string $id The unique identifier for the parameter or object
     */
    public function offsetUnset($id): void
    {
        if (isset($this->keys[$id])) {
            if (\is_object($service = $this->values[$id])) {
                unset($this->factories[$service]);
            }

            unset($this->values[$id], $this->frozen[$id], $this->keys[$id]);
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
    public function factory(callable $callable)
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
     * @param string $id    The unique identifier for the object
     * @param mixed  $scope A service definition to extend the original
     *
     * @throws NotFoundServiceException If the identifier is not defined
     * @throws FrozenServiceException   If the service is frozen
     *
     * @return mixed The wrapped scope
     */
    public function extend($id, $scope)
    {
        if (!isset($this->keys[$id])) {
            throw new NotFoundServiceException($id);
        }

        if (isset($this->frozen[$id])) {
            throw new FrozenServiceException($id);
        }

        if (\is_callable($factory = $this->values[$id])) {
            $factory = $this->callMethod($factory);
        }

        $extended = $scope(...[$factory, $this]);

        if (isset($this->factories[$factory])) {
            $this->factories->detach($factory);
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
        $reflection = new \ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new ContainerResolutionException("Class $class is not instantiable.");
        }

        if ($constructor = $reflection->getConstructor()) {
            return $reflection->newInstanceArgs($this->autowireArguments($constructor, $args));
        }

        if ($args) {
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
