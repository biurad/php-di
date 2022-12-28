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

namespace Rade\DI;

use PhpParser\Node\{Expr, Name, Scalar\String_};
use PhpParser\Node\Stmt\{ClassMethod, Declare_, DeclareDeclare, Nop};
use Rade\DI\Exceptions\{ContainerResolutionException, NotFoundServiceException};
use Symfony\Component\Config\Resource\ResourceInterface;

/**
 * A compilable container to build services easily.
 *
 * Generates a compiled container. This means that there is no runtime performance impact.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class ContainerBuilder extends Container
{
    /** @var array<string,ResourceInterface> */
    private array $resources = [];

    private ?\PhpParser\NodeTraverser $nodeTraverser = null;

    /**
     * Compile the container for optimum performances.
     *
     * @param string $containerParentClass Name of the compiled container parent class. Customize only if necessary.
     */
    public function __construct(private string $containerParentClass = Container::class)
    {
        if (!\class_exists(\PhpParser\BuilderFactory::class)) {
            throw new \RuntimeException('ContainerBuilder uses "nikic/php-parser" v4, do composer require the nikic/php-parser package.');
        }

        $this->resolver = new Resolver($this, new \PhpParser\BuilderFactory());
        $this->services[self::SERVICE_CONTAINER] = new Expr\Variable('this');
        $this->type(self::SERVICE_CONTAINER, ...\array_keys((\class_implements($c = $containerParentClass) ?: []) + (\class_parents($c) ?: []) + [$c => $c]));
    }

    /**
     * {@inheritdoc}
     */
    public function autowired(string $id, bool $single = false): mixed
    {
        $autowired = [];

        foreach ($this->types[$id] ?? [] as $typed) {
            $autowired[] = $this->services[$typed] ?? $this->get($typed);

            if ($single && \array_key_exists(1, $autowired)) {
                $c = \count($t = $this->types[$id]) <= 3 ? \implode(', ', $t) : \current($t) . ', ...' . \end($t);

                throw new ContainerResolutionException(\sprintf('Multiple typed services %s found: %s.', $id, $c));
            }
        }

        if (empty($autowired)) {
            throw new NotFoundServiceException(\sprintf('Typed service "%s" not found. Check class name because it cannot be found.', $id));
        }

        return $single ? $autowired[0] : $autowired;
    }

    /**
     * {@inheritdoc}
     */
    public function reset(): void
    {
        parent::reset();
        $this->nodeTraverser = null;
        $this->resources = [];
    }

    /**
     * Returns an array of resources loaded to build this configuration.
     *
     * @return ResourceInterface[] An array of resources
     */
    public function getResources(): array
    {
        return \array_values($this->resources ?? []);
    }

    /**
     * Add a resource to allow re-build of container.
     *
     * @return $this
     */
    public function addResource(ResourceInterface $resource)
    {
        $this->resources[(string) $resource] = $resource;

        return $this;
    }

    /**
     * Add a node visitor to traverse the generated ast.
     *
     * @return $this
     */
    public function addNodeVisitor(\PhpParser\NodeVisitor $nodeVisitor)
    {
        if (null === $this->nodeTraverser) {
            $this->nodeTraverser = new \PhpParser\NodeTraverser();
        }

        $this->nodeTraverser->addVisitor($nodeVisitor);

        return $this;
    }

    /**
     * Compiles the container.
     * This method main job is to manipulate and optimize the container.
     *
     * supported $options config (defaults):
     * - strictType => true,
     * - printToString => true,
     * - shortArraySyntax => true,
     * - maxLineLength => 200,
     * - containerClass => CompiledContainer,
     *
     * @throws \ReflectionException
     *
     * @return \PhpParser\Node[]|string
     */
    public function compile(array $options = [])
    {
        $options += ['strictType' => true, 'printToString' => true, 'containerClass' => 'CompiledContainer'];
        $astNodes = $options['strictType'] ? [new Declare_([new DeclareDeclare('strict_types', $this->resolver->getBuilder()->val(1))])] : [];
        $processedData = $this->doAnalyse($this->definitions);
        $containerNode = $this->resolver->getBuilder()->class($options['containerClass'])->extend($this->containerParentClass)->setDocComment(Builder\CodePrinter::COMMENT);

        if (!empty($parameters = $this->parameters)) {
            \ksort($parameters);
            $parameters = $this->resolver->resolveArguments($parameters);
        }

        if (!empty($processedData[3])) {
            $containerNode->addStmt($this->resolver->getBuilder()->property('types')->makeProtected()->setType('array')->setDefault($processedData[3]));
        }

        [$resolver, $c, $s] = [$this->resolver, $this->containerParentClass, self::SERVICE_CONTAINER];
        $containerNode = \Closure::bind(function (\PhpParser\Builder\Class_ $node) use ($c, $s, $resolver, $parameters, $processedData) {
            $endMethod = \array_pop($node->methods);
            $constructorNode = ($b = $resolver->getBuilder())->method('__construct');

            if ($endMethod instanceof ClassMethod && '__construct' === $endMethod->name->name) {
                $constructorNode->addStmts([...$endMethod->stmts, new Nop()]);
            } elseif (\method_exists($c, '__construct')) {
                $constructorNode->addStmt($b->staticCall(new Name('parent'), '__construct'));
            }

            if (\count($processedData[1]) > 1) {
                unset($processedData[1][$s]);
                $constructorNode->addStmt(new Expr\Assign($b->propertyFetch($b->var('this'), 'methodsMap'), $b->val($processedData[1])));
            }

            if (!empty($parameters)) {
                $constructorNode->addStmt(new Expr\Assign($b->propertyFetch($b->var('this'), 'parameters'), $b->val($parameters)));
            }

            if (!empty($processedData[0])) {
                $constructorNode->addStmt(new Expr\Assign($b->propertyFetch($b->var('this'), 'aliases'), $b->val($processedData[0])));
            }

            if (!empty($processedData[4])) {
                $constructorNode->addStmt(new Expr\Assign($b->propertyFetch($b->var('this'), 'tags'), $b->val($processedData[4])));
            }

            return $node->addStmt($constructorNode->makePublic());
        }, $containerNode, $containerNode::class)($containerNode);

        if (!empty($processedData[2])) {
            $containerNode->addStmts($processedData[2]);
        }

        $astNodes[] = $containerNode->getNode(); // Build the container class

        if (null !== $this->nodeTraverser) {
            $astNodes = $this->nodeTraverser->traverse($astNodes);
        }

        if ($options['printToString']) {
            unset($options['strictType'], $options['printToString'], $options['containerClass']);

            return Builder\CodePrinter::print($astNodes, $options);
        }

        return $astNodes;
    }

    /**
     * Analyse all definitions, build definitions and return results.
     *
     * @param array<string,Definition> $definitions
     */
    protected function doAnalyse(array $definitions, bool $onlyDefinitions = false): array
    {
        $methodsMap = $serviceMethods = $wiredTypes = [];

        if (!isset($methodsMap[self::SERVICE_CONTAINER])) {
            $methodsMap[self::SERVICE_CONTAINER] = true;
        }

        foreach ($definitions as $id => $definition) {
            if ($this->tagged('container.remove_services', $id)) {
                continue;
            }
            $methodsMap[$id] = $this->resolver::createMethod($id);

            if (!$definition->isPublic()) {
                unset($methodsMap[$id]);
            }

            if ($definition->isAbstract()) {
                unset($methodsMap[$id]);
                continue;
            }
            $serviceMethods[] = $definition->resolve($this->resolver, true);
        }

        if ($onlyDefinitions) {
            return [$methodsMap, $serviceMethods];
        }

        if ($newDefinitions = \array_diff_key($this->definitions, $definitions)) {
            $processedData = $this->doAnalyse($newDefinitions, true);
            $methodsMap = \array_merge($methodsMap, $processedData[0]);
            $serviceMethods = [...$serviceMethods, ...$processedData[1]];
        }
        $aliases = \array_filter($this->aliases, static fn (string $aliased): bool => isset($methodsMap[$aliased]));
        $tags = \array_filter($this->tags, static fn (array $tagged): bool => isset($methodsMap[\key($tagged)]));

        // Prevent autowired private services from be exported.
        foreach ($this->types as $type => $ids) {
            $ids = \array_filter($ids, static fn (string $id): bool => isset($methodsMap[$id]));

            if ([] !== $ids) {
                $ids = \array_values($ids); // If $ids are filtered, keys should not be preserved.
                $wiredTypes[] = new Expr\ArrayItem($this->resolver->getBuilder()->val($ids), new String_($type));
            }
        }
        \natsort($aliases);
        \ksort($methodsMap);
        \ksort($tags, \SORT_NATURAL);
        \usort($serviceMethods, fn (ClassMethod $a, ClassMethod $b): int => \strnatcmp($a->name->toString(), $b->name->toString()));
        \usort($wiredTypes, fn (Expr\ArrayItem $a, Expr\ArrayItem $b): int => \strnatcmp($a->key->value, $b->key->value));

        return [$aliases, $methodsMap, $serviceMethods, $wiredTypes, $tags];
    }
}
