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

namespace Rade\DI\Resolvers;

use Nette\Utils\Callback;
use Psr\Container\ContainerInterface;
use Rade\DI\{
    Builder\Reference,
    Builder\Statement,
    ContainerBuilder,
    Definition,
    Exceptions\ContainerResolutionException,
    Exceptions\NotFoundServiceException,
    FallbackContainer,
    RawDefinition,
    Services\ServiceLocator
};
use Symfony\Contracts\Service\{
    ResetInterface, ServiceProviderInterface, ServiceSubscriberInterface
};

/**
 * Class Resolver
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class Resolver implements ContainerInterface, ResetInterface
{
    private ContainerInterface $container;

    private AutowireValueResolver $resolver;

    /** @var array type => services */
    private array $wiring;

    /** @var array<string,bool> of classes excluded from autowiring */
    private array $excluded = [
        \ArrayAccess::class => true,
        \Countable::class => true,
        \IteratorAggregate::class => true,
        \SplDoublyLinkedList::class => true,
        \stdClass::class => true,
        \SplStack::class => true,
        \Stringable::class => true,
        \Iterator::class => true,
        \Traversable::class => true,
        \Serializable::class => true,
        \JsonSerializable::class => true,
        ServiceProviderInterface::class => true,
        ResetInterface::class => true,
        ServiceLocator::class => true,
        RawDefinition::class => true,
        Reference::class => true,
        Definition::class => true,
        Statement::class => true,
    ];

    public function __construct(ContainerInterface $container, array $wiring = [])
    {
        $this->wiring    = $wiring;
        $this->container = $container;
        $this->resolver  = new AutowireValueResolver();
    }

    /**
     * Resolve wiring classes + interfaces.
     *
     * @param string   $id
     * @param string[] $types
     */
    public function autowire(string $id, array $types): void
    {
        foreach ($types as $type) {
            if (false === $parents = @\class_parents($type)) {
                continue;
            }

            $parents += (\class_implements($type, false) ?: []) + [$type];

            foreach ($parents as $resolved) {
                if ($this->excluded[$resolved] ?? \in_array($id, $this->find($resolved), true)) {
                    continue;
                }

                $this->wiring[$resolved][] = $id;
            }
        }
    }

    /**
     * Resolves arguments for callable
     *
     * @param \ReflectionFunctionAbstract $function
     * @param array<int|string,mixed>     $args
     *
     * @return array<int,mixed>
     */
    public function autowireArguments(\ReflectionFunctionAbstract $function, array $args = []): array
    {
        $resolvedParameters   = [];
        $reflectionParameters = $function->getParameters();

        foreach ($reflectionParameters as $parameter) {
            $resolved = $this->resolver->resolve([$this, 'get'], $parameter, $args);

            if ($parameter->isVariadic() && (\is_array($resolved) && \count($resolved) > 1)) {
                foreach (\array_chunk($resolved, 1) as $index => [$value]) {
                    $resolvedParameters[$index + 1] = $value;
                }

                continue;
            }

            $resolvedParameters[$parameter->getPosition()] = $resolved;

            if (empty(\array_diff_key($reflectionParameters, $resolvedParameters))) {
                // Stop traversing: all parameters are resolved
                return $resolvedParameters;
            }
        }

        return $resolvedParameters;
    }

    /**
     * Resolve a service definition, class string, invocable object or callable
     * using autowiring.
     *
     * @param string|callable|object  $callback
     * @param array<int|string,mixed> $args
     *
     * @throws ContainerResolutionException|\ReflectionException if unresolvable
     *
     * @return mixed
     */
    public function resolve($callback, array $args = [])
    {
        if (\is_callable($callback)) {
            $args = $this->autowireArguments($ref = Callback::toReflection($callback), $args);

            if ($ref instanceof \ReflectionFunction) {
                return $ref->invokeArgs($args);
            }

            return !$ref->isStatic() ? $callback(...$args) : $ref->invokeArgs(null, $args);
        }

        if (\is_string($callback)) {
            if ($this->container->has($callback)) {
                return $this->resolve($this->container->get($callback), $args);
            }

            return $this->resolveClass($callback, $args);
        }

        if ((\is_array($callback) && \count($callback) === 2) && \is_string($callback[0])) {
            $callback[0] = $this->container->get((string) $callback[0]);

            if (\is_callable($callback[0])) {
                $callback[0] = $this->resolve($callback[0]);
            }

            return $this->resolve($callback, $args);
        }

        throw new ContainerResolutionException(
            sprintf('Unable to resolve value provided \'%s\' in $callback parameter.', \get_debug_type($callback))
        );
    }

    /**
     * @param string                  $class
     * @param array<int|string,mixed> $args
     *
     * @throws ContainerResolutionException if class string unresolvable
     *
     * @return object
     */
    public function resolveClass(string $class, array $args = []): object
    {
        /** @var class-string $class */
        $reflection = new \ReflectionClass($class);

        if ($reflection->isAbstract() || !$reflection->isInstantiable()) {
            throw new ContainerResolutionException(\sprintf('Class %s is an abstract type or instantiable.', $class));
        }

        if ((null !== $constructor = $reflection->getConstructor()) && $constructor->isPublic()) {
            return $reflection->newInstanceArgs($this->autowireArguments($constructor, $args));
        }

        if (!empty($args)) {
            throw new ContainerResolutionException("Unable to pass arguments, class $class has no constructor or constructor is not public.");
        }

        return $reflection->newInstance();
    }

    /**
     * Resolves service by type.
     *
     * @param string $id A class or an interface name
     * @param bool   $single
     *
     * @return mixed
     */
    public function get(string $id, bool $single = false)
    {
        if (\is_subclass_of($id, ServiceSubscriberInterface::class)) {
            static $services = [];

            foreach ($id::getSubscribedServices() as $name => $service) {
                $services += $this->resolveServiceSubscriber(\is_int($name) ? $service : $name, $service);
            }

            return !$this->isBuilder() ? new ServiceLocator($services) : $this->container->getBuilder()->new(ServiceLocator::class, $services);
        }

        if (!empty($autowired = $this->wiring[$id] ?? '')) {
            if (\count($autowired) === 1) {
                if ('container' !== $id = \reset($autowired)) {
                    return $single ? $this->container->get($id) : [$this->container->get($id)];
                }

                return !$this->isBuilder() ? $this->container : $this->container->getBuilder()->var('this');
            }

            if (!$single) {
                return \array_map([$this->container, 'get'], $autowired);
            }
            \natsort($autowired);

            throw new ContainerResolutionException(
                \sprintf('Multiple services of type %s found: %s.', $id, \implode(', ', $autowired))
            );
        }

        if ($this->container instanceof FallbackContainer) {
            return $this->container->get($id);
        }

        throw new NotFoundServiceException("Service of type '$id' not found. Check class name because it cannot be found.");
    }

    /**
     * Check if service type exist
     *
     * @param string $id A class or an interface name
     */
    public function has(string $id): bool
    {
        return isset($this->wiring[$id]);
    }

    /**
     * Clears all available autowired types.
     */
    public function reset(): void
    {
        $this->wiring = [];
    }

    /**
     * Return the list of service ids registered to a type.
     *
     * @param string $id A class or an interface name
     *
     * @return string[]
     */
    public function find(string $id): array
    {
        return $this->wiring[$id] ?? [];
    }

    /**
     * Add a class or interface that should be excluded from autowiring.
     */
    public function exclude(string $type): void
    {
        $this->excluded[$type] = true;
    }

    /**
     * Export the array containing services parent classes and interfaces.
     */
    public function export(): array
    {
        return $this->wiring;
    }

    /**
     * Return the PS11 container aiding autowiring.
     */
    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * Resolves services for ServiceLocator.
     *
     * @return array<string,mixed>
     */
    private function resolveServiceSubscriber(string $id, string $value): array
    {
        if ($value[0] === '?') {
            $resolved  = \substr($value, 1);

            if ($id === $value) {
                $id = $resolved;
            }

            if (\substr($resolved, -2) === '[]') {
                $arrayLike = $resolved;
                $resolved  = \substr($resolved, 0, -2);

                if ($this->container->has($resolved) || $this->has($resolved)) {
                    return $this->resolveServiceSubscriber($id, $arrayLike);
                }
            }

            $service  = fn () => ($this->container->has($resolved) || $this->has($resolved)) ? $this->container->get($resolved) : null;

            return [$id => !$this->isBuilder() ? $service : $service()];
        }

        if (\substr($value, -2) === '[]') {
            $resolved = \substr($value, 0, -2);
            $service  = function () use ($resolved) {
                if ($this->has($resolved)) {
                    return $this->get($resolved);
                }

                return [$this->container->get($resolved)];
            };

            return [$id === $value ? $resolved : $id => !$this->isBuilder() ? $service : $service()];
        }

        $service = fn () => $this->container->get($value);

        return [$id => !$this->isBuilder() ? $service : $service()];
    }

    private function isBuilder(): bool
    {
        return $this->container instanceof ContainerBuilder;
    }
}
