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
use PhpParser\Node\Stmt\{Class_, ClassMethod, Expression, Nop, Return_};
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
                $methodsStmts = &$node->stmts;
                $this->resolveStmt($methodsStmts, $nodeId);
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
                            $methodsStmts = &$methodNode->stmts;
                            $this->resolveStmt($methodsStmts, $nodeId, true);

                            $replacements = \array_map(fn (Expr\Assign $node) => new Expression($node), $this->replacement[$nodeId]);
                            $methodNode->stmts = [...\array_values($replacements), new Nop(), ...$methodsStmts];
                        }
                    }
                }
            }
        }

        return parent::afterTraverse($nodes);
    }

    /**
     * @param array<int,Expression|Return_|Expr> $expressions
     */
    private function resolveStmt(array $expressions, string $parentId, bool $leaveNodes = false): void
    {
        foreach ($expressions as &$expression) {
            if ($expression instanceof Expression || $expression instanceof Return_) {
                $expression = $expression->expr;
                $fromStmt = true; // $expression is from stmt

                if ($expression instanceof Expr\Assign) {
                    $expression = &$expression->expr;
                }
            } elseif (\property_exists($expression, 'value') && $expression->value instanceof \PhpParser\Node) {
                $expression = &$expression->value;
            }

            if (\property_exists($expression, 'expr')) {
                $expression = &$expression->expr;
            }

            if ($expression instanceof Expr\MethodCall) {
                $exprVar = &$expression->var;

                if (!$expression instanceof Expr\Variable) {
                    $this->doReplacement($exprVar, $parentId, $leaveNodes);
                }
            }

            if ($expression instanceof Expr\CallLike) {
                $this->resolveStmt($expression->args, $parentId, $leaveNodes);

                if (isset($fromStmt)) {
                    continue;
                }
            }

            if ($expression instanceof Expr\Array_) {
                $this->resolveStmt($expression->items, $parentId, $leaveNodes);
            }

            $this->doReplacement($expression, $parentId, $leaveNodes);
        }
    }

    private function doReplacement(\PhpParser\Node &$nodeValue, string $parentId, bool $leaveNodes): void
    {
        if ($nodeValue instanceof Expr\Variable || $nodeValue instanceof Expr\Closure) {
            return; // @Todo: work in progress ...
        }

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
