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
     * @param \PhpParser\Node[] $stmts
     */
    public static function print(array $stmts, array $options = []): string
    {
        $printer = new self(['shortArraySyntax' => $options['shortArraySyntax'] ??= true]);

        // Resolve whitespace ...
        return \str_replace(["{\n        \n", "\n\n}"], ["{\n", "\n}\n"], $printer->prettyPrintFile($stmts));
    }

    protected function pStmt_Return(\PhpParser\Node\Stmt\Return_ $node): string
    {
        return $this->nl . parent::pStmt_Return($node);
    }

    protected function pStmt_Declare(\PhpParser\Node\Stmt\Declare_ $node)
    {
        return parent::pStmt_Declare($node) . $this->nl;
    }

    protected function pStmt_Property(\PhpParser\Node\Stmt\Property $node): string
    {
        return parent::pStmt_Property($node) . "\n";
    }

    protected function pStmt_ClassMethod(\PhpParser\Node\Stmt\ClassMethod $node): string
    {
        $classMethod = parent::pStmt_ClassMethod($node);

        if (null !== $node->returnType) {
            $classMethod = \str_replace(') :', '):', $classMethod);
        }

        return $classMethod . "\n"; // prefer spaces instead of tab
    }
}
