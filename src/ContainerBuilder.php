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

use PhpParser\Node\{Expr, Name, Scalar, Scalar\String_};
use PhpParser\Node\Stmt\{ClassMethod, Declare_, DeclareDeclare, Expression};
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

        parent::__construct();

        $this->containerParentClass = $containerParentClass;
        $this->resources = \interface_exists(ResourceInterface::class) ? [] : null;

        $this->type(self::SERVICE_CONTAINER, Resolvers\Resolver::autowireService($containerParentClass));
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $id, ?object $definition = null): object
    {
        if ($definition instanceof \PhpParser\Node) {
            $definition = new Definitions\ValueDefinition($definition);
        }

        return parent::set($id, $definition);
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $id, int $invalidBehavior = /* self::EXCEPTION_ON_MULTIPLE_SERVICE */ 1)
    {
        if (isset($this->services[$id])) {
            return $this->services[$id];
        }

        if (\array_key_exists($id, $this->aliases)) {
            return $this->services[$id = $this->aliases[$id]] ?? $this->get($id);
        }

        if (self::SERVICE_CONTAINER === $id) {
            return $this->services[$id] = new Expr\Variable('this');
        }

        return parent::get($id, $invalidBehavior);
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
     * Add a resource to to allow re-build of container.
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
        $containerNode = $this->resolver->getBuilder()->class($options['containerClass'])->extend($this->containerParentClass);

        if (!empty($processedData[0])) {
            $containerNode->addStmt($this->resolver->getBuilder()->property('aliases')->makeProtected()->setType('array')->setDefault($processedData[0]));
        }

        $this->compileParameters($this->parameters, $containerNode->setDocComment(Builder\CodePrinter::COMMENT));

        if (!empty($processedData[1])) {
            $containerNode->addStmt($this->resolver->getBuilder()->property('methodsMap')->makeProtected()->setType('array')->setDefault($processedData[1]));
        }

        if (!empty($processedData[3])) {
            $containerNode->addStmt($this->resolver->getBuilder()->property('types')->makeProtected()->setType('array')->setDefault($processedData[3]));
        }

        if (!empty($processedData[4])) {
            $containerNode->addStmt($this->resolver->getBuilder()->property('tags')->makeProtected()->setType('array')->setDefault($processedData[4]));
        }

        $astNodes[] = $containerNode->addStmts($processedData[2])->getNode();

        if (null !== $this->nodeTraverser) {
            $astNodes = $this->nodeTraverser->traverse($astNodes);
        }

        if ($options['printToString']) {
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
        $method = $this->resolver->getBuilder()->method($this->resolver->createMethod($id))->makeProtected();

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
            } elseif ($definition instanceof \stdClass) {
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

        $cachedService = new Expr\ArrayDimFetch(new Expr\PropertyFetch(new Expr\Variable('this'), 'services'), new String_($id));

        return $method->addStmt(new \PhpParser\Node\Stmt\Return_(new Expr\Assign($cachedService, $this->resolver->getBuilder()->val($definition))));
    }

    /**
     * {@inheritdoc}
     */
    protected function doCreate(string $id, $definition, int $invalidBehavior)
    {
        if (!$definition) {
            $anotherService = $this->resolver->resolve($id);

            if (!$anotherService instanceof String_) {
                return $anotherService;
            }

            if (self::NULL_ON_INVALID_SERVICE !== $invalidBehavior) {
                throw $this->createNotFound($id);
            }

            return null;
        }

        $compiledDefinition = $definition instanceof DefinitionInterface ? $definition->build($id, $this->resolver) : $this->dumpObject($id, $definition);

        if (self::BUILD_SERVICE_DEFINITION !== $invalidBehavior) {
            $resolved = $this->resolver->getBuilder()->methodCall($this->resolver->getBuilder()->var('this'), $this->resolver->createMethod($id));
            $serviceType = 'services';

            if ($definition instanceof ShareableDefinitionInterface) {
                if (!$definition->isShared()) {
                    return $resolved;
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

        foreach ($definitions as $id => $definition) {
            if ($this->tagged('container.remove_services', $id)) {
                continue;
            }

            $serviceMethods[] = $this->doCreate($id, $definition, self::BUILD_SERVICE_DEFINITION);

            if ($definition instanceof ShareableDefinitionInterface && !$definition->isPublic()) {
                continue;
            }

            $methodsMap[$id] = $this->resolver->createMethod($id);
        }

        if ($onlyDefinitions) {
            return [$methodsMap, $serviceMethods];
        }

        if ($newDefinitions = \array_diff_key($this->definitions, $definitions)) {
            $processedData = $this->doAnalyse($newDefinitions, true);
            $methodsMap = \array_merge($methodsMap, $processedData[0]);
            $serviceMethods = \array_merge($serviceMethods, $processedData[1]);
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

        \ksort($aliases);
        \ksort($tags);
        \ksort($methodsMap);
        \usort($serviceMethods, fn (ClassMethod $a, ClassMethod $b): int => \strnatcmp($a->name->toString(), $b->name->toString()));
        \usort($wiredTypes, fn (Expr\ArrayItem $a, Expr\ArrayItem $b): int => \strnatcmp($a->key->value, $b->key->value));

        return [$aliases, $methodsMap, $serviceMethods, $wiredTypes, $tags];
    }

    /**
     * Build parameters + dynamic parameters in compiled container class.
     *
     * @param array<int,array<string,mixed>> $parameters
     */
    protected function compileParameters(array $parameters, \PhpParser\Builder\Class_ $containerNode): void
    {
        if (0 === \count($parameters)) {
            return;
        }

        \ksort($parameters);
        [$resolvedParameters, $dynamicParameters] = $this->resolveParameters($parameters);

        if (!empty($dynamicParameters)) {
            $constructorNode = $this->resolver->getBuilder()->method('__construct');

            if (\method_exists($this->containerParentClass, '__construct')) {
                $constructorNode->addStmt($this->resolver->getBuilder()->staticCall(new Name('parent'), '__construct'));
            }

            foreach ($dynamicParameters as $offset => $value) {
                $parameter = $this->resolver->getBuilder()->propertyFetch($this->resolver->getBuilder()->var('this'), 'parameter');
                $constructorNode->addStmt(new Expr\Assign(new Expr\ArrayDimFetch($parameter, new String_($offset)), $this->resolver->getBuilder()->val($value)));
            }

            $containerNode->addStmt($constructorNode->makePublic());
        }

        $containerNode->addStmt($this->resolver->getBuilder()->property('parameters')->makePublic()->setType('array')->setDefault($resolvedParameters));
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
                    $dynamicParameters[$parameter] = \array_merge(...$arrayParameters);
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
