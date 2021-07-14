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
use Nette\Utils\Reflection;
use PhpParser\Node;
use Rade\DI\{
    AbstractContainer,
    Builder\Reference,
    ContainerBuilder,
    Exceptions\ContainerResolutionException,
    Exceptions\NotFoundServiceException,
    FallbackContainer,
    Services\ServiceLocator
};
use Rade\DI\Attribute\Inject;
use Symfony\Contracts\Service\ServiceSubscriberInterface;

/**
 * Class Resolver.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class Resolver
{
    private AbstractContainer $container;

    private AutowireValueResolver $resolver;

    public function __construct(AbstractContainer $container)
    {
        $this->container = $container;
        $this->resolver = new AutowireValueResolver();
    }

    /**
     * @param mixed $definition
     */
    public static function autowireService($definition): array
    {
        $types = $autowired = [];

        try {
            $types = Reflection::getReturnTypes(Callback::toReflection($definition));
        } catch (\ReflectionException $e) {
            if ($definition instanceof \stdClass) {
                return $types;
            }

            if (\is_string($definition) && \class_exists($definition)) {
                $types[] = $definition;
            } elseif (\is_object($definition)) {
                $types[] = \get_class($definition);
            }
        }

        foreach ($types as $type) {
            $autowired[] = $type;

            if (false === $parents = @\class_parents($type)) {
                continue;
            }

            $autowired = \array_merge($autowired, $parents, (\class_implements($type, false) ?: []));
        }

        return $autowired;
    }

    /**
     * Generates list of properties with #[Inject] attributes.
     *
     * @param \ReflectionProperty[] $properties
     *
     * @internal
     */
    public static function getInjectProperties(AbstractContainer $container, $properties): array
    {
        $res = [];

        foreach ($properties as $reflection) {
            if (\PHP_VERSION_ID >= 80000 && !empty($reflection->getAttributes(Inject::class))) {
                foreach (Reflection::getPropertyTypes($reflection) as $type) {
                    if ('null' === $type) {
                        // @codeCoverageIgnoreStart
                        continue;
                        // @codeCoverageIgnoreEnd
                    }

                    if ($container->has($type) || $container->typed($type)) {
                        $res[$reflection->getName()] = $container->get($type);
                    }
                }
            }
        }

        return $res;
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

        foreach ($function->getParameters() as $parameter) {
            $resolved = $this->resolver->resolve([$this, 'get'], $parameter, $args);

            if (\PHP_VERSION_ID >= 80000 && (null === $resolved && $parameter->isDefaultValueAvailable())) {
                ++$nullValuesFound;

                continue;
            }

            if ($parameter->isVariadic() && (\is_array($resolved) && \count($resolved) > 1)) {
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
        if (\is_callable($callback)) {
            $args = $this->autowireArguments($ref = Callback::toReflection($callback), $args);

            return $ref instanceof \ReflectionFunction ? $ref->invokeArgs($args) : (!$ref->isStatic() ? $callback(...$args) : $ref->invokeArgs(null, $args));
        }

        if (\is_string($callback)) {
            return $this->container->has($callback) ? $this->resolve($this->container->get($callback), $args) : $this->resolveClass($callback, $args);
        }

        if ((\is_array($callback) && \array_keys($callback) === [0, 1]) && $callback[0] instanceof Reference) {
            $callback[0] = $this->container->get((string) $callback[0]);

            if (\is_callable($callback[0])) {
                $callback[0] = $this->resolve($callback[0]);
            }

            return $this->resolve($callback, $args);
        }

        throw new ContainerResolutionException(\sprintf('Unable to resolve value provided \'%s\' in $callback parameter.', \get_debug_type($callback)));
    }

    /**
     * @param array<int|string,mixed> $args
     *
     * @throws ContainerResolutionException if class string unresolvable
     */
    public function resolveClass(string $class, array $args = []): object
    {
        /** @var class-string $class */
        $reflection = new \ReflectionClass($class);

        if ($reflection->isAbstract() || !$reflection->isInstantiable()) {
            throw new ContainerResolutionException(\sprintf('Class %s is an abstract type or instantiable.', $class));
        }

        if ((null !== $constructor = $reflection->getConstructor()) && $constructor->isPublic()) {
            $service = $reflection->newInstanceArgs($this->autowireArguments($constructor, $args));
        } else {
            if (!empty($args)) {
                throw new ContainerResolutionException("Unable to pass arguments, class $class has no constructor or constructor is not public.");
            }

            $service = $reflection->newInstance();
        }

        if (!$this->isBuilder()) {
            foreach (self::getInjectProperties($this->container, $reflection->getProperties(\ReflectionProperty::IS_PUBLIC)) as $property => $value) {
                $service->{$property} = $value;
            }

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (\PHP_VERSION_ID >= 80000 && !empty($method->getAttributes(Inject::class))) {
                    \call_user_func_array([$service, $method->name], $this->autowireArguments($method));
                }
            }
        }

        return $service;
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
                $services += $this->resolveServiceSubscriber(!\is_numeric($name) ? $name : $service, $service);
            }

            return !$this->isBuilder() ? new ServiceLocator($services) : new Node\Expr\New_(ServiceLocator::class, $services);
        }

        if ($this->container->typed($id)) {
            return $this->container->autowired($id, $single);
        }

        if ($this->container instanceof FallbackContainer) {
            return $this->container->get($id);
        }

        throw new NotFoundServiceException("Service of type '$id' not found. Check class name because it cannot be found.");
    }

    /**
     * Resolves services for ServiceLocator.
     *
     * @return (\Closure|array|mixed|null)[]
     */
    private function resolveServiceSubscriber(string $id, string $value): array
    {
        if ('?' === $value[0]) {
            $resolved = \substr($value, 1);

            if ($id === $value) {
                $id = $resolved;
            }

            if ('[]' === \substr($resolved, -2)) {
                $arrayLike = $resolved;
                $resolved = \substr($resolved, 0, -2);

                if ($this->container->has($resolved) || $this->container->typed($resolved)) {
                    return $this->resolveServiceSubscriber($id, $arrayLike);
                }
            }

            $service = fn () => ($this->container->has($resolved) || $this->container->typed($resolved)) ? $this->container->get($resolved) : null;

            return [$id => !$this->isBuilder() ? $service : $service()];
        }

        if ('[]' === \substr($value, -2)) {
            $resolved = \substr($value, 0, -2);
            $service = function () use ($resolved) {
                if ($this->container->typed($resolved)) {
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
