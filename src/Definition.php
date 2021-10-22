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

use PhpParser\Builder\Method;
use PhpParser\BuilderFactory;
use PhpParser\Node\Expr\{Assign, Variable};
use PhpParser\Node\{Expr, Stmt\Return_};
use Rade\DI\Exceptions\ServiceCreationException;
use Rade\DI\Definitions\{DefinitionAwareInterface, DefinitionInterface, DepreciableDefinitionInterface, ShareableDefinitionInterface, TypedDefinitionInterface};
use Rade\DI\Resolvers\Resolver;
use Rade\DI\Definitions\Traits as Defined;

/**
 * Represents definition of standard service.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class Definition implements DefinitionInterface, TypedDefinitionInterface, ShareableDefinitionInterface, DefinitionAwareInterface, DepreciableDefinitionInterface
{
    use Defined\DeprecationTrait;

    use Defined\ParameterTrait;

    use Defined\BindingTrait;

    use Defined\VisibilityTrait;

    use Defined\ConfigureTrait;

    use Defined\AutowireTrait;

    use Defined\DefinitionAwareTrait;

    /** Use in second parameter of bind method. */
    public const EXTRA_BIND = '@code@';

    /** @var mixed The service entity */
    private $entity;

    /**
     * Definition constructor.
     *
     * @param mixed                   $entity
     * @param array<int|string,mixed> $arguments
     */
    public function __construct($entity, array $arguments = [])
    {
        $this->replace($entity, true);
        $this->arguments = $arguments;
    }

    /**
     * {@inheritdoc}
     */
    public function getEntity()
    {
        return $this->entity;
    }

    /**
     * Replace existing entity to a new entity.
     *
     * @param mixed $entity
     *
     * @return $this
     */
    final public function replace($entity, bool $if): self
    {
        if ($entity instanceof DefinitionInterface) {
            throw new ServiceCreationException(\sprintf('A definition entity must not be an instance of "%s".', DefinitionInterface::class));
        }

        if ($if /* Replace if matches a rule */) {
            $this->entity = $entity;
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function build(string $id, Resolver $resolver)
    {
        if (null === $builder = $resolver->getBuilder()) {
            return $this->resolve($id, $resolver);
        }

        $defNode = $builder->method($resolver->createMethod($id))->makeProtected();
        $createdDef = $this->createServiceEntity($id, $resolver, $defNode, $builder);

        if (null !== $this->instanceOf) {
            $createdDef = $this->createAssignedService($defNode, $createdDef);
            $defNode->addStmt($this->triggerInstanceOf($id, $createdDef->var, $builder));
        }

        if ($this->shared) {
            $createdDef = $this->triggerSharedBuild($id, $createdDef instanceof Assign ? $createdDef->var : $createdDef, $builder);
        }

        return $defNode->addStmt(new Return_($createdDef));
    }

    protected function resolve(string $id, Resolver $resolver)
    {
        $resolved = $this->entity;

        if (\is_callable($resolved) || !\is_object($resolved)) {
            $resolved = $resolver->resolve($this->entity, $this->arguments);
        }

        if (null !== $this->instanceOf) {
            (\is_object($resolved) || \is_string($resolved)) && null === $this->triggerInstanceOf($id, $resolved);
        }

        if ($this->isDeprecated()) {
            $this->triggerDeprecation($id);
        }

        if (\is_object($resolved) && $this->hasBinding()) {
            foreach ($this->parameters as $property => $propertyValue) {
                $resolved->{$property} = $resolver->resolve($propertyValue);
            }

            foreach ($this->calls as [$method, $methodValue]) {
                $resolver->resolve([$resolved, $method], (array) $methodValue);
            }
        }

        foreach ($this->extras as $code) {
            $resolver->resolve($code);
        }

        return $resolved;
    }

    protected function createServiceEntity(string $id, Resolver $resolver, Method $defNode, BuilderFactory $builder): Expr
    {
        if ($this->isTyped()) {
            $this->triggerReturnType($defNode);
        }

        if ($this->isDeprecated()) {
            $defNode->addStmt($this->triggerDeprecation($id, $builder));
        }

        if ($this->lazy) {
            $createdDef = $builder->methodCall($builder->propertyFetch($builder->var('this'), 'resolver'), 'resolver', [$this->entity, $this->arguments]);
        } else {
            $createdDef = $resolver->resolve($this->entity, $this->arguments);
        }

        if ($createdDef instanceof Injectable) {
            $createdDef = $createdDef->build($defNode, $builder->var('service'), $builder);
        }

        if ($this->hasBinding()) {
            $this->resolveBinding($defNode, $createdDef = $this->createAssignedService($defNode, $createdDef), $resolver, $builder);
        }

        return $createdDef;
    }

    protected function createAssignedService(Method $defNode, Expr $createdDef): Assign
    {
        if (!$createdDef instanceof Assign) {
            $defNode->addStmt($createdDef = new Assign(new Variable('service'), $createdDef));
        }

        return $createdDef;
    }
}
