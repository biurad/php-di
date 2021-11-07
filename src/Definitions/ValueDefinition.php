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

use PhpParser\Node\Stmt\Return_;
use Rade\DI\Exceptions\ServiceCreationException;
use Rade\DI\Resolvers\Resolver;

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
        if (null === $builder = $resolver->getBuilder()) {
            if ($this->isDeprecated()) {
                $this->triggerDeprecation($id);
            }

            return \is_array($value = $this->value) ? $resolver->resolveArguments($value) : $value;
        }

        $defNode = $builder->method($resolver->createMethod($id))->makeProtected()->setReturnType(\get_debug_type($this->value));

        if ($this->isDeprecated()) {
            $defNode->addStmt($this->triggerDeprecation($id, $builder));
        }

        if ($this->lazy && is_array($this->value)) {
            $createdValue = $builder->methodCall($builder->propertyFetch($builder->var('this'), 'resolver'), 'resolveArguments', [$this->value]);
        }

        if ($this->shared) {
            $createdValue = $this->triggerSharedBuild($id, $createdValue ?? $builder->val($this->value), $builder);
        }

        return $defNode->addStmt(new Return_($createdValue ?? $builder->val($this->value)));
    }
}
