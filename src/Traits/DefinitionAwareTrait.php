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

namespace Rade\DI\Traits;

use Nette\Utils\Callback;
use PhpParser as p;
use Rade\DI\Container;
use Rade\DI\Definitions\Reference;
use Rade\DI\Definitions\Statement;
use Rade\DI\Exceptions\ContainerResolutionException;
use Rade\DI\Injector\Injectable;
use Rade\DI\Resolver;

/**
 * DefinitionAware trait.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
trait DefinitionAwareTrait
{
    private mixed $entity;
    private ?string $id = null;
    private ?Container $container = null;

    /** @var array<string,array<int|string,mixed>> */
    private array $calls = [];

    /** @var array<string,mixed> */
    private array $options = [];
    private array $deprecation = [];

    /**
     * @return mixed|\PhpParser\Node\Stmt\ClassMethod
     */
    public function resolve(Resolver $resolver, bool $createMethod = false): mixed
    {
        if (($this->options['abstract'] ?? false)) {
            throw new ContainerResolutionException(\sprintf('Resolving an abstract definition %s is not allowed.', $this->id));
        }

        if (null === $builder = $resolver->getBuilder()) {
            if ([] !== $this->deprecation) {
                $deprecation = $this->getDeprecation();
                \trigger_deprecation($deprecation['package'], $deprecation['version'], $deprecation['message']);
            }

            if (\is_callable($entity = $this->entity)) {
                $entity = $resolver->resolveCallable($entity, $this->arguments);
            } elseif (\is_string($entity)) {
                $entity = $resolver->resolveClass(!\str_contains($entity, '%') ? $entity : $this->container->parameter($entity), $this->arguments);
            } elseif (!\is_object($entity)) {
                $entity = $resolver->resolve($entity, $this->arguments);
            }

            if ([] !== $this->calls) {
                foreach ($this->calls['a'] ?? [] as $property => $propertyValue) {
                    $propertyValue = $resolver->resolve($propertyValue);

                    if (\str_contains($property, '|')) {
                        $pR = $entity;
                        $pL = \count($properties = \explode('|', $property)) - 1;

                        foreach ($properties as $pI => $p) {
                            $pL !== $pI ? $pR = $pR->{$p} : $pR->{$p} = $propertyValue;
                        }
                    } else {
                        $entity->{$property} = $propertyValue;
                    }
                }

                foreach ($this->calls['b'] ?? [] as $name => $methods) {
                    if (\str_contains($name, '|')) {
                        $mR = $entity;
                        $mL = \count($names = \explode('|', $name)) - 1;

                        foreach ($names as $mI => $m) {
                            if ($mL !== $mI) {
                                $mR = $resolver->resolveCallable([$mR, $m]);
                            } else {
                                foreach ($methods as $value) {
                                    $resolver->resolveCallable([$mR, $m], \is_array($value) ? $value : (null === $value ? [] : [$value]));
                                }
                            }
                        }
                        continue;
                    }

                    foreach ($methods as $value) {
                        $resolver->resolveCallable([$entity, $name], \is_array($value) ? $value : (null === $value ? [] : [$value]));
                    }
                }

                foreach ($this->calls['c'] ?? [] as [$code, $extend]) {
                    $resolver->resolve($code, $extend ? [$entity] : []);
                }
            }

            return $entity;
        }

        return $this->build($resolver, $builder, $createMethod);
    }

    /**
     * Binds container and id into this definition.
     *
     * @return $this
     */
    public function setContainer(Container $container, string $id, bool $anonymous = false)
    {
        $this->container = $container;
        $this->id = $id;

        if (!$anonymous && !empty($this->options)) {
            if (isset($this->options['aliases'])) {
                foreach ($this->options['aliases'] as $alias => $v) {
                    $this->container->alias($alias, $this->id);
                }
                unset($this->options['aliases']);
            }

            if (isset($this->options['types'])) {
                $this->container->type($this->id, ...\array_keys($this->options['types']));
                unset($this->options['types']);
                $this->options['typed'] = true;
            }

            if (isset($this->options['tags'])) {
                foreach ($this->options['tags'] as $tag => $value) {
                    $this->container->tag($this->id, $tag, $value);
                }
                unset($this->options['tags']);
            }
        }

        return $this;
    }

    protected function build(Resolver $resolver, p\BuilderFactory $builder, bool $createMethod): p\Node\Expr|p\Node\Stmt\ClassMethod
    {
        $defNode = $builder->method($resolver::createMethod($this->id))->makeProtected();
        $defTyped = $defNodes = [];

        foreach (($this->getTypes() ?: Resolver::autowireService($this->entity, true, $this->container)) as $typed) {
            foreach ($defTyped as $interface) {
                if (\is_subclass_of($typed, $interface) || \is_subclass_of($interface, $typed)) {
                    continue 2;
                }
            }
            $defTyped[] = $typed;
        }

        if (\count($defTyped) > 1) {
            $defTyped = [new p\Node\UnionType(\array_map(static fn (string $t) => p\BuilderHelpers::normalizeType($t), $defTyped))];
        }

        if ($deprecation = $this->getDeprecation()) {
            $defNodes[] = $builder->funcCall('trigger_deprecation', \array_values($deprecation));
        }

        if ($this->isLazy()) {
            if (!\is_string($entity = $this->entity)) {
                $entity = $resolver->resolve($entity);
            }
            $createdDef = $builder->methodCall($builder->propertyFetch($builder->var('this'), 'resolver'), 'resolve', [$entity, $resolver->resolveArguments($this->arguments)]);
        } else {
            $createdDef = $resolver->resolve($this->entity, $this->arguments);

            if ($createdDef instanceof Injectable) {
                $createdDef = $createdDef->build($defNodes, $builder->var('service'), $builder);
            }
        }

        if ($this->hasBinding()) {
            if (!$createdDef instanceof p\Node\Expr\Assign) {
                $defNodes[] = $createdDef = new p\Node\Expr\Assign(new p\Node\Expr\Variable('service'), $createdDef);
            }

            foreach ($this->calls['a'] ?? [] as $property => $pValue) {
                $pValue = $resolver->resolve($pValue);

                if ($pValue instanceof p\Node\Stmt) {
                    throw new ContainerResolutionException(\sprintf('Constructing property "%s" for service "%s" failed, expression not supported.', $property, $this->id));
                }

                if (\str_contains($property, '|')) {
                    $properties = \array_reverse(\explode('|', $property));
                    $pF = \array_pop($properties);
                    $defNodes[] = new p\Node\Expr\Assign(\array_reduce($properties, function (p\Node\Expr\PropertyFetch $p, string $i) use ($builder) {
                        return $builder->propertyFetch($p, $i);
                    }, $builder->propertyFetch($createdDef->var, $pF)), $pValue);
                } else {
                    $defNodes[] = new p\Node\Expr\Assign($builder->propertyFetch($createdDef->var, $property), $pValue);
                }
            }

            foreach ($this->calls['b'] ?? [] as $name => $methods) {
                foreach ($methods as $value) {
                    if (!\is_array($value)) {
                        $value = null === $value ? [] : [$value];
                    }

                    if (!$this->methodExists($this->entity, $name)) {
                        $mCall = $resolver->resolveArguments($value);

                        if (\str_contains($name, '|')) {
                            $names = \explode('|', $name);
                            [$mF, $mL] = [\array_shift($names), \array_pop($names)];
                            $defNodes[] = $builder->methodCall(
                                \array_reduce($names, function (p\Node\Expr\MethodCall $m, string $i) use ($builder) {
                                    return $builder->methodCall($m, $i);
                                }, $builder->methodCall($createdDef->var, $mF)),
                                $mL,
                                $mCall
                            );
                            continue;
                        }
                    } else {
                        $mCall = $resolver->autowireArguments(Callback::toReflection([$this->entity, $name]), $value);
                    }
                    $defNodes[] = $builder->methodCall($createdDef->var, $name, $mCall);
                }
            }

            foreach ($this->calls['c'] ?? [] as [$code, $extend]) {
                $defNodes[] = $resolver->resolve($code, $extend ? [$createdDef->var] : []);
            }
        }

        if (!$createMethod) {
            $service = $builder->methodCall($builder->var('this'), $resolver::createMethod($this->id));

            if ($this->isShared()) {
                $service = new p\Node\Expr\BinaryOp\Coalesce(
                    new p\Node\Expr\ArrayDimFetch(
                        $builder->propertyFetch($builder->var('this'), $this->isPublic() ? 'services' : 'privates'),
                        new p\Node\Scalar\String_($this->id)
                    ),
                    $service
                );
            }

            return $service;
        }

        if ($createdDef instanceof p\Node\Expr\Assign) {
            $createdVar = $createdDef->var;
        }

        if ($this->isShared()) {
            $createdVar = new p\Node\Expr\Assign(
                new p\Node\Expr\ArrayDimFetch(
                    $builder->propertyFetch($builder->var('this'), $this->isPublic() ? 'services' : 'privates'),
                    new p\Node\Scalar\String_($this->id)
                ),
                $createdVar ?? $createdDef
            );
        }

        $defNodes[] = new p\Node\Stmt\Return_($createdVar ?? $createdDef);

        return $defNode->setReturnType($defTyped[0] ?? 'mixed')->addStmts($defNodes)->getNode();
    }

    protected function methodExists(mixed $entity, string $method): bool
    {
        if (\is_string($entity)) {
            return \method_exists($entity, $method);
        }

        if (\is_array($entity)) {
            if ($entity[0] instanceof Reference) {
                $class = $this->container->definition((string) $entity[0])?->getEntity();

                return \is_string($class) ? \method_exists($class, $method) : $this->methodExists($class, $method);
            }

            if ($entity[0] instanceof Statement) {
                $class = $entity[0]->getValue();

                return \is_string($class) ? \method_exists($class, $method) : $this->methodExists($class, $method);
            }

            if ($entity[0] instanceof self) {
                return \is_string($entity[0]->entity) ? \method_exists($entity[0]->entity, $method) : $this->methodExists($entity[0]->entity, $method);
            }
        }

        return false;
    }
}
