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

namespace Rade\DI\NodeVisitor;

use PhpParser\Node\Scalar\String_;
use Rade\DI\Resolvers\Resolver;

/**
 * PhpLiteral Node Visitor.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
final class PhpLiteralVisitor extends \PhpParser\NodeVisitorAbstract
{
    private Resolver $resolver;

    private int $offset = -1;

    private array $args;

    /**
     * @param array<int,mixed> $args
     */
    public function __construct(Resolver $resolver, array $args)
    {
        $this->args = $args;
        $this->resolver = $resolver;
    }

    /**
     * {@inheritdoc}
     */
    public function enterNode(\PhpParser\Node $node)
    {
        if ($node instanceof String_ && '??' === $node->value) {
            $value = $this->args[++$this->offset];

            if (\is_array($value)) {
                return $this->resolver->getBuilder()->val($this->resolver->resolveArguments($value));
            }

            return $this->resolver->resolve($value);
        }

        return parent::enterNode($node);
    }
}
