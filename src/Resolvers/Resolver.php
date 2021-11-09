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

use Nette\Utils\{Callback, Type};
use PhpParser\BuilderFactory;
use PhpParser\Node\{Expr, Stmt, Scalar};
use Rade\DI\Exceptions\{ContainerResolutionException, NotFoundServiceException};
use Rade\DI\Definitions\{Reference, Statement, ValueDefinition};
use Rade\DI\{AbstractContainer, Services\ServiceLocator};
use Rade\DI\Builder\PhpLiteral;
use Rade\DI\Injector\{Injectable, InjectableInterface};
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
     * If true, exception will be thrown on resolvable services with are not typed.
     */
    public function setStrictAutowiring(bool $boolean = true): void
    {
        $this->strict = $boolean;
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
    public static function autowireService($definition, bool $allTypes = false, AbstractContainer $container = null): array
    {
        $types = $autowired = [];

        if (\is_callable($definition)) {
            $types = Type::fromReflection(Callback::toReflection($definition));
        } elseif (\is_string($definition)) {
            if (!\class_exists($definition)) {
                return $allTypes ? ['string'] : $types;
            }

            $types[] = $definition;
        } elseif (\is_object($definition)) {
            if ($definition instanceof \stdClass) {
                return $allTypes ? ['object'] : $types;
            }

            $types[] = \get_class($definition);
        } elseif (\is_array($definition)) {
            if (null !== $container && 2 === \count($definition, \COUNT_RECURSIVE)) {
                if ($definition[0] instanceof Reference) {
                    $types = Type::fromReflection(new \ReflectionMethod($container->definition((string) $definition[0])->getEntity(), $definition[1]));
                } elseif ($definition[0] instanceof Expr\BinaryOp\Coalesce) {
                    $types = Type::fromReflection(new \ReflectionMethod($container->definition($definition[0]->left->dim->value)->getEntity(), $definition[1]));
                }
            } else {
                return $allTypes ? ['array'] : [];
            }
        }

        if ($types instanceof Type) {
            $types = $types->getNames();
        }

        foreach (($types ?? []) as $type) {
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
            $resolved = $this->resolve($callback->getValue(), $callback->getArguments());

            if ($callback->isClosureWrappable()) {
                $resolved = null === $this->builder ? fn () => $resolved : new Expr\ArrowFunction(['expr' => $resolved]);
            }

            return $resolved;
        }

        if ($callback instanceof Reference) {
            $callback = $this->resolveReference((string) $callback);

            if (\is_callable($callback) || (\is_array($callback) && 2 === \count($callback, \COUNT_RECURSIVE))) {
                return $this->resolveCallable($callback, $args);
            }

            return $callback; // Expected to be resolved.
        }

        if ($callback instanceof ValueDefinition) {
            return $callback->getEntity();
        }

        if ($callback instanceof PhpLiteral) {
            $expression = $callback->resolve($this)[0];

            return $expression instanceof Stmt\Expression ? $expression->expr : $expression;
        }

        if (\is_string($callback)) {
            if (\str_contains($callback, '%')) {
                $callback = $this->container->parameter($callback);
            }

            if (\class_exists($callback)) {
                return $this->resolveClass($callback, $args);
            }

            if (\function_exists($callback)) {
                return $this->resolveCallable($callback, $args);
            }

            if (\str_contains($callback, '::') && \is_callable($callback)) {
                return $this->resolveCallable(\explode('::', $callback, 2), $args);
            }
        } elseif (\is_callable($callback) || \is_array($callback)) {
            return $this->resolveCallable($callback, $args);
        }

        return null === $this->builder ? $callback : $this->builder->val($callback);
    }

    /**
     * Resolves callables and array like callables.
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
            if (2 === \count($callback, \COUNT_RECURSIVE)) {
                $callback[0] = $this->resolve($callback[0]);

                if ($callback[0] instanceof Expr\BinaryOp\Coalesce) {
                    $type = [$this->container->definition($callback[0]->left->dim->value)->getEntity(), $callback[1]];
                } elseif ($callback[0] instanceof Expr\New_) {
                    $type = [(string) $callback[0]->class, $callback[1]];
                }

                if (isset($type) || \is_callable($callback)) {
                    goto create_callable;
                }
            }

            $callback = $this->resolveArguments($callback);

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
     * @param array<int|string,mixed> $arguments
     *
     * @return array<int|string,mixed>
     */
    public function resolveArguments(array $arguments = []): array
    {
        foreach ($arguments as $key => $value) {
            if ($value instanceof \stdClass) {
                $resolved = null === $this->builder ? $value : new Expr\Cast\Object_($this->builder->val($this->resolveArguments((array) $value)));
            } elseif (\is_array($value)) {
                $resolved = $this->resolveArguments($value);
            } elseif (\is_int($value)) {
                $resolved = null === $this->builder ? $value : new Scalar\LNumber($value);
            } elseif (\is_float($value)) {
                $resolved = null === $this->builder ? (int) $value : new Scalar\DNumber($value);
            } elseif (\is_numeric($value)) {
                $resolved = null === $this->builder ? (int) $value : Scalar\LNumber::fromString($value);
            } elseif (\is_string($value)) {
                if (\str_contains($value, '%')) {
                    $value = $this->container->parameter($value);
                }

                $resolved = null === $this->builder ? $value : new Scalar\String_($value);
            } else {
                $resolved = $this->resolve($value);
            }

            $arguments[$key] = $resolved;
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

            if (null === $builder = $this->builder) {
                return new ServiceLocator($services);
            }

            return $builder->new('\\' . ServiceLocator::class, [$services]);
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
            $arrayLike = \str_ends_with($value = \substr($value, 1), '[]');

            if (\is_int($id)) {
                $id = $arrayLike ? \substr($value, 0, -2) : $value;
            }

            return ($this->container->has($id) || $this->container->typed($id)) ? $this->resolveServiceSubscriber($id, $value) : [$id => $arrayLike ? [] : null];
        }

        $service = function () use ($value) {
            if ('[]' === \substr($value, -2)) {
                $service = $this->container->get(\substr($value, 0, -2), $this->container::IGNORE_MULTIPLE_SERVICE);

                return \is_array($service) ? $service : [$service];
            }

            return $this->container->get($value);
        };

        if (null !== $this->builder) {
            $service = new Expr\ArrowFunction(['expr' => $this->builder->val($service()), 'returnType' => '[]' !== \substr($value, -2) ? $value : 'array']);
        }

        return [\is_int($id) ? \rtrim($value, '[]') : $id => $service];
    }
}
