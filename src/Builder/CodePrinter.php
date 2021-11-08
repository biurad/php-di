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

namespace Rade\DI\Builder;

use PhpParser\PrettyPrinter\Standard;

/**
 * A custom Php-Parser printer.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class CodePrinter extends Standard
{
    public const COMMENT = <<<'COMMENT'
/**
 * @internal This class has been auto-generated by the Rade DI.
 */
COMMENT;

    /**
     * Pretty print nodes.
     *
     * @param \PhpParser\Node[]   $stmts
     * @param array<string,mixed> $options
     */
    public static function print(array $stmts, array $options = []): string
    {
        $printer = new self(['shortArraySyntax' => $options['shortArraySyntax'] ??= true]);

        return $printer->prettyPrintFile($stmts);
    }

    protected function pStmt_Return(\PhpParser\Node\Stmt\Return_ $node): string
    {
        return $this->nl . parent::pStmt_Return($node);
    }

    protected function pStmt_Declare(\PhpParser\Node\Stmt\Declare_ $node): string
    {
        return parent::pStmt_Declare($node) . $this->nl;
    }

    protected function pStmt_Property(\PhpParser\Node\Stmt\Property $node): string
    {
        if ('tags' === $node->props[0]->name->name) {
            foreach ($node->props[0]->default->items as $item) {
                $item->setAttribute('multiline', true);
            }
        }

        return parent::pStmt_Property($node) . \PHP_EOL;
    }

    protected function pStmt_ClassMethod(\PhpParser\Node\Stmt\ClassMethod $node): string
    {
        $classMethod = parent::pStmt_ClassMethod($node);

        if (null !== $node->returnType) {
            $classMethod = \str_replace(') :', '):', $classMethod);
        }

        $this->indent();
        $classMethod = \str_replace(["{\n" . ($nl = \strrev($this->nl)), $nl], ["{\n", \PHP_EOL], $classMethod);
        $this->outdent();

        return $classMethod . \PHP_EOL; // prefer spaces instead of tab
    }

    protected function pStmt_Class(\PhpParser\Node\Stmt\Class_ $node): string
    {
        return \str_replace(\PHP_EOL . \strrev($this->nl) . '}', "\n}", parent::pStmt_Class($node));
    }

    protected function pScalar_String(\PhpParser\Node\Scalar\String_ $node): string
    {
        if (\Nette\Utils\Validators::isType($node->value)) {
            return $this->pExpr_ConstFetch(new \PhpParser\Node\Expr\ConstFetch(new \PhpParser\Node\Name($node->value . '::class')));
        }

        return parent::pScalar_String($node);
    }

    protected function pMaybeMultiline(array $nodes, bool $trailingComma = false): string
    {
        if (\count($nodes) > 5 || (isset($nodes[0]) && $nodes[0]->getAttribute('multiline'))) {
            return $this->pCommaSeparatedMultiline($nodes, $trailingComma) . $this->nl;
        }

        return parent::pMaybeMultiline($nodes, $trailingComma);
    }
}
