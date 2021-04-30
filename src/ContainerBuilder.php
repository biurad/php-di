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

use PhpParser\Node\{Expr\ArrayItem, Stmt\Declare_, Stmt\DeclareDeclare};
use Rade\DI\{
    Builder\Statement,
    Exceptions\CircularReferenceException,
    Exceptions\NotFoundServiceException,
    Exceptions\ServiceCreationException
};
use Symfony\Component\Config\{
    Resource\ClassExistenceResource,
    Resource\FileResource,
    Resource\ResourceInterface
};

class ContainerBuilder extends AbstractContainer
{
    private bool $trackResources;

    /** @var ResourceInterface[] */
    private array $resources = [];

    /** @var Definition[] */
    private array $definitions = [];

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

        $this->containerParentClass = $containerParentClass;
        $this->trackResources = \interface_exists(ResourceInterface::class);

        $this->builder = new \PhpParser\BuilderFactory();
        $this->resolver = new Resolvers\Resolver($this);

        $this->resolver->autowire('container', [$containerParentClass]);
    }

    /**
     * {@inheritdoc}
     */
    public function __call(string $name, array $args)
    {
        if ('call' === $name) {
            throw new ServiceCreationException(\sprintf('Refactor your code to use %s class instead.', Statement::class));
        }

        if ('resolveClass' === $name) {
            $class = new \ReflectionClass($service = $args[0]);

            if ($class->isAbstract() || !$class->isInstantiable()) {
                throw new ServiceCreationException(\sprintf('Class entity %s is an abstract type or instantiable.', $service));
            }

            if ((null !== $constructor = $class->getConstructor()) && $constructor->isPublic()) {
                return $this->builder->new($service, $this->resolver->autowireArguments($constructor, $args[1] ?? []));
            }

            if (!empty($args[1] ?? [])) {
                throw new ServiceCreationException("Unable to pass arguments, class $service has no constructor or constructor is not public.");
            }

            return $this->builder->new($service);
        }

        return parent::__call($name, $args);
    }

    /**
     * Extends an object definition.
     *
     * @param string $id The unique identifier for the definition
     *
     * @throws NotFoundServiceException If the identifier is not defined
     */
    public function extend(string $id): Definition
    {
        $extended = $this->definitions[$id] ?? $this->createNotFound($id, true);

        if ($extended instanceof RawDefinition) {
            throw new ServiceCreationException(\sprintf('Extending a raw definition for "%s" is not supported.', $id));
        }

        // Incase service has been cached, remove it.
        unset(self::$services[$id]);

        return $this->definitions[$id] = $extended;
    }

    /**
     * Sets a autowired service definition.
     *
     * @param string|array|Definition|Statement $definition
     */
    public function autowire(string $id, $definition): Definition
    {
        if ($definition instanceof RawDefinition) {
            throw new ServiceCreationException(
                \sprintf('Service %s using %s instance is not supported for autowiring.', $id, RawDefinition::class)
            );
        }

        return $this->set($id, $definition)->autowire();
    }

    /**
     * Sets a service definition.
     *
     * @param string|array|Definition|Statement|RawDefinition $definition
     *
     * @return Definition|RawDefinition the service definition
     */
    public function set(string $id, $definition)
    {
        unset($this->aliases[$id]);

        if (!$definition instanceof RawDefinition) {
            if (!$definition instanceof Definition) {
                $definition = new Definition($definition);
            }

            $definition->attach($id, $this->resolver);
        }

        return $this->definitions[$id] = $definition;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $id, int $invalidBehavior = /* self::EXCEPTION_ON_MULTIPLE_SERVICE */ 1)
    {
        switch (true) {
            case isset(self::$services[$id]):
                return self::$services[$id];

            case isset($this->definitions[$id]):
                return self::$services[$id] = $this->doCreate($id, $this->definitions[$id]);

            case $this->resolver->has($id):
                return $this->resolver->get($id, (bool) $invalidBehavior);

            case isset($this->providers[$id]):
                return $this->providers[$id];

            case isset($this->aliases[$id]):
                return $this->get($this->aliases[$id]);

            default:
                throw $this->createNotFound($id);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $id): bool
    {
        return (isset($this->definitions[$id]) || isset($this->providers[$id]))
            || ($this->resolver->has($id) || isset($this->aliases[$id]));
    }

    /**
     * {@inheritdoc}
     */
    public function remove(string $id): void
    {
        if (isset($this->definitions[$id])) {
            unset($this->definitions[$id], self::$services[$id]);
        }

        parent::remove($id);
    }

    /**
     * {@inheritdoc}
     */
    public function keys(): array
    {
        return \array_keys($this->definitions);
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
        if (!$this->trackResources) {
            return $this;
        }

        $this->resources[(string) $resource] = $resource;

        return $this;
    }

    /**
     * Get the builder use to compiler container.
     */
    public function getBuilder(): \PhpParser\BuilderFactory
    {
        return $this->builder;
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
        $astNodes = [];

        foreach ($this->providers as $name => $builder) {
            if ($this->trackResources) {
                $this->addResource(new FileResource((new \ReflectionClass($name))->getFileName()));
                $this->addResource(new ClassExistenceResource($name, false));
            }

            if ($builder instanceof Builder\PrependInterface) {
                $builder->before($this);
            }
        }

        if ($options['strictType']) {
            $astNodes[] = new Declare_([new DeclareDeclare('strict_types', $this->builder->val(1))]);
        }

        $parameters = \array_map(fn ($value) => $this->builder->val($value), $this->parameters);
        $astNodes[] = $this->doCompile($this->definitions, $parameters, $options['containerClass'])->getNode();

        if ($options['printToString']) {
            return Builder\CodePrinter::print($astNodes, $options);
        }

        return $astNodes;
    }

    /**
     * {@inheritdoc}
     *
     * @param Definition|RawDefinition $service
     */
    protected function doCreate(string $id, $service, bool $build = false)
    {
        if ($service instanceof RawDefinition) {
            return $this->builder->val($service());
        }

        if (isset($this->loading[$id])) {
            throw new CircularReferenceException($id, [...\array_keys($this->loading), $id]);
        }

        try {
            $this->loading[$id] = true;

            // Strict circular reference check ...
            $compiled = $service->build($this->builder);

            if (!$build) {
                return $service->resolve($this->builder);
            }

            return $compiled;
        } finally {
            unset($this->loading[$id]);
        }
    }

    /**
     * @param Definition[] $definitions
     */
    protected function doCompile(array $definitions, array $parameters, string $containerClass): \PhpParser\Builder\Class_
    {
        [$methodsMap, $serviceMethods, $wiredTypes] = $this->doAnalyse($definitions);

        return $this->builder->class($containerClass)->extend($this->containerParentClass)
            ->setDocComment(Builder\CodePrinter::COMMENT)
            ->addStmts($serviceMethods)
            ->addStmt($this->builder->property('parameters')
                ->makePublic()->setType('array')
                ->setDefault($parameters))
            ->addStmt($this->builder->property('privates')
                ->makeProtected()->setType('array')
                ->makeStatic()->setDefault([]))
            ->addStmt($this->builder->property('methodsMap')
                ->makeProtected()->setType('array')
                ->setDefault($methodsMap))
            ->addStmt($this->builder->property('types')
                ->makeProtected()->setType('array')
                ->setDefault($wiredTypes))
            ->addStmt($this->builder->property('aliases')
                ->makeProtected()->setType('array')
                ->setDefault($this->aliases))
        ;
    }

    /**
     * Analyse all definitions, build definitions and return results.
     *
     * @param Definition[] $definitions
     */
    protected function doAnalyse(array $definitions): array
    {
        $methodsMap = $serviceMethods = $wiredTypes = [];
        \ksort($definitions);

        foreach ($definitions as $id => $definition) {
            if ($definition instanceof RawDefinition) {
                continue; // @Todo: support exporting raw service definition.
            }

            $serviceMethods[$id] = $this->doCreate($id, $definition, true);

            if (!$definition->is(Definition::PRIVATE)) {
                $methodsMap[$id] = (string) $definition;
            }
        }

        // Use Default Container method ...
        $methodsMap['container'] = 'getServiceContainer';

        // Remove private aliases
        foreach ($this->aliases as $aliased => $service) {
            if ($this->ignoredDefinition($definitions[$service] ?? null)) {
                unset($this->aliases[$aliased]);
            }
        }

        // Prevent autowired private services from be exported.
        foreach ($this->resolver->export() as $type => $ids) {
            if (1 === \count($ids) && $this->ignoredDefinition($definitions[\reset($ids)] ?? null)) {
                continue;
            }

            $ids = \array_filter($ids, fn (string $id): bool => !$this->ignoredDefinition($definitions[$id] ?? null));

            // If $ids are filtered, keys should not be preserved.
            $ids = \array_values($ids);

            $wiredTypes[] = new ArrayItem($this->builder->val($ids), $this->builder->constFetch($type . '::class'));
        }

        return [$methodsMap, $serviceMethods, $wiredTypes];
    }

    /**
     * @param RawDefinition|Definition|null $def
     */
    private function ignoredDefinition($def): bool
    {
        return $def instanceof RawDefinition || ($def instanceof Definition && $def->is(Definition::PRIVATE));
    }
}
