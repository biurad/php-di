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
use Symfony\Component\Config\ConfigCache;

/**
 * This class splits definitions equally with the total amount of $maxDefinitions
 * into traits, and imported as use traits into compiled container class.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class DefinitionsSplitter extends NodeVisitorAbstract
{
    private int $maxCount;

    private string $fileName;

    private array $traits = [];

    private ?string $previousTrait = null;

    private \PhpParser\BuilderFactory $builder;

    private ?Declare_ $strictDeclare = null;

    public function __construct(int $maxDefinitions = 500, string $fileName = 'definitions_autoload.php')
    {
        $this->maxCount = $maxDefinitions;
        $this->fileName = $fileName;
        $this->builder = new \PhpParser\BuilderFactory();
    }

    public function getTraits(): array
    {
        return $this->traits;
    }

    /**
     * Use this function to build generated traits into a cache directory
     * and return the file that requires all traits.
     */
    public function buildTraits(string $cacheDirectory, bool $debug = false): string
    {
        $traitsDirectory = \rtrim($cacheDirectory, '/') . '/Definitions' . $this->traitHash($cacheDirectory);
        $autoLoadAst = [];

        if (null !== $this->strictDeclare) {
            $autoLoadAst[] = $this->strictDeclare;
        }

        foreach ($this->traits as $traitName => $traitStmts) {
            $cache = new ConfigCache($traitsDirectory . '/' . $traitName . '.php', $debug);
            $autoLoadInclude = new Expression(new Include_(new String_($cache->getPath()), Include_::TYPE_REQUIRE));

            if (\count($autoLoadAst) <= 1) {
                $autoLoadInclude->setDocComment(new \PhpParser\Comment\Doc(\str_replace('class', 'file', CodePrinter::COMMENT)));
            }

            if (!$cache->isFresh() || $debug) {
                $traitAst = [];
                $traitComment = "\n *\n" . ' * @property array<int,mixed> $services' . \PHP_EOL . ' * @property array<int,mixed> $privates';

                if (null !== $this->strictDeclare) {
                    $traitAst[] = $this->strictDeclare;
                }

                $traitAst[] = $this->builder->trait($traitName)
                    ->setDocComment(\strtr(CodePrinter::COMMENT, ['class' => 'trait', '.' => '.' . $traitComment . \PHP_EOL]))
                    ->addStmts($traitStmts)
                    ->getNode();

                $cache->write(CodePrinter::print($traitAst));
            }

            $autoLoadAst[] = $autoLoadInclude;
        }

        $autoloadCache = new ConfigCache(\rtrim($cacheDirectory, '/') . '/' . $this->fileName, $debug);

        if (!$autoloadCache->isFresh() || $debug) {
            $autoloadCache->write(CodePrinter::print($autoLoadAst) . \PHP_EOL);
        }

        return $autoloadCache->getPath();
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
                $node->stmts = \array_merge([$this->builder->useTrait(...\array_keys($this->traits))->getNode()], $nodeStmts);
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
