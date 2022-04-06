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

use PhpParser\BuilderHelpers;
use PhpParser\Node\Scalar\String_;

/**
 * PhpLiteral Node Visitor.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
final class PhpLiteralVisitor extends \PhpParser\NodeVisitorAbstract
{
    private int $offset = -1;
    private array $args;

    /**
     * @param array<int,mixed> $args
     */
    public function __construct(array $args)
    {
        $this->args = $args;
    }

    /**
     * {@inheritdoc}
     */
    public function enterNode(\PhpParser\Node $node)
    {
        if ($node instanceof String_ && '??' === $node->value) {
            $value = $this->args[++$this->offset] ?? null;

            if (null === $value) {
                throw new \ParseError('Unable to parse syntax "??" as no value supplied for its string node expression.');
            }

            return BuilderHelpers::normalizeValue($value);
        }

        return parent::enterNode($node);
    }
}
