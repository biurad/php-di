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

namespace Rade\DI\Loader;

use Rade\DI\AbstractContainer;
use Rade\DI\Container;
use Rade\DI\ContainerBuilder;
use Rade\DI\Definition;
use Symfony\Component\Config\FileLocatorInterface;
use Symfony\Component\Config\Loader\FileLoader as BaseFileLoader;
use Symfony\Component\Config\Resource\ClassExistenceResource;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Config\Resource\GlobResource;

abstract class FileLoader extends BaseFileLoader
{
    /** @var Container|ContainerBuilder */
    protected AbstractContainer $container;

    /** @var array<string,bool|string|string[]> */
    protected array $autowired = [];

    /** @var array<string,string[]> */
    protected array $deprecations = [];

    /** @var array<string,mixed> */
    protected array $tags = [];

    public function __construct(AbstractContainer $container, FileLocatorInterface $locator)
    {
        $this->container = $container;
        parent::__construct($locator);
    }

    /**
     * Registers a set of classes as services using PSR-4 for discovery.
     *
     * @param Definition           $prototype A definition to use as template
     * @param string               $namespace The namespace prefix of classes in the scanned directory
     * @param string               $resource  The directory to look for classes, glob-patterns allowed
     * @param string|string[]|null $exclude   A globbed path of files to exclude or an array of globbed paths of files to exclude
     */
    public function registerClasses(Definition $prototype, string $namespace, string $resource, $exclude = null): void
    {
        if ('\\' !== @$namespace[-1]) {
            throw new \InvalidArgumentException(\sprintf('Namespace prefix must end with a "\\": "%s".', $namespace));
        }

        if (!\preg_match('/^(?:[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*+\\\\)++$/', $namespace)) {
            throw new \InvalidArgumentException(\sprintf('Namespace is not a valid PSR-4 prefix: "%s".', $namespace));
        }

        $classes = $this->findClasses($namespace, $this->resolveParameters($resource), (array) $exclude);

        // prepare for deep cloning
        $serializedPrototype = \serialize($prototype);

        foreach ($classes as $class) {
            $definition = $this->container->set($class, \unserialize($serializedPrototype))->replace($class, true);

            if ($definition instanceof Definition) {
                if (isset($this->autowired[$namespace])) {
                    $definition->autowire(\is_array($this->autowired[$namespace]) ? $this->autowired[$namespace] : []);
                }

                if (isset($this->deprecations[$namespace])) {
                    [$package, $version, $message] = $this->deprecations[$namespace];

                    $definition->deprecate($package, $version, $message);
                }

                if (null !== $tags = $this->tags[$class] ?? $this->tags[$namespace] ?? null) {
                    $this->container->tag($class, $this->tags[$class] = $tags);
                }
            }
        }
    }

    /**
     * Replaces "%value%" from container's parameters keys.
     */
    protected function resolveParameters(string $value): string
    {
        $res = '';
        $parts = \preg_split('#(%[^%\s]+%)#i', $value, -1, \PREG_SPLIT_DELIM_CAPTURE);

        if (false === $parts || (1 == \count($parts) && $value === $parts[0])) {
            return $value;
        }

        foreach ($parts as $part) {
            if ('' !== $part && '%' === $part[0]) {
                $val = \substr($part, 1, -1);

                if (!isset($this->container->parameters[$val])) {
                    throw new \RuntimeException(\sprintf('You have requested a non-existent parameter "%s".', $val));
                }

                if (!\is_scalar($part = $this->container->parameters[$val])) {
                    throw new \InvalidArgumentException(\sprintf('Unable to concatenate non-scalar parameter "%s" into %s.', $val, $value));
                }
            }

            $res .= (string) $part;
        }

        return '' !== $res ? $res : $value;
    }

    private function findClasses(string $namespace, string $pattern, array $excludePatterns): array
    {
        $excludePaths = [];
        $excludePrefix = null;

        foreach ($excludePatterns as $excludePattern) {
            $excludePattern = $this->resolveParameters($excludePattern);

            foreach ($this->glob($excludePattern, true, $resource, true, true) as $path => $info) {
                if (null === $excludePrefix) {
                    $excludePrefix = $resource->getPrefix();
                }

                // normalize Windows slashes
                $excludePaths[\str_replace('\\', '/', $path)] = true;
            }
        }

        $classes = [];
        $prefixLen = null;

        foreach ($this->glob($pattern, true, $resource, false, false, $excludePaths) as $path => $info) {
            if (null === $prefixLen) {
                $prefixLen = \strlen($resource->getPrefix());

                if ($excludePrefix && 0 !== \strpos($excludePrefix, $resource->getPrefix())) {
                    throw new \InvalidArgumentException(\sprintf('Invalid "exclude" pattern when importing classes for "%s": make sure your "exclude" pattern (%s) is a subset of the "resource" pattern (%s).', $namespace, $excludePattern, $pattern));
                }
            }

            if (isset($excludePaths[\str_replace('\\', '/', $path)]) || !\preg_match('/\\.php$/', $path, $m) || !$info->isReadable()) {
                continue;
            }

            $class = $namespace . \ltrim(\str_replace('/', '\\', \substr($path, $prefixLen, -\strlen($m[0]))), '\\');

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

            if ($this->container instanceof ContainerBuilder) {
                $this->container->addResource(new ClassExistenceResource($class, false));
                $this->container->addResource(new FileExistenceResource($rPath = $r->getFileName()));
                $this->container->addResource(new FileResource($rPath));
            }

            if ($r->isInstantiable()) {
                $classes[] = $class;
            }
        }

        // track only for new & removed files
        if ($this->container instanceof ContainerBuilder) {
            if ($resource instanceof GlobResource) {
                $this->container->addResource($resource);
            } else {
                foreach ($resource as $path) {
                    $this->container->addResource(new FileExistenceResource($path));
                }
            }
        }

        return $classes;
    }
}
