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

use Nette\Utils\{Callback, Reflection};
use PhpParser\BuilderFactory;
use PhpParser\Node\{Expr, Stmt, Scalar};
use PhpParser\Node\Scalar\String_;
use Rade\DI\Exceptions\{ContainerResolutionException, NotFoundServiceException};
use Symfony\Component\VarExporter\VarExporter;
use Symfony\Contracts\Service\{ServiceProviderInterface, ServiceSubscriberInterface};

/**
 * Class Resolver.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class Resolver
{
    private \WeakMap $cache;
    private bool $strict = true;

    public function __construct(private Container $container, private ?BuilderFactory $builder = null)
    {
        $this->cache = new \WeakMap();
    }

    /**
     * The method name generated for a service definition.
     */
    public static function createMethod(string $id): string
    {
        return 'get' . \str_replace(['.', '_', '\\'], '', \ucwords($id, '._'));
    }

    /**
     * @param bool $boolean If false and parameter type hint is a class, it will be auto resolved
     */
    public function setStrictAutowiring(bool $boolean = true): void
    {
        $this->strict = $boolean;
    }

    public static function autowireService(mixed $definition, bool $allTypes = false, Container $container = null): array
    {
        $types = $autowired = [];

        if (\is_callable($definition)) {
            $types = \array_filter(self::getTypes(Callback::toReflection($definition)), fn (string $v) => \class_exists($v) || \interface_exists($v) || $allTypes);
        } elseif (\is_object($definition)) {
            if ($definition instanceof \stdClass) {
                return $allTypes ? ['object'] : $types;
            }

            $types[] = \get_class($definition);
        } elseif (\is_string($definition)) {
            if (!(\class_exists($definition) || \interface_exists($definition))) {
                return $allTypes ? ['string'] : [];
            }

            $types[] = $definition;
        } elseif (\is_array($definition)) {
            if (null !== $container && 2 === \count($definition, \COUNT_RECURSIVE)) {
                if ($definition[0] instanceof Definitions\Reference) {
                    $def = $container->definition((string) $definition[0]);
                } elseif ($definition[0] instanceof Expr\BinaryOp\Coalesce) {
                    $def = $container->definition($definition[0]->left->dim->value);
                }

                if (isset($def)) {
                    if ($def instanceof Definition) {
                        $class = self::getDefinitionClass($def);

                        if (null === $class) {
                            return [];
                        }
                    }
                    $types = self::getTypes(new \ReflectionMethod($class ?? $def, $definition[1]));
                    goto resolve_types;
                }
            }

            return $allTypes ? ['array'] : [];
        }

        resolve_types:
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

        foreach ($function->getParameters() as $offset => $parameter) {
            $position = 0 === $nullValuesFound ? $offset : $parameter->name;
            $resolved = $args[$offset] ?? $args[$parameter->name] ?? null;
            $types = self::getTypes($parameter) ?: ['null'];

            if (\PHP_VERSION_ID >= 80100 && (\count($types) >= 1 && \is_subclass_of($enumType = $types[0], \BackedEnum::class))) {
                if (null === $resolved = ($resolved ?? $args[$enumType] ?? null)) {
                    throw new ContainerResolutionException(\sprintf('Missing value for enum parameter %s.', Reflection::toString($parameter)));
                }

                try {
                    $resolvedParameters[$position] = $enumType::from($resolved);
                } catch (\ValueError $e) {
                    throw new ContainerResolutionException(\sprintf('The "%s" value could not be resolved for enum parameter %s.', $resolved, Reflection::toString($parameter)), 0, $e);
                }
            } elseif (null === ($resolved = $resolved ?? $this->autowireArgument($parameter, $types, $args))) {
                if ($parameter->isDefaultValueAvailable()) {
                    ++$nullValuesFound;
                } elseif (!$parameter->isVariadic()) {
                    $resolvedParameters[$position] = self::getParameterDefaultValue($parameter, $types);
                }
            } elseif ($parameter->isVariadic() && \is_array($resolved)) {
                $resolvedParameters = \array_merge($resolvedParameters, $resolved);
            } else {
                $resolvedParameters[$position] = $resolved;
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
     */
    public function resolve($callback, array $args = []): mixed
    {
        if ($callback instanceof Definitions\Parameter) {
            if (!\array_key_exists($param = (string) $callback, $this->container->parameters)) {
                if (!$callback->isResolvable()) {
                    throw new ContainerResolutionException(\sprintf('The parameter "%s" is not defined.', $param));
                }

                return $this->cache[$callback] ??= $this->builder?->methodCall(new Expr\Variable('this'), 'parameter', [$param]) ?? $this->container->parameter($param);
            }
            $resolved = $this->cache[$callback] ??= $this->builder?->val(new Expr\ArrayDimFetch($this->builder->propertyFetch(new Expr\Variable('this'), 'parameters'), new String_($param))) ?? $this->container->parameters[$param];
        } elseif ($callback instanceof Definitions\Statement) {
            if (!isset($this->cache[$callback])) {
                $resolved = fn () => $this->resolve($callback->getValue(), $callback->getArguments() + $args);
                $this->cache[$callback] = !$callback->isClosureWrappable() ? $resolved() : $this->builder?->val(new Expr\ArrowFunction(['expr' => $resolved()])) ?? $resolved;
            }
            $resolved = $this->cache[$callback];
        } elseif ($callback instanceof Definitions\Reference) {
            $resolved = $this->cache[$callback] ??= $this->resolveReference((string) $callback);

            if (\is_callable($resolved) || \is_array($callback)) {
                $resolved = $this->resolveCallable($resolved, $args);
            } elseif (null === $resolved) {
                $callback = $resolved;
            }
        } elseif ($callback instanceof Definitions\TaggedLocator) {
            $resolved = $this->cache[$callback] ??= $this->resolve($callback->resolve($this->container));
        } elseif ($callback instanceof Definition) {
            if ($callback->hasContainer()) {
                throw new ContainerResolutionException(\sprintf('The definition "%s" has container set and cannot be resolved.', $callback->getId()));
            }

            if (null === $this->builder) {
                return $this->cache[$callback] ??= $callback->setContainer($this->container, 'anonymous', true)();
            }

            if (!isset($this->cache[$callback])) {
                $service = \array_map(
                    fn (\PhpParser\Node $v) => ($v instanceof Stmt\Return_ && $v->expr instanceof Expr\Assign) ? new Stmt\Return_($v->expr->expr) : $v,
                    $callback(true)->stmts
                );
                $this->cache[$callback] = 1 === \count($service) ? $service[0]->expr : new Expr\FuncCall(new Expr\Closure(['stmts' => $service]));
            }

            $resolved = $this->cache[$callback];
        } elseif ($callback instanceof Builder\PhpLiteral) {
            $expression = $this->cache[$callback] ??= $callback->resolve($this)[0];
            $resolved = $expression instanceof Stmt\Expression ? $expression->expr : $expression;
        } elseif (\is_string($callback)) {
            if (\str_contains($callback, '%')) {
                $callback = $this->container->parameter($callback);
            }

            if (\class_exists($callback)) {
                return $this->resolveClass($callback, $args);
            }

            if (\is_callable($callback)) {
                $resolved = $this->resolveCallable($callback, $args);
            }
        } elseif (\is_callable($callback) || \is_array($callback)) {
            $resolved = $this->resolveCallable($callback, $args);
        } elseif (\is_object($callback)) {
            if (null === $this->builder) {
                return $callback;
            }

            if ($callback instanceof \PhpParser\Node) {
                if (!$callback instanceof Expr\New_) {
                    $resolved = $callback instanceof Stmt\Expression ? $callback->expr : $callback;
                } elseif (\is_subclass_of($class = $callback->class->__toString(), Injector\InjectableInterface::class)) {
                    $resolved = $this->cache[$callback] ??= Injector\Injectable::getResolved($this, $callback, new \ReflectionClass($class));
                }
            } elseif ($callback instanceof \stdClass) {
                $resolved = $this->cache[$callback] ??= new Expr\Cast\Object_($this->builder->val($this->resolveArguments((array) $callback)));
            } elseif (($ref = new \ReflectionObject($callback))->hasMethod('__set_state')) {
                $args = \array_merge(...\array_map(function (\ReflectionProperty $p) use ($callback): mixed {
                    $p->setAccessible(true);

                    return $p->getValue($callback);
                }, $ref->getProperties()));
                $resolved = $this->builder->staticCall($ref->getName(), '__set_state', [$args]);
            } elseif (\class_exists(VarExporter::class)) {
                $resolved = $this->cache[$callback] ??= $this->resolve(new Builder\PhpLiteral(VarExporter::export($callback)));
            } else {
                $resolved = $this->cache[$callback] ??= $this->builder->funcCall('\\unserialize', [new String_(\serialize($callback), ['docLabel' => 'SERIALIZED', 'kind' => String_::KIND_NOWDOC])]);
            }
        }

        return $resolved ?? $this->builder?->val($callback) ?? $callback;
    }

    /**
     * Resolves callables and array like callables.
     *
     * @param callable|array<int,mixed> $callback
     * @param array<int|string,mixed>   $arguments
     *
     * @throws \ReflectionException if $callback is not a real callable
     */
    public function resolveCallable(callable|array $callback, array $arguments = []): mixed
    {
        if (\is_array($callback)) {
            if ((2 === \count($callback) && \array_is_list($callback)) && \is_string($callback[1])) {
                $callback[0] = $this->resolve($callback[0]);

                if ($callback[0] instanceof Expr\BinaryOp\Coalesce) {
                    $class = self::getDefinitionClass($this->container->definition($callback[0]->left->dim->value));

                    if (null !== $class) {
                        $type = [$class, $callback[1]];
                    }
                } elseif ($callback[0] instanceof Expr\New_) {
                    $type = [(string) $callback[0]->class, $callback[1]];
                }

                if (isset($type) || \is_callable($callback)) {
                    goto create_callable;
                }
            }

            $callback = $this->resolveArguments($callback);

            return $this->builder?->val($callback) ?? $callback;
        }

        create_callable:
        $args = $this->autowireArguments($ref = Callback::toReflection($type ?? $callback), $arguments);

        if ($ref instanceof \ReflectionFunction) {
            return $this->builder?->funcCall($callback, $args) ?? $ref->invokeArgs($args);
        }

        if ($ref->isStatic()) {
            $className = \is_array($callback) ? $callback[0] : $ref->getDeclaringClass()->getName();

            return $this->builder?->staticCall($className, $ref->getName(), $args) ?? $ref->invokeArgs(null, $args);
        }

        return $this->builder?->methodCall($callback[0], $ref->getName(), $args) ?? $callback(...$args);
    }

    /**
     * @param array<int|string,mixed> $args
     *
     * @throws ContainerResolutionException|\ReflectionException if class string unresolvable
     */
    public function resolveClass(string $class, array $args = []): object
    {
        if (\is_subclass_of($class, ServiceProviderInterface::class)) {
            static $services = [];

            foreach ($args as $name => $service) {
                $services += $this->resolveServiceSubscriber($name, (string) $service);
            }

            return $this->builder?->new('\\' . $class, [$services]) ?? new $class($services);
        }

        if (\is_subclass_of($class, ServiceSubscriberInterface::class)) {
            static $services = [];

            foreach ($class::getSubscribedServices() as $name => $service) {
                $services += $this->resolveServiceSubscriber($name, $service);
            }

            return $this->builder?->new('\\' . Services\ServiceLocator::class, [$services]) ?? new Services\ServiceLocator($services);
        }

        if (\method_exists($class, '__construct')) {
            $args = $this->autowireArguments(new \ReflectionMethod($class, '__construct'), $args);
        } elseif (!empty($args)) {
            throw new ContainerResolutionException(\sprintf('Unable to pass arguments, class "%s" has no constructor.', $class));
        }

        if (null === $this->builder) {
            try {
                $service = new $class(...$args);
            } catch (\Throwable $e) {
                throw new ContainerResolutionException(\sprintf('Class %s is an abstract type or instantiable.', $class), 0, $e);
            }
        } else {
            $service = $this->builder->new($class, $args);
            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract() || !$reflection->isInstantiable()) {
                throw new ContainerResolutionException(\sprintf('Class %s is an abstract type or instantiable.', $class));
            }
        }

        if (\is_subclass_of($class, Injector\InjectableInterface::class)) {
            $service = $this->cache[$service] ??= Injector\Injectable::getResolved($this, $service, $reflection ?? new \ReflectionClass($class));
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
            if (\is_array($value)) {
                $resolved = $this->resolveArguments($value);
            } elseif (\is_int($value)) {
                $resolved = $this->builder?->val(new Scalar\LNumber($value)) ?? $value;
            } elseif (\is_float($value)) {
                $resolved = $this->builder?->val(new Scalar\DNumber($value)) ?? (int) $value;
            } elseif (\is_numeric($value)) {
                $resolved = $this->builder?->val(Scalar\LNumber::fromString($value)) ?? (int) $value;
            } elseif (\is_string($value)) {
                if (\str_contains($value, '%')) {
                    $value = $this->container->parameter($value);
                }
                $resolved = $this->builder?->val($value) ?? $value;
            } elseif (\is_bool($value)) {
                $resolved = $this->builder?->val($value) ?? $value;
            } elseif (\is_callable($value)) {
                $resolved = $this->resolveCallable($value);
            } elseif (null === $value) {
                $resolved = $this->builder?->val(null) ?? $value;
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
     */
    public function get(string $id, bool $single = false): mixed
    {
        if ($this->container->typed($id)) {
            return $this->container->autowired($id, $single);
        }

        if (\is_subclass_of($id, ServiceSubscriberInterface::class)) {
            return $this->resolveClass($id);
        }

        if (!$this->strict && \class_exists($id)) {
            try {
                return $this->resolveClass($id);
            } catch (ContainerResolutionException) {
            }
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

    public function resolveReference(string $reference): mixed
    {
        $invalidBehavior = $this->container::EXCEPTION_ON_MULTIPLE_SERVICE;

        if ('?' === $reference[0]) {
            $invalidBehavior |= $this->container::NULL_ON_INVALID_SERVICE;
            $reference = \substr($reference, 1);
        }

        if (1 === \preg_match('/\[(.*?)?\]$/', $reference, $matches, \PREG_UNMATCHED_AS_NULL)) {
            $reference = \str_replace($matches[0], '', $reference);
            $autowired = $this->container->typed($reference, true);

            if (\is_numeric($k = $matches[1] ?? null) && isset($autowired[$k])) {
                return $this->container->get($autowired[$k], $invalidBehavior);
            }

            if (!empty($autowired)) {
                return \array_map([$this->container, 'get'], $autowired);
            }

            try {
                if (null === $service = $this->container->get($reference, $invalidBehavior)) {
                    return [];
                }

                return [$service];
            } catch (NotFoundServiceException $e) {
                goto type_get;
            }
        }

        try {
            return $this->container->get($reference, $invalidBehavior);
        } catch (NotFoundServiceException $e) {
            type_get:
            if (!$this->strict) {
                try {
                    $s = \class_exists($reference) ? $this->resolveClass($reference) : (\function_exists($reference) ? $this->resolveCallable($reference) : null);

                    if (null !== $s) {
                        return !isset($autowired) ? $s : [$s];
                    }
                } catch (ContainerResolutionException) {
                    // Skip error throwing while resolving
                }
            }

            throw $e;
        }
    }

    /**
     * Resolves services for ServiceLocator.
     *
     * @param int|string $id
     *
     * @return (\Closure|array|mixed|null)[]
     */
    public function resolveServiceSubscriber($id, string $value): array
    {
        $service = fn () => $this->resolveReference($value);

        if (null !== $this->builder) {
            $type = \rtrim(\ltrim($value, '?'), '[]');

            if ('[]' === \substr($value, -2)) {
                $returnType = 'array';
            } elseif ($this->container->has($type)) {
                $returnType = $this->container->definition($type)->getTypes()[0] ?? (
                    \class_exists($type) || \interface_exists($type)
                    ? $type
                    : (!\is_int($id) && (\class_exists($id) || \interface_exists($id)) ? $id : null)
                );
            } elseif (\class_exists($type) || \interface_exists($type)) {
                $returnType = $type;
            }

            $service = new Expr\ArrowFunction(['expr' => $this->builder->val($service()), 'returnType' => $returnType ?? null]);
        }

        return [\is_int($id) ? ($type ?? \rtrim(\ltrim($value, '?'), '[]')) : $id => $service];
    }

    /**
     * Resolves missing argument using autowiring.
     *
     * @param array<int|string,mixed> $providedParameters
     * @param array<int,string>       $types
     *
     * @throws ContainerResolutionException
     */
    public function autowireArgument(\ReflectionParameter $parameter, array $types, array $providedParameters): mixed
    {
        foreach ($types as $typeName) {
            if (\PHP_MAJOR_VERSION >= 8 && $attributes = $parameter->getAttributes()) {
                foreach ($attributes as $attribute) {
                    if (Attribute\Inject::class === $attribute->getName()) {
                        try {
                            return $attribute->newInstance()->resolve($this, $typeName);
                        } catch (NotFoundServiceException $e) {
                            // Ignore this exception ...
                        }
                    }

                    if (Attribute\Tagged::class === $attribute->getName()) {
                        return $this->resolveArguments($attribute->newInstance()->getValues($this->container));
                    }
                }
            }

            if (!Reflection::isBuiltinType($typeName)) {
                try {
                    return $providedParameters[$typeName] ?? $this->get($typeName, !$parameter->isVariadic());
                } catch (NotFoundServiceException $e) {
                    // Ignore this exception ...
                } catch (ContainerResolutionException $e) {
                    $errorException = new ContainerResolutionException(\sprintf("{$e->getMessage()} (needed by %s)", Reflection::toString($parameter)));
                }

                if (
                    ServiceProviderInterface::class === $typeName &&
                    null !== $class = $parameter->getDeclaringClass()
                ) {
                    if (!$class->implementsInterface(ServiceSubscriberInterface::class)) {
                        throw new ContainerResolutionException(\sprintf(
                            'Service of type %s needs parent class %s to implement %s.',
                            $typeName,
                            $class->getName(),
                            ServiceSubscriberInterface::class
                        ));
                    }

                    return $this->get($class->getName());
                }
            }

            if (
                ($method = $parameter->getDeclaringFunction()) instanceof \ReflectionMethod
                && \preg_match('#@param[ \t]+([\w\\\\]+)(?:\[\])?[ \t]+\$' . $parameter->name . '#', (string) $method->getDocComment(), $m)
                && ($itemType = Reflection::expandClassName($m[1], $method->getDeclaringClass()))
                && (\class_exists($itemType) || \interface_exists($itemType))
            ) {
                try {
                    if (\in_array($typeName, ['array', 'iterable'], true)) {
                        return $this->get($itemType);
                    }

                    if ('object' === $typeName || \is_subclass_of($itemType, $typeName)) {
                        return $this->get($itemType, true);
                    }
                } catch (NotFoundServiceException $e) {
                    // Ignore this exception ...
                }
            }

            if (isset($errorException)) {
                throw $errorException;
            }
        }

        return null;
    }

    /**
     * Returns an associated type to the given parameter if available.
     *
     * @param \ReflectionParameter|\ReflectionFunctionAbstract $reflection
     *
     * @return array<int,string>
     */
    public static function getTypes(\Reflector $reflection): array
    {
        if ($reflection instanceof \ReflectionParameter || $reflection instanceof \ReflectionProperty) {
            $type = $reflection->getType();
        } elseif ($reflection instanceof \ReflectionFunctionAbstract) {
            $type = $reflection->getReturnType() ?? (\PHP_VERSION_ID >= 80100 ? $reflection->getTentativeReturnType() : null);
        }

        if (!isset($type)) {
            return [];
        }

        $resolver = static function (\ReflectionNamedType $rName) use ($reflection): string {
            $function = $reflection instanceof \ReflectionParameter ? $reflection->getDeclaringFunction() : $reflection;

            if ($function instanceof \ReflectionMethod) {
                $lcName = \strtolower($rName->getName());

                if ('self' === $lcName || 'static' === $lcName) {
                    return $function->getDeclaringClass()->name;
                }

                if ('parent' === $lcName) {
                    return $function->getDeclaringClass()->getParentClass()->name;
                }
            }

            return $rName->getName();
        };

        if (!$type instanceof \ReflectionNamedType) {
            return \array_map($resolver, $type->getTypes());
        }

        return [$resolver($type)];
    }

    private static function getDefinitionClass(Definition $def): ?string
    {
        if (!\is_string($class = $def->getEntity())) {
            return null;
        }

        if (!\class_exists($class)) {
            foreach ($def->getTypes() as $typed) {
                if (\class_exists($typed)) {
                    return $typed;
                }
            }

            return null;
        }

        return $class;
    }

    /**
     * Get the parameter's allowed null else error.
     *
     * @throws \ReflectionException|ContainerResolutionException
     *
     * @return void|null
     */
    private static function getParameterDefaultValue(\ReflectionParameter $parameter, array $types)
    {
        if ($parameter->isOptional() || $parameter->allowsNull()) {
            return null;
        }

        $errorDescription = 'Parameter ' . Reflection::toString($parameter);

        if ('' === ($typedHint = \implode('|', $types))) {
            $errorDescription .= ' has no type hint or default value.';
        } elseif (\str_contains($typedHint, '|')) {
            $errorDescription .= ' has multiple type-hints ("' . $typedHint . '").';
        } elseif (\class_exists($typedHint)) {
            $errorDescription .= ' has an unresolved class-based type-hint ("' . $typedHint . '").';
        } elseif (\interface_exists($typedHint)) {
            $errorDescription .= ' has an unresolved interface-based type-hint ("' . $typedHint . '").';
        } else {
            $errorDescription .= ' has a type-hint ("' . $typedHint . '") that cannot be resolved, perhaps a you forgot to set it up?';
        }

        throw new ContainerResolutionException($errorDescription);
    }
}
