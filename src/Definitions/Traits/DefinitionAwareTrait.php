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

namespace Rade\DI\Definitions\Traits;

use Rade\DI\AbstractContainer;
use Rade\DI\Exceptions\ContainerResolutionException;
use Nette\Utils\Callback;
use PhpParser as p;
use Rade\DI\Definitions\Reference;
use Rade\DI\Definitions\Statement;
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
    private ?AbstractContainer $container = null;

    /** @var array<string,array<int|string,mixed>> */
    private array $calls = [];

    /** @var array<string,mixed> */
    private array $options = [];
    private array $deprecation = [];

    /**
     * @return mixed|\PhpParser\Builder\Method
     */
    public function resolve(Resolver $resolver): mixed
    {
        if (isset($this->options['abstract'])) {
            throw new ContainerResolutionException(\sprintf('Resolving an abstract definition %s is not allowed.', $this->id));
        }

        if (null === $builder = $resolver->getBuilder()) {
            if (!empty($this->deprecation)) {
                $deprecation = $this->getDeprecation();
                \trigger_deprecation($deprecation['package'], $deprecation['version'], $deprecation['message']);
            }

            if (\is_callable($entity = $this->entity)) {
                $entity = $resolver->resolveCallable($entity, $this->arguments);
            } elseif (!\is_object($entity)) {
                $entity = $resolver->resolve($entity, $this->arguments);
            }

            if (!empty($this->calls)) {
                foreach ($this->calls['a'] ?? [] as $property => $propertyValue) {
                    $entity->{$property} = $resolver->resolve($propertyValue);
                }

                foreach ($this->calls['b'] ?? [] as $name => $methods) {
                    foreach ($methods as $value) {
                        $resolver->resolve([$entity, $name], \is_array($value) ? $value : (null === $value ? [] : [$value]));
                    }
                }

                foreach ($this->calls['c'] ?? [] as [$code, $extend]) {
                    $resolver->resolve($code, $extend ? [$entity] : []);
                }
            }

            return $entity;
        }

        return $this->build($resolver, $builder);
    }

    /**
     * Binds container and id into this definition.
     *
     * @return $this
     */
    public function setContainer(AbstractContainer $container, string $id)
    {
        $this->container = $container;
        $this->id = $id;

        if (!empty($this->options)) {
            if (isset($this->options['aliases'])) {
                foreach ($this->options['aliases'] as $alias => $v) {
                    $this->container->alias($alias, $this->id);
                }
                unset($this->options['aliases']);
            }

            if (isset($this->options['excludes'])) {
                $this->container->excludeType(...\array_keys($this->options['excludes']));
                unset($this->options['excludes']);
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

    protected function build(Resolver $resolver, p\BuilderFactory $builder): p\Node\Expr|p\Builder\Method
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
            $createdDef = $builder->methodCall($builder->propertyFetch($builder->var('this'), 'resolver'), 'resolver', [$entity, $resolver->resolveArguments($this->arguments)]);
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
                $defNodes[] = new p\Node\Expr\Assign($builder->propertyFetch($createdDef->var, $property), $pValue);

                if ($pValue instanceof p\Node\Stmt) {
                    throw new ContainerResolutionException(\sprintf('Constructing property "%s" for service "%s" failed, expression not supported.', $property, $this->id));
                }
            }

            foreach ($this->calls['b'] ?? [] as $name => $methods) {
                foreach ($methods as $value) {
                    if (!\is_array($value)) {
                        $value = null === $value ? [] : [$value];
                    }

                    if ($this->methodExists($this->entity, $name)) {
                        $mCall = $resolver->autowireArguments(Callback::toReflection([$this->entity, $name]), $value);
                    } else {
                        $mCall = $resolver->resolveArguments($value);
                    }
                    try {
                        $defNodes[] = $builder->methodCall($createdDef->var, $name, $mCall);
                    } catch (\LogicException) {
                        //dd($mCall, $value, $name, $createdDef->var, $this->calls['b'] ?? []);
                    }
                }
            }

            foreach ($this->calls['c'] ?? [] as [$code, $extend]) {
                $defNodes[] = $resolver->resolve($code, $extend ? [$createdDef->var] : []);
            }
        }

        if ($createdDef instanceof p\Node\Expr\Assign) {
            $createdVar = $createdDef->var;
        }

        if ($this->isShared()) {
            $createdDef = new p\Node\Expr\Assign(
                new p\Node\Expr\ArrayDimFetch(
                    $builder->propertyFetch($builder->var('this'), $this->isPublic() ? 'services' : 'privates'),
                    new p\Node\Scalar\String_($this->id)
                ),
                $createdVar ?? $createdDef
            );
        }

        $defNodes[] = new p\Node\Stmt\Return_($createdVar ?? $createdDef);

        return $defNode->setReturnType($defTyped[0] ?? 'mixed')->addStmts($defNodes);
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
