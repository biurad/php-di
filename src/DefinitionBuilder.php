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

use Rade\DI\Definitions\DefinitionInterface;
use Symfony\Component\Config\Resource\{ClassExistenceResource, FileExistenceResource, FileResource};

/**
 * A builder specialized in creating homogeneous service definitions.
 *
 * This class has some performance impact and recommended to be used with ContainerBuilder class.
 *
 * @experimental in 1.0
 *
 * @method self|Definition autowire(string $id, DefinitionInterface|string|object|null $definition = null)
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
final class DefinitionBuilder
{
    private AbstractContainer $container;

    private array $classes = [];

    private array $defaults = [];

    private ?string $definition = null;

    private ?string $directory = null;

    private bool $trackDefaults = false;

    private bool $trackClasses = false;

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
        $id = $this->definition;

        if ('autowire' === $name) {
            if (empty($arguments) || 1 === \count($arguments)) {
                $id && $this->container->definition($id)->autowire($arguments[0] ?? []);
            } else {
                $this->doCreate($this->container->autowire($this->definition = $arguments[0], $arguments[1] ?? null));
            }
        } elseif ($this->trackDefaults) {
            $this->defaults[$name][] = $arguments;
        } elseif (null !== $id) {
            (!$this->trackClasses ? $this->container->definition($id) : $this->classes[$id][0])->{$name}(...$arguments);
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
    public function alias(string $id, string $serviceId)
    {
        $this->container->alias($id, $serviceId);

        return $this;
    }

    /**
     * Set a service definition.
     *
     * @param DefinitionInterface|string|object|null $definition
     *
     * @return Definition|$this
     */
    public function set(string $id, $definition = null)
    {
        $this->doCreate($this->container->set($this->definition = $id, $definition));

        return $this;
    }

    /**
     * Extends a service definition.
     *
     * @return Definition|$this
     */
    public function extend(string $id, callable $scope = null)
    {
        $this->doCreate($this->container->extend($this->definition = $id, $scope));

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
        $this->defaults = $merge ? $this->defaults ?? [] : [];
        $this->trackDefaults = true;

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
        $this->doCreate($definition = new Definition($this->definition = $namespace), false);

        $this->classes[$namespace] = [$definition, $classes];
        $this->trackClasses = true;

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

    /**
     * Undocumented function.
     */
    private function doCreate(DefinitionInterface $definition, bool $destruct = true): void
    {
        $this->trackDefaults = $this->trackClasses = false;

        foreach ($this->defaults as $defaultMethod => $defaultArguments) {
            foreach ($defaultArguments as $default) {
                $definition->{$defaultMethod}(...$default);
            }
        }

        $destruct && $this->__destruct();
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
            $pathLength = \strlen($this->directory ?? $path);

            $directoryIterator = new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS);
            $files = \iterator_to_array(new \RecursiveIteratorIterator($directoryIterator));

            \uksort($files, 'strnatcmp');

            foreach ($files as $path => $info) {
                $path = \str_replace('\\', '/', $path); // normalize Windows slashes

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

                $class = \str_replace('/', '\\', \substr($path, $pathLength, -\strlen($m[0])));
                $class = $namespace . \ltrim(\str_replace(\explode('\\', $namespace), '', $class), '\\.');

                if (!\preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*+(?:\\\\[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*+)*+$/', $class)) {
                    continue;
                }

                try {
                    $r = new \ReflectionClass($class);
                } catch (\Error | \ReflectionException $e) {
                    if (\preg_match('/^Class .* not found$/', $e->getMessage())) {
                        continue;
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
                    $classNames[] = $class;
                }
            }

            // track only for new & removed files
            if ($container instanceof ContainerBuilder && \interface_exists(ResourceInterface::class)) {
                $container->addResource(new FileExistenceResource($path));
            }
        }

        return $classNames;
    }
}
