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

use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\{Class_, Declare_, Expression};
use PhpParser\NodeVisitorAbstract;
use Rade\DI\Builder\CodePrinter;

/**
 * This class splits definitions equally with the total amount of $maxDefinitions
 * into traits, and imported as use traits into compiled container class.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class DefinitionsSplitter extends NodeVisitorAbstract
{
    private array $traits = [];
    private ?string $previousTrait = null;
    private \PhpParser\BuilderFactory $builder;
    private ?Declare_ $strictDeclare = null;

    public function __construct(private int $maxCount = 500, private string $fileName = 'definitions_autoload.php')
    {
        $this->builder = new \PhpParser\BuilderFactory();
    }

    public function getTraits(): array
    {
        return $this->traits;
    }

    /**
     * Use this function to build generated traits into a cache directory
     * and return the file that requires all traits.
     *
     * @param array<int,string> $includePaths
     */
    public function buildTraits(string $cacheDirectory, array $includePaths = []): string
    {
        $traitsDirectory = \rtrim($cacheDirectory, '/') . '/Definitions' . $this->traitHash($cacheDirectory);
        $autoLoadAst = [];

        if (!\is_dir($traitsDirectory)) {
            \mkdir($traitsDirectory, 0777, true);
        }

        if (null !== $this->strictDeclare) {
            $autoLoadAst[] = $this->strictDeclare;
        }

        foreach ($includePaths as $bPath) {
            $autoLoadAst[] = new Expression(new Include_(new String_($bPath), Include_::TYPE_REQUIRE));
        }

        foreach ($this->traits as $traitName => $traitStmts) {
            $path = $traitsDirectory . '/' . $traitName . '.php';
            $autoLoadInclude = new Expression(new Include_(new String_($path), Include_::TYPE_REQUIRE));

            if (\count($autoLoadAst) <= 1) {
                $autoLoadInclude->setDocComment(new \PhpParser\Comment\Doc(\str_replace('class', 'file', CodePrinter::COMMENT)));
            }

            $traitAst = [];
            $traitComment = "\n *\n" . ' * @property array<int,mixed> $services' . \PHP_EOL . ' * @property array<int,mixed> $privates';

            if (null !== $this->strictDeclare) {
                $traitAst[] = $this->strictDeclare;
            }

            $traitAst[] = $this->builder->trait($traitName)
                ->setDocComment(\strtr(CodePrinter::COMMENT, ['class' => 'trait', '.' => '.' . $traitComment . \PHP_EOL]))
                ->addStmts($traitStmts)
                ->getNode();

            \file_put_contents($path, CodePrinter::print($traitAst));
            $autoLoadAst[] = $autoLoadInclude;
        }
        \file_put_contents($build = $cacheDirectory .'/' . $this->fileName, CodePrinter::print($autoLoadAst));

        return $build;
    }

    /**
     * {@inheritdoc}
     */
    public function enterNode(\PhpParser\Node $node)
    {
        if ($node instanceof Declare_) {
            $this->strictDeclare = $node;
        } elseif ($node instanceof Class_) {
            $indexHash = ($stmtsCount = \count($nodeStmts = $node->stmts)) . 'a';

            while ($stmtsCount >= $this->maxCount) {
                $traitName = 'Definition_' . $this->traitHash($indexHash) . 'Trait';

                if (null === $this->previousTrait) {
                    $stmtsCount = \count($this->traits[$traitName] = \array_splice($nodeStmts, $this->maxCount));
                } else {
                    $traitStmts = &$this->traits[$this->previousTrait];
                    $stmtsCount = \count($this->traits[$traitName] = \array_splice($traitStmts, $this->maxCount));
                }

                $this->previousTrait = $traitName;
                ++$indexHash;
            }

            if (empty($this->traits[$this->previousTrait])) {
                unset($this->traits[$this->previousTrait]);
            }

            if (!empty($this->traits)) {
                $node->stmts = [$this->builder->useTrait(...\array_keys($this->traits))->getNode(), ...$nodeStmts];
            }

            $this->previousTrait = null;
        }

        return parent::enterNode($node);
    }

    private function traitHash(string $indexHash): string
    {
        return \substr(\ucwords(\base64_encode(\hash('sha256', $indexHash))), 0, 7);
    }
}
