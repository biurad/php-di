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

use Nette\Utils\{Callback, Reflection};
use PhpParser\BuilderFactory;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use Rade\DI\Exceptions\{ContainerResolutionException, NotFoundServiceException};
use Rade\DI\Definitions\{Reference, Statement, ValueDefinition};
use Rade\DI\{AbstractContainer, Injectable, InjectableInterface, Services\ServiceLocator};
use Symfony\Contracts\Service\ServiceSubscriberInterface;

/**
 * Class Resolver.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class Resolver
{
    private AbstractContainer $container;

    private ?BuilderFactory $builder;

    private bool $strict = true;

    public function __construct(AbstractContainer $container, BuilderFactory $builder = null)
    {
        $this->builder = $builder;
        $this->container = $container;
    }

    /**
     * Allowing Strict rules, only resolves service types.
     */
    public function disableStrictRule(): void
    {
        $this->strict = false;
    }

    /**
     * The method name generated for a service definition.
     */
    public function createMethod(string $id): string
    {
        return 'get' . \str_replace(['.', '_', '\\'], '', \ucwords($id, '._'));
    }

    /**
     * @param mixed $definition
     */
    public static function autowireService($definition): array
    {
        $types = $autowired = [];

        if ($definition instanceof \stdClass) {
            return $types;
        }

        if (\is_callable($definition)) {
            $types = Reflection::getReturnTypes(Callback::toReflection($definition));
        } elseif (\is_string($definition) && \class_exists($definition)) {
            $types[] = $definition;
        } elseif (\is_object($definition)) {
            $types[] = \get_class($definition);
        }

        foreach ($types as $type) {
            $autowired[] = $type;

            foreach (\class_implements($type) ?: [] as $interface) {
                $autowired[] = $interface;
            }

            foreach (\class_parents($type) ?: [] as $parent) {
                $autowired[] = $parent;
            }
        }

        return $autowired;
    }

    /**
     * Resolves arguments for callable.
     *
     * @param array<int|string,mixed> $args
     *
     * @return array<int,mixed>
     */
    public function autowireArguments(\ReflectionFunctionAbstract $function, array $args = []): array
    {
        $resolvedParameters = [];
        $nullValuesFound = 0;
        $args = $this->resolveArguments($args); // Resolves provided arguments.

        foreach ($function->getParameters() as $parameter) {
            $resolved = AutowireValueResolver::resolve([$this, 'get'], $parameter, $args);

            if (null === $resolved && $parameter->isDefaultValueAvailable()) {
                ++$nullValuesFound;

                continue;
            }

            if ($parameter->isVariadic() && \is_array($resolved)) {
                $resolvedParameters = \array_merge($resolvedParameters, $resolved);

                continue;
            }

            $position = \PHP_VERSION_ID >= 80000 && $nullValuesFound > 0 ? $parameter->getName() : $parameter->getPosition();
            $resolvedParameters[$position] = $resolved;
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
        if ($callback instanceof Statement) {
            return $this->resolve($callback->getValue(), $callback->getArguments());
        }

        if ($callback instanceof Reference) {
            $callback = $this->resolveReference((string) $callback);

            if (\is_callable($callback)) {
                return $this->resolveCallable($callback, $args);
            }
        }

        if (\is_string($callback)) {
            if (\str_contains($callback, '%')) {
                $callback = $this->container->parameter($callback);
            }

            if (\class_exists($callback)) {
                return $this->resolveClass($callback, $args);
            }

            if (\str_contains($callback, '::') && \is_callable($callback)) {
                $callback = \explode('::', $callback, 2);
            }
        } elseif (\is_callable($callback) || \is_array($callback)) {
            return $this->resolveCallable($callback, $args);
        }

        unresolved:
        return null === $this->builder ? $callback : $this->builder->val($callback);
    }

    /**
     * Undocumented function.
     *
     * @param callable|array<int,mixed> $callback
     * @param array<int|string,mixed>   $arguments
     *
     * @throws \ReflectionException if $callback is not a real callable
     *
     * @return mixed
     */
    public function resolveCallable($callback, array $arguments = [])
    {
        if (\is_array($callback)) {
            if (\array_keys($callback) === [0, 1]) {
                $callback[0] = $this->resolve($callback[0]);

                if ($callback[0] instanceof Expr\BinaryOp\Coalesce) {
                    $type = [$this->container->definition($callback[0]->left->dim->value)->getEntity(), $callback[1]];

                    goto create_callable;
                }

                if (\is_callable($callback)) {
                    goto create_callable;
                }
            }

            return null === $this->builder ? $callback : $this->builder->val($callback);
        }

        create_callable:
        $args = $this->autowireArguments($ref = Callback::toReflection($type ?? $callback), $arguments);

        if ($ref instanceof \ReflectionFunction) {
            return null === $this->builder ? $ref->invokeArgs($args) : $this->builder->funcCall($callback, $args);
        }

        if ($ref->isStatic()) {
            return null === $this->builder ? $ref->invokeArgs(null, $args) : $this->builder->staticCall($callback[0], $ref->getName(), $args);
        }

        return null === $this->builder ? $callback(...$args) : $this->builder->methodCall($callback[0], $ref->getName(), $args);
    }

    /**
     * @param array<int|string,mixed> $args
     *
     * @throws ContainerResolutionException|\ReflectionException if class string unresolvable
     */
    public function resolveClass(string $class, array $args = []): object
    {
        /** @var class-string $class */
        $reflection = new \ReflectionClass($class);

        if ($reflection->isAbstract() || !$reflection->isInstantiable()) {
            throw new ContainerResolutionException(\sprintf('Class %s is an abstract type or instantiable.', $class));
        }

        if (null === $constructor = $reflection->getConstructor()) {
            if (!empty($args)) {
                throw new ContainerResolutionException(\sprintf('Unable to pass arguments, class "%s" has no constructor.', $class));
            }

            $service = null === $this->builder ? $reflection->newInstanceWithoutConstructor() : $this->builder->new($class);
        } else {
            $args = $this->autowireArguments($constructor, $args);
            $service = null === $this->builder ? $reflection->newInstanceArgs($args) : $this->builder->new($class, $args);
        }

        if ($reflection->implementsInterface(InjectableInterface::class)) {
            return Injectable::getProperties($this, $service, $reflection);
        }

        return $service;
    }

    /**
     * @param array<int|string,mixed> $args
     *
     * @return array<int|string,mixed>
     */
    public function resolveArguments(array $arguments = []): array
    {
        foreach ($arguments as $key => $value) {
            if (\is_array($value)) {
                $arguments[$key] = $this->resolveArguments($value);

                continue;
            }

            if (\is_numeric($value)) {
                $arguments[$key] = (int) $value;

                continue;
            }

            if ($value instanceof ValueDefinition) {
                $value = $value->getEntity();
            }

            $arguments[$key] = $this->resolve($value);
        }

        return $arguments;
    }

    /**
     * Resolves service by type.
     *
     * @param string $id A class or an interface name
     *
     * @return mixed
     */
    public function get(string $id, bool $single = false)
    {
        if (\is_subclass_of($id, ServiceSubscriberInterface::class)) {
            static $services = [];

            foreach ($id::getSubscribedServices() as $name => $service) {
                $services += $this->resolveServiceSubscriber($name, $service);
            }

            return null === $this->builder ? new ServiceLocator($services) : $this->builder->new(ServiceLocator::class, $services);
        }

        if (!$this->strict) {
            return $this->container->get($id, $single ? $this->container::EXCEPTION_ON_MULTIPLE_SERVICE : $this->container::IGNORE_MULTIPLE_SERVICE);
        }

        if ($this->container->typed($id)) {
            return $this->container->autowired($id, $single);
        }

        throw new NotFoundServiceException(\sprintf('Service of type "%s" not found. Check class name because it cannot be found.', $id));
    }

    /**
     * Gets the PHP's parser builder.
     */
    public function getBuilder(): ?BuilderFactory
    {
        return $this->builder;
    }

    /**
     * @return mixed
     */
    private function resolveReference(string $reference)
    {
        if ('?' === $reference[0]) {
            $invalidBehavior = $this->container::EXCEPTION_ON_MULTIPLE_SERVICE;
            $reference = \substr($reference, 1);

            if ($arrayLike = \str_ends_with('[]', $reference)) {
                $reference = \substr($reference, 0, -2);
                $invalidBehavior = $this->container::IGNORE_MULTIPLE_SERVICE;
            }

            if ($this->container->has($reference) || $this->container->typed($reference)) {
                return $this->container->get($reference, $invalidBehavior);
            }

            return $arrayLike ? [] : null;
        }

        if ('[]' === \substr($reference, -2)) {
            return $this->container->get(\substr($reference, 0, -2), $this->container::IGNORE_MULTIPLE_SERVICE);
        }

        return $this->container->get($reference);
    }

    /**
     * Resolves services for ServiceLocator.
     *
     * @param int|string $id
     *
     * @return (\Closure|array|mixed|null)[]
     */
    private function resolveServiceSubscriber($id, string $value): array
    {
        if ('?' === $value[0]) {
            $arrayLike = \str_ends_with('[]', $value = \substr($value, 1));

            if (\is_int($id)) {
                $id = $arrayLike ? \substr($value, 0, -2) : $value;
            }

            return ($this->container->has($id) || $this->container->typed($id)) ? $this->resolveServiceSubscriber($id, $value) : ($arrayLike ? [] : null);
        }

        if ('[]' === \substr($value, -2)) {
            $service = fn (): array => $this->container->get(\substr($value, 0, -2), $this->container::IGNORE_MULTIPLE_SERVICE);
        } else {
            $service = fn () => $this->container->get($value);
        }

        return [\is_int($id) ? $value : $id => (null === $this->builder ? $service : new ArrowFunction(['expr' => $service()]))];
    }
}
