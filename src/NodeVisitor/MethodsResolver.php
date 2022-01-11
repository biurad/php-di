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

/**
 * Fixes container's class methods name conflict.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class MethodsResolver extends \PhpParser\NodeVisitorAbstract
{
    private array $replacement = [];

    /**
     * {@inheritdoc}
     */
    public function enterNode(\PhpParser\Node $node)
    {
        if ($node instanceof \PhpParser\Node\Stmt\PropertyProperty && 'methodsMap' === $node->name->name) {
            $sameNaming = [];

            foreach ($node->default->items as $item) {
                $lcName = \strtolower($item->key->value);

                if (\array_key_exists($lcName, $sameNaming)) {
                    $this->replacement[$item->value->value][] = $item->value->value .= $sameNaming[$lcName]++;
                } else {
                    $sameNaming[$lcName] = 0;
                }
            }
        } elseif ($node instanceof \PhpParser\Node\Stmt\ClassMethod && \array_key_exists($node->name->name, $this->replacement)) {
            $node->name->name = \array_shift($this->replacement[$node->name->name]);
        }

        return parent::enterNode($node);
    }
}
