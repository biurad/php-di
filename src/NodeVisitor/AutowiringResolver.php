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

use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\{Class_, ClassMethod, Expression};
use PhpParser\NodeVisitorAbstract;

/**
 * Autowiring Enhancement for class methods stmts.
 *
 * Resolves same object id used multiple times in class methods stmts
 * and create a variable for the object.
 *
 * Example of how it works:
 *
 * ```php
 * $hello = $container->call(HelloWorld::class);
 * $container->set('foo', new Definition('MyWorld', [$hello]))->bind('addGreeting', [$hello]);
 *
 * // A variable is created for $hello object, then mapped to receiving nodes.
 * ```
 *
 * @experimental if any bug found, create an issue at https://github.com/divineniiquaye/rade-di/issues/new
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class AutowiringResolver extends NodeVisitorAbstract
{
    /** @var array<string,array<int,Expr\Assign> */
    private array $replacement = [];

    /** @var array<string,array<int,array<int,mixed>>> */
    private array $sameArgs = [];

    /**
     * {@inheritdoc}
     */
    public function enterNode(\PhpParser\Node $node)
    {
        if ($node instanceof ClassMethod) {
            $nodeId = $node->name->toString();

            if (!isset($this->sameArgs[$nodeId])) {
                $this->resolveStmt($node->stmts, $nodeId);
            }
        }

        return parent::enterNode($node);
    }

    /**
     * {@inheritdoc}
     */
    public function afterTraverse(array $nodes)
    {
        foreach ($nodes as $node) {
            if ($node instanceof Class_) {
                foreach ($node->stmts as $methodNode) {
                    if ($methodNode instanceof ClassMethod) {
                        $nodeId = $methodNode->name->toString();

                        if (isset($this->replacement[$nodeId])) {
                            $this->resolveStmt($methodNode->stmts, $nodeId, true);

                            $replacements = \array_map(fn (Expr\Assign $node) => new Expression($node), $this->replacement[$nodeId]);
                            $methodNode->stmts = \array_merge(\array_values($replacements), $methodNode->stmts);
                        }
                    }
                }
            }
        }

        return parent::afterTraverse($nodes);
    }

    /**
     * @param array<int,Expression> $expressions
     */
    private function resolveStmt(array $expressions, string $parentId, bool $leaveNodes = false): void
    {
        foreach ($expressions as $expression) {
            $expr = $expression->expr;

            if ($expr instanceof Expr\Assign) {
                $expr = $expr->expr;
            }

            if (isset($expr->args)) {
                $this->resolveNode($expr->args, $parentId, $leaveNodes);

                continue;
            }

            $exprNodes = $expr->items ?? [&$expr];
            $this->resolveNode($exprNodes, $parentId, $leaveNodes);
        }
    }

    /**
     * @param array<int,\PhpParser\Node\Expr> $nodes
     */
    private function resolveNode(array &$nodes, string $parentId, bool $leaveNodes): void
    {
        foreach ($nodes as &$node) {
            if (\property_exists($node, 'value') && \is_object($node->value)) {
                $nodeValue = &$node->value;
            } else {
                $nodeValue = $node;
            }

            if ($nodeValue instanceof Expr\Variable || $nodeValue instanceof Expr\Closure) {
                continue; // @Todo: work in progress ...
            }

            if (isset($nodeValue->expr)) {
                $nodeValue = &$nodeValue->expr;
            }

            if (\property_exists($nodeValue, 'items')) {
                $nodeValues = &$nodeValue->items;
            } elseif (\property_exists($nodeValue, 'args')) {
                $nodeValues = &$nodeValue->args;
            } elseif (\property_exists($nodeValue, 'stmts')) {
                $nodeValues = &$nodeValue->stmts;
            }

            if (!empty($nodeValues ?? [])) {
                $this->resolveNode($nodeValues, $parentId, $leaveNodes);
            }

            $this->doReplacement($nodeValue, $parentId, $leaveNodes);
        }
    }

    private function doReplacement(\PhpParser\Node &$nodeValue, string $parentId, bool $leaveNodes): void
    {
        $nodeId = \spl_object_id($nodeValue);

        if ($leaveNodes) {
            if (isset($this->replacement[$parentId][$nodeId])) {
                $nodeValue = $this->replacement[$parentId][$nodeId]->var;
            }

            return;
        }

        if (isset($this->sameArgs[$parentId][$nodeId])) {
            [$varName, $varValue] = $this->sameArgs[$parentId][$nodeId];
            $this->replacement[$parentId][$nodeId] = new Expr\Assign(new Expr\Variable($varName), $varValue);

            return;
        }

        $this->sameArgs[$parentId][$nodeId] = ['v_' . \substr(\hash('sha256', \serialize($nodeValue)), 0, 5), $nodeValue];
    }
}
