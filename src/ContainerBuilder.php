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

use PhpParser\Node\{Expr, MatchArm, Name, Param, Scalar, Scalar\String_};
use PhpParser\Node\Stmt\{Case_, ClassMethod, Declare_, DeclareDeclare, Expression, Nop, Return_};
use Rade\DI\Definitions\{DefinitionInterface, ShareableDefinitionInterface};
use Rade\DI\Exceptions\ServiceCreationException;
use Symfony\Component\Config\Resource\ResourceInterface;

/**
 * A compilable container to build services easily.
 *
 * Generates a compiled container. This means that there is no runtime performance impact.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class ContainerBuilder extends AbstractContainer
{
    private const BUILD_SERVICE_DEFINITION = 3;

    /** @var array<string,ResourceInterface>|null */
    private ?array $resources;

    /** Name of the compiled container parent class. */
    private string $containerParentClass;

    private ?\PhpParser\NodeTraverser $nodeTraverser = null;

    /**
     * Compile the container for optimum performances.
     *
     * @param string $containerParentClass Name of the compiled container parent class. Customize only if necessary.
     */
    public function __construct(string $containerParentClass = Container::class)
    {
        if (!\class_exists(\PhpParser\BuilderFactory::class)) {
            throw new \RuntimeException('ContainerBuilder uses "nikic/php-parser" v4, do composer require the nikic/php-parser package.');
        }

        $this->containerParentClass = $c = $containerParentClass;
        $this->resources = \interface_exists(ResourceInterface::class) ? [] : null;
        $this->resolver = new Resolver($this, new \PhpParser\BuilderFactory());
        $this->services[self::SERVICE_CONTAINER] = new Expr\Variable('this');
        $this->type(self::SERVICE_CONTAINER, \array_keys((\class_implements($c) ?: []) + (\class_parents($c) ?: []) + [$c => $c]));
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $id, object $definition = null): object
    {
        if ($definition instanceof \PhpParser\Node) {
            $definition = new Definitions\ValueDefinition($definition);
        }

        return parent::set($id, $definition);
    }

    /**
     * {@inheritdoc}
     */
    public function reset(): void
    {
        parent::reset();
        $this->nodeTraverser = null;

        if (isset($this->resources)) {
            $this->resources = [];
        }
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
        if (\is_array($this->resources)) {
            $this->resources[(string) $resource] = $resource;
        }

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

        if (!empty($processedData[0])) {
            $containerNode->addStmt($this->resolver->getBuilder()->property('aliases')->makeProtected()->setType('array')->setDefault($processedData[0]));
        }

        if (!empty($parameters = $this->parameters)) {
            \ksort($parameters);
            $this->compileToConstructor($this->resolveParameters($parameters), $containerNode, 'parameters');
        }

        if (!empty($processedData[3])) {
            $containerNode->addStmt($this->resolver->getBuilder()->property('types')->makeProtected()->setType('array')->setDefault($processedData[3]));
        }

        if (!empty($processedData[4])) {
            $containerNode->addStmt($this->resolver->getBuilder()->property('tags')->makeProtected()->setType('array')->setDefault($processedData[4]));
        }

        if (!empty($processedData[1])) {
            $this->compileHasGetMethod($processedData[1], $containerNode);
        }
        $astNodes[] = $containerNode->addStmts($processedData[2])->getNode();

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
     * @param mixed $definition
     *
     * @return mixed
     */
    public function dumpObject(string $id, $definition)
    {
        $method = $this->resolver->getBuilder()->method($this->resolver::createMethod($id))->makeProtected();
        $cachedService = new Expr\ArrayDimFetch(new Expr\PropertyFetch(new Expr\Variable('this'), 'services'), new String_($id));

        if ($definition instanceof Expression) {
            $definition = $definition->expr;
        }

        if ($definition instanceof \PhpParser\Node) {
            if ($definition instanceof Expr\Array_) {
                $method->setReturnType('array');
            } elseif ($definition instanceof Expr\New_) {
                $method->setReturnType($definition->class->toString());
            }
        } elseif (\is_object($definition)) {
            if ($definition instanceof \Closure) {
                throw new ServiceCreationException(\sprintf('Cannot dump closure for service "%s".', $id));
            }

            if ($definition instanceof \stdClass) {
                $method->setReturnType('object');
                $definition = new Expr\Cast\Object_($this->resolver->getBuilder()->val($this->resolver->resolveArguments((array) $definition)));
            } elseif ($definition instanceof \IteratorAggregate) {
                $method->setReturnType('iterable');
                $definition = $this->resolver->getBuilder()->new(\get_class($definition), [$this->resolver->resolveArguments(\iterator_to_array($definition))]);
            } else {
                $method->setReturnType(\get_class($definition));
                $definition = $this->resolver->getBuilder()->funcCall('\\unserialize', [new String_(\serialize($definition), ['docLabel' => 'SERIALIZED', 'kind' => String_::KIND_NOWDOC])]);
            }
        }

        return $method->addStmt(new \PhpParser\Node\Stmt\Return_(new Expr\Assign($cachedService, $this->resolver->getBuilder()->val($definition))));
    }

    /**
     * {@inheritdoc}
     */
    protected function doCreate(string $id, $definition, int $invalidBehavior)
    {
        if ($definition instanceof String_ && $id === $definition->value) {
            unset($this->services[$id]);

            return null;
        }

        $compiledDefinition = $definition instanceof DefinitionInterface ? $definition->build($id, $this->resolver) : $this->dumpObject($id, $definition);

        if (self::BUILD_SERVICE_DEFINITION !== $invalidBehavior) {
            $resolved = $this->resolver->getBuilder()->methodCall($this->resolver->getBuilder()->var('this'), $this->resolver::createMethod($id));
            $serviceType = 'services';

            if ($definition instanceof ShareableDefinitionInterface) {
                if (!$definition->isShared()) {
                    return $this->services[$id] = $resolved;
                }

                if (!$definition->isPublic()) {
                    $serviceType = 'privates';
                }
            }

            $service = $this->resolver->getBuilder()->propertyFetch($this->resolver->getBuilder()->var('this'), $serviceType);
            $createdService = new Expr\BinaryOp\Coalesce(new Expr\ArrayDimFetch($service, new String_($id)), $resolved);

            return $this->services[$id] = $createdService;
        }

        return $compiledDefinition->getNode();
    }

    /**
     * Analyse all definitions, build definitions and return results.
     *
     * @param DefinitionInterface[] $definitions
     */
    protected function doAnalyse(array $definitions, bool $onlyDefinitions = false): array
    {
        $methodsMap = $serviceMethods = $wiredTypes = [];
        $s = $this->services[self::SERVICE_CONTAINER] ?? new Expr\Variable('this');

        if (!isset($methodsMap[self::SERVICE_CONTAINER])) {
            $methodsMap[self::SERVICE_CONTAINER] = 80000 <= \PHP_VERSION_ID ? new MatchArm([new String_(self::SERVICE_CONTAINER)], $s) : new Case_(new String_(self::SERVICE_CONTAINER), [new Return_($s)]);
        }

        foreach ($definitions as $id => $definition) {
            if ($this->tagged('container.remove_services', $id)) {
                continue;
            }

            $m = ($b= $this->resolver->getBuilder())->methodCall($s, $this->resolver::createMethod($id));
            $methodsMap[$id] = 80000 <= \PHP_VERSION_ID ? new MatchArm([$si = new String_($id)], $m) : new Case_($si = new String_($id), [new Return_($m)]);

            if ($definition instanceof ShareableDefinitionInterface) {
                if (!$definition->isPublic()) {
                    unset($methodsMap[$id]);
                } elseif ($definition->isShared()) {
                    $sr = new Expr\ArrayDimFetch($b->propertyFetch($s, 'services'), $si);
                    $sb = &$methodsMap[$id];
                    $sb instanceof MatchArm ? $sb->body = new Expr\BinaryOp\Coalesce($sr, $sb->body) : $sb->stmts[0]->expr = new Expr\BinaryOp\Coalesce($sr, $sb->stmts[0]->expr);
                }

                if ($definition->isAbstract()) {
                    unset($methodsMap[$id]);
                    continue;
                }
            }

            $serviceMethods[] = $this->doCreate($id, $definition, self::BUILD_SERVICE_DEFINITION);
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

    /**
     * Build parameters + dynamic parameters in compiled container class.
     *
     * @param array<int,array<string,mixed>> $parameters
     */
    protected function compileToConstructor(array $parameters, \PhpParser\Builder\Class_ &$containerNode, string $name): void
    {
        [$resolvedParameters, $dynamicParameters] = $parameters;

        if (!empty($dynamicParameters)) {
            $resolver = $this->resolver;
            $container = $this->containerParentClass;
            $containerNode = \Closure::bind(function (\PhpParser\Builder\Class_ $node) use ($dynamicParameters, $resolver, $container, $name) {
                $endMethod = \array_pop($node->methods);
                $constructorNode = $resolver->getBuilder()->method('__construct');

                if ($endMethod instanceof ClassMethod && '__construct' === $endMethod->name->name) {
                    $constructorNode->addStmts([...$endMethod->stmts, new Nop()]);
                } elseif (\method_exists($container, '__construct')) {
                    $constructorNode->addStmt($resolver->getBuilder()->staticCall(new Name('parent'), '__construct'));
                }

                foreach ($dynamicParameters as $offset => $value) {
                    $parameter = $resolver->getBuilder()->propertyFetch($resolver->getBuilder()->var('this'), $name);
                    $constructorNode->addStmt(new Expr\Assign(new Expr\ArrayDimFetch($parameter, new String_($offset)), $resolver->getBuilder()->val($value)));
                }

                return $node->addStmt($constructorNode->makePublic());
            }, $containerNode, $containerNode)($containerNode);
        }

        if (!empty($resolvedParameters)) {
            $containerNode->addStmt($this->resolver->getBuilder()->property($name)->makePublic()->setType('array')->setDefault($resolvedParameters));
        }
    }

    /**
     * Build the container's get method.
     */
    protected function compileHasGetMethod(array $getMethods, \PhpParser\Builder\Class_ &$containerNode): void
    {
        if (!\method_exists($this->containerParentClass, 'doLoad')) {
            throw new ServiceCreationException(\sprintf('The %c class must have a "doLoad" protected method', $this->containerParentClass));
        }

        if (!\method_exists($this->containerParentClass, 'has')) {
            throw new ServiceCreationException(\sprintf('The %c class must have a "has" public method', $this->containerParentClass));
        }

        $p8 = 80000 <= \PHP_VERSION_ID;
        $s = $this->services[self::SERVICE_CONTAINER] ?? new Expr\Variable('this');

        if ($p8) {
            $getMethods[] = $md = new MatchArm([new Expr\ConstFetch(new Name('default'))], new Expr\ConstFetch(new Name('null')));
        }
        $getNode = ($b = $this->resolver->getBuilder())->method('get')->makePublic();
        $hasNode = $b->method('has')->makePublic()->setReturnType('bool');
        $ia = new Expr\Assign($i = new Expr\Variable('id'), new Expr\BinaryOp\Coalesce(new Expr\ArrayDimFetch($b->propertyFetch($s, 'aliases'), $i), $i));
        $hasNode->addParam($mi = new Param($i, null, 'string'));
        $getNode->addParams([$mi, new Param($ib = new Expr\Variable('invalidBehavior'), $b->val(1), 'int')]);
        $getNode->addStmt($p8 ? new Expr\Assign($sv = new Expr\Variable('s'), new Expr\Match_($ia, $getMethods)) : new \PhpParser\Node\Stmt\Switch_($ia, $getMethods));
        $sf = new Expr\BinaryOp\Coalesce(new Expr\ArrayDimFetch($b->propertyFetch($s, 'services'), $i), $b->methodCall($s, 'doLoad', [$i, $ib]));
        $hf = $b->staticCall('parent', 'has', [$i]);

        if ($p8) {
            unset($getMethods[0]);
            $hasNode->addStmt(new Expr\Assign($sv, new Expr\Match_($i, [new MatchArm(\array_map([$b, 'val'], \array_keys($getMethods)), $b->val(true)), $md])));
            $hf = new Expr\BinaryOp\Coalesce($sv, $hf);
            $sf = new Expr\BinaryOp\Coalesce($sv, $sf);
        } else {
            $hf = new Expr\BinaryOp\BooleanOr($b->funcCall('method_exists', [$s, $b->staticCall(Resolver::class, 'createMethod', [$i])]), $hf);
        }

        $containerNode->addStmt($hasNode->addStmt(new Return_($hf)));
        $containerNode->addStmt($getNode->addStmt(new Return_($sf)));
    }

    /**
     * Resolve parameter's and retrieve dynamic type parameter.
     *
     * @param array<string,mixed> $parameters
     *
     * @return array<int,mixed>
     */
    protected function resolveParameters(array $parameters, bool $recursive = false): array
    {
        $resolvedParameters = $dynamicParameters = [];

        if (!$recursive) {
            $parameters = $this->resolver->resolveArguments($parameters);
        }

        foreach ($parameters as $parameter => $value) {
            if (\is_array($value)) {
                $arrayParameters = $this->resolveParameters($value, $recursive);

                if (!empty($arrayParameters[1])) {
                    $grouped = $arrayParameters[1] + $arrayParameters[0];
                    \uksort($grouped, fn ($a, $b) => (\is_int($a) && \is_int($b) ? $a <=> $b : 0));
                    $dynamicParameters[$parameter] = $grouped;
                } else {
                    $resolvedParameters[$parameter] = $arrayParameters[0];
                }

                continue;
            }

            if ($value instanceof Scalar || $value instanceof Expr\ConstFetch) {
                $resolvedParameters[$parameter] = $value;

                continue;
            }

            $dynamicParameters[$parameter] = $value;
        }

        return [$resolvedParameters, $dynamicParameters];
    }
}
