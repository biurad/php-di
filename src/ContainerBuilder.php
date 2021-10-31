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

use PhpParser\Node\{Expr, Scalar\String_};
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Stmt\{Declare_, DeclareDeclare};
use Rade\DI\Definitions\DefinitionInterface;
use Rade\DI\Definitions\ShareableDefinitionInterface;
use Rade\DI\Exceptions\ContainerResolutionException;
use Symfony\Component\Config\{
    Resource\ClassExistenceResource,
    Resource\FileResource,
    Resource\FileExistenceResource,
    Resource\ResourceInterface
};

class ContainerBuilder extends AbstractContainer
{
    private const BUILD_SERVICE_DEFINITION = 5;

    private bool $trackResources;

    /** @var ResourceInterface[] */
    private array $resources = [];

    /** Name of the compiled container parent class. */
    private string $containerParentClass;

    private \PhpParser\BuilderFactory $builder;

    /**
     * Compile the container for optimum performances.
     *
     * @param string $containerParentClass Name of the compiled container parent class. Customize only if necessary.
     */
    public function __construct(string $containerParentClass = Container::class)
    {
        parent::__construct();

        if (!\is_subclass_of($containerParentClass, AbstractContainer::class)) {
            throw new ContainerResolutionException(\sprintf('Compiled container class must be a extendable of "%s" class, which "%s" is not.', AbstractContainer::class, $containerParentClass));
        }

        $this->containerParentClass = $containerParentClass;
        $this->trackResources = \interface_exists(ResourceInterface::class);

        $this->builder = $this->resolver->getBuilder();
        $this->services[self::SERVICE_CONTAINER] = $this->builder->var('this');
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $id, int $invalidBehavior = /* self::EXCEPTION_ON_MULTIPLE_SERVICE */ 1)
    {
        return $this->services[$id = $this->aliases[$id] ?? $id]
            ?? self::SERVICE_CONTAINER === $id ? $this->services[$id] = new Expr\Variable('this') : $this->doGet($id, $invalidBehavior);
    }

    /**
     * Returns an array of resources loaded to build this configuration.
     *
     * @return ResourceInterface[] An array of resources
     */
    public function getResources(): array
    {
        return \array_values($this->resources);
    }

    /**
     * Add a resource to to allow re-build of container.
     *
     * @return $this
     */
    public function addResource(ResourceInterface $resource): self
    {
        if ($this->trackResources) {
            $this->resources[(string) $resource] = $resource;
        }

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
     * - spacingLevel => 8,
     * - containerClass => CompiledContainer,
     *
     * @throws \ReflectionException
     *
     * @return \PhpParser\Node[]|string
     */
    public function compile(array $options = [])
    {
        $options += ['strictType' => true, 'printToString' => true, 'containerClass' => 'CompiledContainer'];
        $astNodes = [];

        foreach ($this->providers as $name => $builder) {
            if ($this->trackResources) {
                $this->addResource(new ClassExistenceResource($name, false));
                $this->addResource(new FileExistenceResource($rPath = (new \ReflectionClass($name))->getFileName()));
                $this->addResource(new FileResource($rPath));
            }

            if ($builder instanceof Services\PrependInterface) {
                $builder->before($this);
            }
        }

        if ($options['strictType']) {
            $astNodes[] = new Declare_([new DeclareDeclare('strict_types', $this->builder->val(1))]);
        }

        $compiledContainerData = $this->doAnalyse($this->definitions);
        $compiledContainerNode = $this->builder->class($options['containerClass'])->extend($this->containerParentClass)->setDocComment(Builder\CodePrinter::COMMENT);

        if (!empty($compiledContainerData[0])) {
            $compiledContainerNode->addStmt($this->builder->property('aliases')->makeProtected()->setType('array')->setDefault($compiledContainerData[0]));
        }

        if (!empty($compiledContainerData[1])) {
            $compiledContainerNode->addStmt($this->builder->property('parameters')->makePublic()->setType('array')->setDefault($compiledContainerData[1]));
        }

        if (!empty($compiledContainerData[2])) {
            $compiledContainerNode->addStmt($this->builder->property('methodsMap')->makeProtected()->setType('array')->setDefault($compiledContainerData[2]));
        }

        if (!empty($compiledContainerData[4])) {
            $compiledContainerNode->addStmt($this->builder->property('types')->makeProtected()->setType('array')->setDefault($compiledContainerData[4]));
        }

        $astNodes[] = $compiledContainerNode->addStmts($compiledContainerData[3])->getNode();

        if ($options['printToString']) {
            return Builder\CodePrinter::print($astNodes, $options);
        }

        return $astNodes;
    }

    /**
     * @param mixed $createdService
     *
     * @return mixed
     */
    public function dumpObject(string $id, $createdService, bool $nullOnInvalid)
    {
        if (null === $createdService) {
            $anotherService = $this->resolver->resolve($id);

            if (!$anotherService instanceof String_) {
                return $anotherService;
            }

            if (!$nullOnInvalid) {
                throw $this->createNotFound($id);
            }

            return null;
        }

        // @Todo: Support for dumping objects in compiled container.
        return $this->resolver->getBuilder()->val($createdService);
    }

    /**
     * {@inheritdoc}
     */
    protected function doCreate(string $id, $definition, int $invalidBehavior)
    {
        /** @var DefinitionInterface $definition */
        $compiledDefinition = $definition->build($id, $this->resolver);

        if (self::BUILD_SERVICE_DEFINITION !== $invalidBehavior) {
            $resolved = $this->builder->methodCall($this->builder->var('this'), $this->resolver->createMethod($id));
            $serviceType = 'services';

            if ($definition instanceof Definition) {
                if (!$definition->isShared()) {
                    return $this->services[$id] = $resolved;
                }

                if (!$definition->isPublic()) {
                    $serviceType = 'privates';
                }
            }

            $service = $this->builder->propertyFetch($this->builder->var('this'), $serviceType);
            $createdService = new Expr\BinaryOp\Coalesce(new Expr\ArrayDimFetch($service, new String_($id)), $resolved);

            return self::IGNORE_SERVICE_INITIALIZING === $invalidBehavior ? $createdService : $this->services[$id] = $createdService;
        }

        return $compiledDefinition;
    }

    /**
     * Analyse all definitions, build definitions and return results.
     *
     * @param DefinitionInterface[] $definitions
     */
    protected function doAnalyse(array $definitions): array
    {
        $methodsMap = $serviceMethods = $wiredTypes = [];
        \ksort($definitions);

        foreach ($definitions as $id => $definition) {
            $serviceMethods[] = $this->doCreate($id, $definition, self::BUILD_SERVICE_DEFINITION);

            if ($definition instanceof ShareableDefinitionInterface && !$definition->isPublic()) {
                continue;
            }

            $methodsMap[$id] = $this->resolver->createMethod($id);
        }

        $aliases = \array_filter($this->aliases, fn ($aliased): bool => isset($methodsMap[$aliased]));
        $parameters = \array_map(fn ($value) => $this->builder->val($value), $this->parameters);

        // Prevent autowired private services from be exported.
        foreach ($this->types as $type => $ids) {
            $ids = \array_filter($ids, fn (string $id): bool => isset($methodsMap[$id]));

            if ([] !== $ids) {
                $ids = \array_values($ids); // If $ids are filtered, keys should not be preserved.
                $wiredTypes[] = new ArrayItem($this->builder->val($ids), $this->builder->constFetch($type . '::class'));
            }
        }

        return [$aliases, $parameters, $methodsMap, $serviceMethods, $wiredTypes];
    }
}
