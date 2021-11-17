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

use PhpParser\Node\Expr\{Assign, Variable};
use PhpParser\Node\Stmt\Return_;
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
    use Defined\AutowireTrait;
    use Defined\DefinitionAwareTrait;

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
    public function replace($entity, bool $if): self
    {
        if ($entity instanceof DefinitionInterface) {
            throw new ServiceCreationException(\sprintf('A definition entity must not be an instance of "%s".', DefinitionInterface::class));
        }

        if ($if /* Replace if matches a rule */) {
            $this->entity = $entity;

            if ($this->autowired) {
                $this->typed(Resolver::autowireService($entity, false, isset($this->innerId) ? $this->container : null));
            }
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

        $this->triggerReturnType($defNode = $builder->method($resolver->createMethod($id))->makeProtected());

        if ($this->isDeprecated()) {
            $defNode->addStmt($this->triggerDeprecation($id, $builder));
        }

        if ($this->lazy) {
            $createdDef = $builder->methodCall($builder->propertyFetch($builder->var('this'), 'resolver'), 'resolver', [$this->entity, $resolver->resolveArguments($this->arguments)]);
        } else {
            $createdDef = $resolver->resolve($this->entity, $this->arguments);

            if ($createdDef instanceof Injector\Injectable) {
                $createdDef = $createdDef->build($defNode, $builder->var('service'), $builder);
            }
        }

        if ($this->hasBinding()) {
            if (!$createdDef instanceof Assign) {
                $defNode->addStmt($createdDef = new Assign(new Variable('service'), $createdDef));
            }

            $this->resolveBinding($defNode, $createdDef, $resolver, $builder);
        }

        if ($this->shared) {
            $createdDef = $this->triggerSharedBuild($id, $createdDef instanceof Assign ? $createdDef->var : $createdDef, $builder);
        }

        return $defNode->addStmt(new Return_($createdDef));
    }

    /**
     * @return mixed
     */
    protected function resolve(string $id, Resolver $resolver)
    {
        if ($this->isDeprecated()) {
            $this->triggerDeprecation($id);
        }

        if (\is_object($resolved = $this->entity)) {
            $resolved = !\is_callable($resolved) ? $resolved : $resolver->resolveCallable($resolved);
        } else {
            $resolved = $resolver->resolve($this->entity, $this->arguments);
        }

        if ($this->hasBinding()) {
            if (\is_object($resolved)) {
                foreach ($this->parameters as $property => $propertyValue) {
                    $resolved->{$property} = $resolver->resolve($propertyValue);
                }

                foreach ($this->calls as [$method, $methodValue]) {
                    $resolver->resolve([$resolved, $method], (array) $methodValue);
                }
            }

            foreach ($this->extras as [$extend, $code]) {
                $resolver->resolve($code, $extend ? [$resolved] : []);
            }
        }

        return $resolved;
    }
}
