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
use Rade\DI\Exceptions\{ContainerResolutionException, NotFoundServiceException};
use Symfony\Contracts\Service\{ServiceProviderInterface, ServiceSubscriberInterface};

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

    /** @var array<string,\PhpParser\Node> */
    private array $literalCache = [];

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
            $definition = \Closure::fromCallable($definition);
        }

        if ($definition instanceof \Closure) {
            $definition = Callback::unwrap($definition);
            $types = self::getTypes(\is_array($definition) ? new \ReflectionMethod($definition[0], $definition[1]) : new \ReflectionFunction($definition));
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
                    $types = self::getTypes(new \ReflectionMethod($def instanceof Definitions\DefinitionInterface ? $def->getEntity() : $def, $definition[1]));

                    goto resolve_types;
                }
            }

            return $allTypes ? ['array'] : [];
        }

        if (\is_callable($definition)) {
            $types = self::getTypes(Callback::toReflection($definition));
        } elseif (\is_string($definition)) {
            if (!(\class_exists($definition) || \interface_exists($definition))) {
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
                if ($definition[0] instanceof Definitions\Reference) {
                    $types = self::getTypes(new \ReflectionMethod($container->definition((string) $definition[0])->getEntity(), $definition[1]));
                } elseif ($definition[0] instanceof Expr\BinaryOp\Coalesce) {
                    $types = self::getTypes(new \ReflectionMethod($container->definition($definition[0]->left->dim->value)->getEntity(), $definition[1]));
                }
            } else {
                return $allTypes ? ['array'] : [];
            }
        }

        resolve_types:
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

        foreach ($function->getParameters() as $offset => $parameter) {
            $position = 0 === $nullValuesFound ? $offset : $parameter->name;
            $resolved = $args[$offset] ?? $args[$parameter->name] ?? null;
            $types = self::getTypes($parameter);

            if (\PHP_VERSION_ID >= 80100 && (\count($types) > 1 && \is_subclass_of($enumType = $types[0], \BackedEnum::class))) {
                if (null === ($resolved = $resolved ?? $providedParameters[$enumType] ?? null)) {
                    throw new ContainerResolutionException(\sprintf('Missing parameter %s.', Reflection::toString($parameter)));
                }
                $resolvedParameters[$position] = $enumType::from($resolved);

                continue;
            }

            if (null === ($resolved = $resolved ?? $this->autowireArgument($parameter, $types, $args))) {
                if ($parameter->isDefaultValueAvailable()) {
                    if (\PHP_MAJOR_VERSION < 8) {
                        $resolvedParameters[$position] = Reflection::getParameterDefaultValue($parameter);
                    } else {
                        ++$nullValuesFound;
                    }
                } elseif (!$parameter->isVariadic()) {
                    $resolvedParameters[$position] = self::getParameterDefaultValue($parameter, $types);
                }

                continue;
            }

            if ($parameter->isVariadic() && \is_array($resolved)) {
                $resolvedParameters = \array_merge($resolvedParameters, $resolved);

                continue;
            }

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
        if ($callback instanceof Definitions\Statement) {
            if (Services\ServiceLocator::class == ($value = $callback->getValue())) {
                $services = [];

                foreach (($callback->getArguments() ?: $args) as $name => $service) {
                    $services += $this->resolveServiceSubscriber($name, (string) $service);
                }

                $resolved = null === $this->builder ? new Services\ServiceLocator($services) : $this->builder->new('\\' . Services\ServiceLocator::class, [$services]);
            } else {
                $resolved = $this->resolve($value, $callback->getArguments() ?: $args);

                if ($callback->isClosureWrappable()) {
                    $resolved = null === $this->builder ? fn () => $resolved : new Expr\ArrowFunction(['expr' => $resolved]);
                }
            }
        } elseif ($callback instanceof Definitions\Reference) {
            $resolved = $this->resolveReference((string) $callback);

            if (\is_callable($resolved) || (\is_array($resolved) && 2 === \count($resolved, \COUNT_RECURSIVE))) {
                $resolved = $this->resolveCallable($resolved, $args);
            }
        } elseif ($callback instanceof Definitions\ValueDefinition) {
            $resolved = $callback->getEntity();
        } elseif ($callback instanceof Builder\PhpLiteral) {
            $expression = $this->literalCache[\spl_object_id($callback)] ??= $callback->resolve($this)[0];
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
            } elseif (null !== $resolvedType = $this->container->convert($callback)) {
                $resolved = $resolvedType;
            }
        } elseif (\is_callable($callback) || \is_array($callback)) {
            $resolved = $this->resolveCallable($callback, $args);
        }

        return $resolved ?? (null === $this->builder ? $callback : $this->builder->val($callback));
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
            $className = \is_array($callback) ? $callback[0] : $ref->getDeclaringClass()->getName();

            return null === $this->builder ? $ref->invokeArgs(null, $args) : $this->builder->staticCall($className, $ref->getName(), $args);
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

        if ($reflection->implementsInterface(Injector\InjectableInterface::class)) {
            return Injector\Injectable::getProperties($this, $service, $reflection);
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

                $resolved = null === $this->builder ? $value : $this->builder->val($value);
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
                return new Services\ServiceLocator($services);
            }

            return $builder->new('\\' . Services\ServiceLocator::class, [$services]);
        }

        if (!$this->strict) {
            return $this->container->get($id, $single ? $this->container::EXCEPTION_ON_MULTIPLE_SERVICE : $this->container::IGNORE_MULTIPLE_SERVICE);
        }

        if ($this->container->typed($id)) {
            return $this->container->autowired($id, $single);
        }

        if (null !== $resolvedType = $this->container->convert($id)) {
            return $resolvedType;
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
            if ($this->container->has($value)) {
                $returnType = $this->container->definition($value)->getTypes()[0] ?? (\class_exists($id) || \interface_exists($id) ? $id : null);
            } elseif ('[]' !== \substr($value, -2)) {
                $returnType = 'array';
            }

            $service = new Expr\ArrowFunction(['expr' => $this->builder->val($service()), 'returnType' => $returnType ?? null]);
        }

        return [\is_int($id) ? \rtrim($value, '[]') : $id => $service];
    }

    /**
     * Resolves missing argument using autowiring.
     *
     * @param array<int|string,mixed> $providedParameters
     * @param array<int,string>       $types
     *
     * @throws ContainerResolutionException
     *
     * @return mixed
     */
    private function autowireArgument(\ReflectionParameter $parameter, array $types, array $providedParameters)
    {
        foreach ($types as $typeName) {
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

            if (\PHP_MAJOR_VERSION >= 8 && $attributes = $parameter->getAttributes()) {
                foreach ($attributes as $attribute) {
                    if (Attribute\Inject::class === $attribute->getName()) {
                        if (null === $attrName = $attribute->getArguments()[0] ?? null) {
                            throw new ContainerResolutionException(\sprintf('Using the Inject attribute on parameter %s requires a value to be set.', $parameter->getName()));
                        }

                        if ($arrayLike = \str_ends_with($attrName, '[]')) {
                            $attrName = \substr($attrName, 0, -2);
                        }

                        try {
                            return $this->get($attrName, !$arrayLike);
                        } catch (NotFoundServiceException $e) {
                            // Ignore this exception ...
                        }
                    }
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
    private static function getTypes(\Reflector $reflection): array
    {
        if ($reflection instanceof \ReflectionParameter) {
            $type = $reflection->getType();
        } elseif ($reflection instanceof \ReflectionFunctionAbstract) {
            $type = $reflection->getReturnType() ?? (PHP_VERSION_ID >= 80100 ? $reflection->getTentativeReturnType() : null);
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

    /**
     * Get the parameter's allowed null else error.
     *
     * @throws \ReflectionException|ContainerResolutionException
     *
     * @return null
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
            $errorDescription .= ' has a type-hint ("' . $typedHint  . '") that cannot be resolved, perhaps a you forgot to set it up?';
        }

        throw new ContainerResolutionException($errorDescription);
    }
}
