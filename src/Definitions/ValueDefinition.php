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

namespace Rade\DI\Definitions;

use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Return_;
use Rade\DI\Exceptions\ContainerResolutionException;
use Rade\DI\Exceptions\ServiceCreationException;
use Rade\DI\Resolver;

/**
 * Represents a definition service that shouldn't be resolved.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class ValueDefinition implements DefinitionInterface, ShareableDefinitionInterface, DepreciableDefinitionInterface
{
    use Traits\DeprecationTrait;
    use Traits\VisibilityTrait;

    /** @var mixed */
    private $value;

    /**
     * Definition constructor.
     *
     * @param mixed $value
     */
    public function __construct($value, bool $shared = true)
    {
        $this->replace($value);
        $this->shared = $shared;
    }

    /**
     * Replace the existing value.
     *
     * @param mixed $value
     *
     * @return $this
     */
    public function replace($value)
    {
        if ($value instanceof DefinitionInterface) {
            throw new ServiceCreationException(\sprintf('A definition entity must not be an instance of "%s".', DefinitionInterface::class));
        } elseif ($value instanceof \PhpParser\Node && !$value instanceof Expr) {
            throw new ServiceCreationException(\sprintf('A definition entity must be an instance of "%s".', Expr::class));
        }

        $this->value = $value;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getEntity()
    {
        return $this->value;
    }

    /**
     * {@inheritdoc}
     */
    public function build(string $id, Resolver $resolver)
    {
        $builder = $resolver->getBuilder();
        $value = $this->value;

        if ($this->abstract) {
            throw new ContainerResolutionException(\sprintf('Resolving an abstract definition %s is not allowed.', $id));
        }

        if (!empty($this->deprecation)) {
            $deprecation = $this->triggerDeprecation($id, $builder);
        }

        if (null === $builder) {
            return \is_array($value) ? $resolver->resolveArguments($value) : (!$this->lazy ? $value : $resolver->resolve($value));
        }

        $defNode = $builder->method($resolver->createMethod($id))->makeProtected();

        if ($value instanceof \PhpParser\Node) {
            if ($value instanceof Expr\Array_) {
                $defNode->setReturnType('array');
            } elseif ($value instanceof Expr\New_) {
                $defNode->setReturnType($value->class->toString());
            }
        } elseif (\PHP_MAJOR_VERSION >= 8) {
            $defNode->setReturnType('mixed');
        }

        if (isset($deprecation)) {
            $defNode->addStmt($deprecation);
        }

        if ($this->lazy) {
            $lazyMethod = \is_array($value) ? 'resolveArguments' : 'resolve';
            $createdValue = $builder->methodCall($builder->propertyFetch($builder->var('this'), 'resolver'), $lazyMethod, [$value]);
        }

        if ($this->shared) {
            $createdValue = $this->triggerSharedBuild($id, $createdValue ?? $builder->val($value), $builder);
        }

        return $defNode->addStmt(new Return_($createdValue ?? $builder->val($value)));
    }
}
