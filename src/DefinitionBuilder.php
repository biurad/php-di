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

use Nette\Utils\{ObjectHelpers, Validators};
use Rade\DI\Definitions\DefinitionInterface;
use Symfony\Component\Config\Resource\{ClassExistenceResource, FileExistenceResource, FileResource};

/**
 * A builder specialized in creating homogeneous service definitions.
 *
 * This class has some performance impact and recommended to be used with ContainerBuilder class.
 *
 * @experimental in 1.0
 *
 * @method self|Definition autowire(string $id, Definitions\TypedDefinitionInterface|object|null $definition = null)
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
final class DefinitionBuilder
{
    private AbstractContainer $container;

    /** @var array<string,array<int,mixed>> */
    private array $classes = [];

    /** @var array<string,array<int,array<int,mixed>>> */
    private array $defaults = [];

    private ?string $definition = null;

    private ?string $directory = null;

    private bool $trackDefaults = false;

    public function __construct(AbstractContainer $container)
    {
        $this->container = $container;
    }

    public function __destruct()
    {
        if (!empty($this->classes)) {
            foreach ($this->classes as [$definition, $classes]) {
                // prepare for deep cloning
                $serializedDef = \serialize($definition);

                foreach ($classes as $resource) {
                    $this->container->set($resource, (\unserialize($serializedDef))->replace($resource, true));
                }
            }

            $this->classes = [];
        }
    }

    /**
     * Where all the magic happens.
     *
     * @param array<int,mixed> $arguments
     *
     * @return $this
     */
    public function __call(string $name, array $arguments)
    {
        if (!$id = $this->definition) {
            throw $this->createInitializingError(__METHOD__);
        }

        if ($this->trackDefaults) {
            $this->defaults[$id][] = [$name, $arguments];
        } else {
            try {
                (!isset($this->classes[$id]) ? $this->container->definition($id) : $this->classes[$id][0])->{$name}(...$arguments);
            } catch (\Error $e) {
                throw $this->createErrorException($name, $e);
            }
        }

        return $this;
    }

    /**
     * Set a config into container's parameter.
     *
     * @param mixed $value
     *
     * @return $this
     */
    public function parameter(string $name, $value)
    {
        $this->container->parameters[$name] = $value;

        return $this;
    }

    /**
     * Marks an alias id to service id.
     *
     * @return $this
     */
    public function alias(string $id, string $serviceId = null)
    {
        $this->container->alias($id, $serviceId ?? $this->definition);

        return $this;
    }

    /**
     * Enables autowiring.
     *
     * @param string                                           $id
     * @param array<int,string>                                $types
     * @param Definitions\TypedDefinitionInterface|object|null $definition
     *
     * @return Definition|$this
     */
    public function autowire(/* string $id, $definition or array $types */)
    {
        $arguments = \func_get_args();

        if (\is_string($arguments[0] ?? null)) {
            $this->doCreate($this->container->autowire($this->definition = $arguments[0], $arguments[1] ?? null));

            return $this;
        }

        if (!$id = $this->definition) {
            throw $this->createInitializingError(__FUNCTION__);
        }

        if ($this->trackDefaults) {
            $this->defaults[$id][] = [__FUNCTION__, $arguments];
        } else {
            $definition = !isset($this->classes[$id]) ? $this->container->definition($id) : $this->classes[$id][0];

            if ($definition instanceof Definitions\TypedDefinitionInterface) {
                $definition->autowire($arguments[0] ?? []);
            }
        }

        return $this;
    }

    /**
     * Set a service definition.
     *
     * @param DefinitionInterface|object|null $definition
     *
     * @return Definition|$this
     */
    public function set(string $id, object $definition = null)
    {
        $this->doCreate($this->container->set($this->definition = $id, $definition));

        return $this;
    }

    /**
     * Extends a service definition.
     *
     * @return Definition|$this
     */
    public function extend(string $id)
    {
        $this->doCreate($this->container->definition($this->definition = $id));

        return $this;
    }

    /**
     * Replaces old service with a new one, but keeps a reference of the old one as: service_id.inner.
     *
     * @param DefinitionInterface|object|null $definition
     *
     * @see Rade\DI\Traits\DefinitionTrait::decorate
     *
     * @return Definition|$this
     */
    public function decorate(string $id, object $definition = null)
    {
        $this->doCreate($this->container->decorate($this->definition = $id, $definition));

        return $this;
    }

    /**
     * Defines a set of defaults for following service definitions.
     *
     * @param bool $merge If true, new defaults will be merged into existing
     *
     * @return Definition|$this
     */
    public function defaults(bool $merge = true)
    {
        $this->trackDefaults = true;
        $this->definition = '#defaults';

        if (!$merge) {
            $this->defaults[$this->definition] = [];
        }

        return $this;
    }

    /**
     * Defines a set of defaults only for services whose class matches a defined one.
     *
     * @return Definition|$this
     */
    public function instanceOf(string $interfaceOrClass)
    {
        $this->trackDefaults = true;

        if (!Validators::isType($interfaceOrClass)) {
            throw new \RuntimeException(\sprintf('"%s" is set as an "instanceof" conditional, but it does not exist.', $interfaceOrClass));
        }

        $this->definition = $interfaceOrClass;

        return $this;
    }

    /**
     * Registers a set of classes as services using PSR-4 for discovery.
     *
     * @param string               $namespace The namespace prefix of classes in the scanned directory
     * @param string|null          $resource  The directory to look for classes, glob-patterns allowed
     * @param string|string[]|null $exclude   A globbed path of files to exclude or an array of globbed paths of files to exclude
     *
     * @return $this
     */
    public function namespaced(string $namespace, string $resource = null, $exclude = null)
    {
        if ('\\' !== @$namespace[-1]) {
            throw new \InvalidArgumentException(\sprintf('Namespace prefix must end with a "\\": "%s".', $namespace));
        }

        if (!\preg_match('/^(?:[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*+\\\\)++$/', $namespace)) {
            throw new \InvalidArgumentException(\sprintf('Namespace is not a valid PSR-4 prefix: "%s".', $namespace));
        }

        if (null !== $resource) {
            $resource = $this->container->parameter($this->directory . $resource);
        }

        $classes = $this->findClasses($namespace, $resource ?? $this->findResourcePath($namespace), (array) $exclude);
        $this->doCreate($definition = new Definition($this->definition = $namespace));

        $this->classes[$namespace] = [$definition, $classes];

        return $this;
    }

    /**
     * Set|Replace a directory for finding classes.
     *
     * @return $this
     */
    public function directory(string $path)
    {
        $this->directory = \rtrim($path, '\\/') . '/';

        return $this;
    }

    public function getContainer(): AbstractContainer
    {
        return $this->container;
    }

    private function doCreate(object $definition): void
    {
        $this->trackDefaults = false;

        foreach ($this->defaults as $offset => $defaultMethods) {
            if ('#defaults' !== $offset) {
                $class = $definition instanceof DefinitionInterface ? $definition->getEntity() : $definition;

                if (!(\is_string($class) || \is_object($class)) || !\is_subclass_of($class, $offset)) {
                    continue;
                }
            }

            foreach ($defaultMethods as [$defaultMethod, $defaultArguments]) {
                try {
                    $definition->{$defaultMethod}(...$defaultArguments);
                } catch (\Error $e) {
                    throw $this->createErrorException($defaultMethod, $e);
                }
            }
        }

        $this->__destruct();
    }

    private function findResourcePath(string $namespace): string
    {
        foreach (\spl_autoload_functions() as $classLoader) {
            if (!\is_array($classLoader)) {
                continue;
            }

            if ($classLoader[0] instanceof \Composer\Autoload\ClassLoader) {
                $psr4Prefixes = $classLoader[0]->getPrefixesPsr4();

                foreach ($psr4Prefixes as $prefix => $paths) {
                    if (!\str_starts_with($namespace, $prefix)) {
                        continue;
                    }

                    foreach ($paths as $path) {
                        $namespacePostfix = '/' . \substr($namespace, \strlen($prefix));

                        if (\file_exists($path = $path . $namespacePostfix)) {
                            $this->directory = \dirname($path) . '/';

                            return $path;
                        }
                    }
                }

                break;
            }
        }

        throw new \RuntimeException('PSR-4 autoloader file can not be found!');
    }

    /**
     * @param array<int,string> $excludePatterns
     *
     * @return array<int,string>
     */
    private function findClasses(string $namespace, string $pattern, array $excludePatterns): array
    {
        $classNames = [];
        $container = $this->container;

        foreach (\glob($pattern, \GLOB_BRACE) as $path) {
            $directoryIterator = new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS);
            $files = \iterator_to_array(new \RecursiveIteratorIterator($directoryIterator));

            \uksort($files, 'strnatcmp');

            /** @var \SplFileInfo $info */
            foreach ($files as $path => $info) {
                $path = \str_replace('\\', '/', $path); // normalize Windows slashes
                $pathLength = 0;

                foreach ($excludePatterns as $excludePattern) {
                    $excludePattern = $container->parameter($this->directory . $excludePattern);

                    foreach (\glob($excludePattern, \GLOB_BRACE) ?: [$excludePattern] as $excludedPath) {
                        if (\str_starts_with($path, \str_replace('\\', '/', $excludedPath))) {
                            continue 3;
                        }
                    }
                }

                if (!\preg_match('/\\.php$/', $path, $m) || !$info->isReadable()) {
                    continue;
                }

                foreach (\explode('\\', $namespace, -1) as $namespaced) {
                    if ($pos = \strpos($path, $namespaced . '/')) {
                        $pathLength = +$pos + \strlen($namespaced . '/');
                    }
                }

                if (0 === $pathLength) {
                    $pathLength = \preg_match('/\w+\.php$/', $path, $l) ? \strpos($path, $l[0]) : 0;
                }

                $class = \str_replace('/', '\\', \substr($path, $pathLength, -\strlen($m[0])));

                if (null === $class = $this->findClass($container, $namespace . $class, $path, $pattern)) {
                    continue;
                }

                $classNames[] = $class;
            }

            // track only for new & removed files
            if ($container instanceof ContainerBuilder && \interface_exists(ResourceInterface::class)) {
                $container->addResource(new FileExistenceResource($path));
            }
        }

        return $classNames;
    }

    private function findClass(AbstractContainer $container, string $class, string $path, string $pattern): ?string
    {
        if (!\preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*+(?:\\\\[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*+)*+$/', $class)) {
            return null;
        }

        try {
            $r = new \ReflectionClass($class);
        } catch (\Error | \ReflectionException $e) {
            if (\preg_match('/^Class .* not found$/', $e->getMessage())) {
                return null;
            }

            if ($e instanceof \ReflectionException) {
                throw new \InvalidArgumentException(\sprintf('Expected to find class "%s" in file "%s" while importing services from resource "%s", but it was not found! Check the namespace prefix used with the resource.', $class, $path, $pattern), 0, $e);
            }

            throw $e;
        }

        if ($container instanceof ContainerBuilder && \interface_exists(ResourceInterface::class)) {
            $container->addResource(new ClassExistenceResource($class, false));
            $container->addResource(new FileExistenceResource($rPath = $r->getFileName()));
            $container->addResource(new FileResource($rPath));
        }

        if ($r->isInstantiable()) {
            return $class;
        }

        return null;
    }

    private function createErrorException(string $name, \Throwable $e): \BadMethodCallException
    {
        if (!\str_starts_with($e->getMessage(), 'Call to undefined method')) {
            throw $e;
        }

        $hint = ObjectHelpers::getSuggestion(\array_merge(
            \get_class_methods($this),
            \get_class_methods(Definition::class),
        ), $name);

        return new \BadMethodCallException(\sprintf('Call to undefined method %s::%s()' . ($hint ? ", did you mean $hint()?" : '.'), __CLASS__, $name), 0, $e);
    }

    private function createInitializingError(string $name): \LogicException
    {
        return new \LogicException(\sprintf('Did you forgot to register a service via "set", "autowire", or "namespaced" methods\' before calling %s::%s().', __CLASS__, $name));
    }
}
