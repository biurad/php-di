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

use PhpParser\Node\Expr\{ArrayDimFetch, Assign};
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use Rade\DI\Exceptions\ContainerResolutionException;
use Rade\DI\Resolvers\Resolver;

/**
 * Represents a definition service that shouldn't be resolved.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
final class ValueDefinition implements DefinitionInterface
{
    use Traits\DeprecationTrait;

    /** @var mixed */
    private $value;

    /**
     * Definition constructor.
     *
     * @param mixed $value
     */
    public function __construct($value)
    {
        if ($value instanceof self) {
            throw new ContainerResolutionException('Unresolvable definition cannot contain itself.');
        }

        $this->value = $value;
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

            return $this->value;
        }

        $defNode = $builder->method($resolver->createMethod($id))->makeProtected();
        $serviceVar = new ArrayDimFetch($builder->propertyFetch($builder->var('this'), 'services'), new String_($id));

        if ($this->isDeprecated()) {
            $defNode->addStmt($this->triggerDeprecation($id, $builder));
        }

        return $defNode->addStmt(new Return_(new Assign($serviceVar, $builder->val($this->value))));
    }
}
