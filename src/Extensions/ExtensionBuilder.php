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

namespace Rade\DI\Extensions;

use Rade\DI\{AbstractContainer, ContainerBuilder};
use Rade\DI\Exceptions\MissingPackageException;
use Symfony\Component\Config\Builder\{ConfigBuilderGenerator, ConfigBuilderGeneratorInterface};
use Symfony\Component\Config\Definition\{ConfigurationInterface, Processor};
use Symfony\Component\Config\Resource\{ClassExistenceResource, FileExistenceResource, FileResource};

/**
 * Provides ability to load container extensions.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class ExtensionBuilder
{
    protected AbstractContainer $container;

    /** @var array<string,mixed> */
    private array $configuration;

    private ?ConfigBuilderGeneratorInterface $configBuilder = null;

    /** @var array<string,string> */
    private array $aliases = [];

    /** @var array<string,ExtensionInterface|null> */
    private array $extensions = [];

    /**
     * @param array<string,mixed> $config the default configuration for all extensions
     */
    public function __construct(AbstractContainer $container, array $config = [])
    {
        if (\array_key_exists('parameters', $config)) {
            $this->container->parameters += $config['parameters'];
            unset($config['parameters']);
        }

        $this->container = $container;
        $this->configuration = $config;
    }

    /**
     * Enable Generating ConfigBuilders to help create valid config.
     */
    public function setConfigBuilderGenerator(string $outputDir): void
    {
        $this->configBuilder = new ConfigBuilderGenerator($outputDir);
    }

    /**
     * Get a registered extension instance for extending purpose or etc.
     */
    public function get(string $extensionName): ?ExtensionInterface
    {
        return $this->extensions[$this->aliases[$extensionName] ?? $extensionName] ?? null;
    }

    /**
     * Checks if extension exists.
     */
    public function has(string $extensionName): bool
    {
        return \array_key_exists($this->aliases[$extensionName] ?? $extensionName, $this->extensions);
    }

    /**
     * Get all loaded extensions.
     *
     * @return array<string,ExtensionInterface>
     */
    public function getExtensions(): array
    {
        return $this->extensions;
    }

    /**
     * Get all extensions configuration.
     *
     * @return array<string,mixed>
     */
    public function getConfig(string $extensionName = null, string $parent = null, string &$key = null)
    {
        $configuration = $this->configuration;

        if (null === $extensionName) {
            return $configuration;
        }

        if (!(\array_key_exists($extensionName, $this->extensions) || \array_key_exists($extensionName, $this->aliases))) {
            throw new \InvalidArgumentException(\sprintf('The extension name provided in not valid, must be an extension\'s class name or alias.', $extensionName));
        }

        if (isset($parent, $configuration[$parent])) {
            $configuration = $configuration[$parent];
        }

        if (isset($configuration[$extensionName])) {
            $config = $configuration[$key = $extensionName];
        } elseif (isset($this->aliases[$extensionName], $configuration[$this->aliases[$extensionName]])) {
            $config = $configuration[$key = $this->aliases[$extensionName]];
        } elseif ($searchedId = \array_search($extensionName, $this->aliases, true)) {
            $config = $configuration[$key = $searchedId] ?? [];
        }

        return $config ?? [];
    }

    /**
     * Modify the default configuration for an extension.
     *
     * @param array<string,mixed> $configuration
     * @param bool $replace If true, integer keys values will be replaceable
     */
    public function modifyConfig(string $extensionName, array $configuration, string $parent = null, bool $replace = false): void
    {
        if (!\array_key_exists($extensionName, $this->extensions)) {
            throw new \InvalidArgumentException(\sprintf('The extension name provided in not valid, must be an extension\'s class name.', $extensionName));
        }

        $defaults = $this->getConfig($extensionName, $parent, $extensionKey);

        if (!empty($defaults)) {
            $values = $this->mergeConfig($defaults, $configuration, $replace);

            if (isset($parent, $extensionKey, $this->configuration[$parent][$extensionKey])) {
                $this->configuration[$parent][$extensionKey] = $values;
            } else {
                $this->configuration[$extensionKey] = $values;
            }
        } else {
            $this->configuration[$extensionName] = $configuration;
        }
    }

    /**
     * Loads a set of container extensions.
     *
     * You can map an extension class name to a priority index else if
     * declared as an array with arguments the third index is termed as priority value.
     *
     * Example:
     * [
     *    PhpExtension::class,
     *    CoreExtension::class => -1,
     *    [ProjectExtension::class, ['%project.dir%']],
     * ]
     *
     * @param array<int,mixed> $extensions
     */
    public function load(array $extensions): void
    {
        $this->container->runScope([ExtensionInterface::BUILDER => $this], function () use ($extensions): void {
            /** @var array<int,BootExtensionInterface> */
            $afterLoading = [];

            $this->bootExtensions($extensions, $afterLoading);

            foreach (\array_reverse($afterLoading) as $bootable) {
                $bootable->boot($this->container);
            }
        });
    }

    /**
     * Set alias if exist and return config if exists too.
     *
     * @return array<int|string,mixed>
     */
    protected function process(ExtensionInterface $extension, string $extraKey = null): array
    {
        $configuration = $this->configuration;
        $id = \get_class($extension);

        if ($extension instanceof AliasedInterface) {
            $aliasedId = $extension->getAlias();

            if (isset($this->aliases[$aliasedId])) {
                throw new \RuntimeException(\sprintf('The aliased id "%s" for %s extension class must be unqiue.', $aliasedId, \get_class($extension)));
            }

            $this->aliases[$aliasedId] = $id;
        }

        if (isset($extraKey, $configuration[$extraKey])) {
            $configuration = $configuration[$parent = $extraKey];
        }

        if (isset($aliasedId, $configuration[$aliasedId]) || \array_key_exists($id, $configuration)) {
            $configuration = $configuration[$aliasedId ?? ($e = $id)] ?? [];
        } else {
            $configuration = [];
        }

        if ($extension instanceof ConfigurationInterface) {
            if (null !== $this->configBuilder && \is_string($configuration)) {
                $configLoader = $this->configBuilder->build($extension)();

                if (\file_exists($configuration = $this->container->parameter($configuration))) {
                    (include $configuration)($configLoader);
                }

                $configuration = $configLoader->toArray();
            } else {
                $treeBuilder = $extension->getConfigTreeBuilder()->buildTree();
                $configuration = (new Processor())->process($treeBuilder, [$treeBuilder->getName() => $configuration]);
            }
        }

        if (isset($parent)) {
            unset($this->configuration[$parent][$aliasedId ?? $e ?? $id]);

            return $this->configuration[$parent][$id] = $configuration;
        }
        unset($this->configuration[$aliasedId ?? $e ?? $id]);

        return $this->configuration[$id] = $configuration;
    }

    /**
     * Resolve extensions and register them.
     *
     * @param mixed[]                           $extensions
     * @param array<int,BootExtensionInterface> $afterLoading
     */
    private function bootExtensions(array $extensions, array &$afterLoading, string $extraKey = null): void
    {
        $container = $this->container;

        foreach ($this->sortExtensions($extensions) as $resolved) {
            if ($resolved instanceof DebugExtensionInterface && $resolved->inDevelopment() !== $container->parameters['debug']) {
                continue;
            }

            if ($container instanceof ContainerBuilder) {
                $container->addResource(new ClassExistenceResource(($ref = new \ReflectionClass($resolved))->getName(), false));
                $container->addResource(new FileExistenceResource($rPath = $ref->getFileName()));
                $container->addResource(new FileResource($rPath));
            }

            if ($resolved instanceof RequiredPackagesInterface) {
                $this->ensureRequiredPackagesAvailable($resolved);
            }

            $this->extensions[\get_class($resolved)] = $resolved; // Add to stack before registering it ...

            if ($resolved instanceof DependenciesInterface) {
                $this->bootExtensions($resolved->dependencies(), $afterLoading, \method_exists($resolved, 'dependOnConfigKey') ? $resolved->dependOnConfigKey() : $extraKey);
            }

            $resolved->register($container, $this->process($resolved, $extraKey));

            if ($resolved instanceof BootExtensionInterface) {
                $afterLoading[] = $resolved;
            }
        }
    }

    /**
     * Sort extensions by priority.
     *
     * @param mixed[] $extensions container extensions with their priority as key
     *
     * @return array<string,ExtensionInterface>
     */
    private function sortExtensions(array $extensions): array
    {
        if (0 === \count($extensions)) {
            return [];
        }

        $passes = [];

        foreach ($extensions as $offset => $extension) {
            $index = 0;

            if (\is_int($extension)) {
                [$index, $extension] = [$extension, $offset];
            } elseif (\is_array($extension) && isset($extension[2])) {
                $index = $extension[2];
            }
            [$extension, $args] = \is_array($extension) ? $extension : [$extension, []];

            if ($this->container instanceof ContainerBuilder) {
                $resolved = new $extension(\array_map(fn ($v) => \is_string($v) ? $this->container->parameter($v) : $v, $args));
            } else {
                $resolved = $this->container->getResolver()->resolveClass($extension, $args);
            }

            $passes[$index][] = $this->extensions[$extension] = $resolved;
        }
        \krsort($passes);

        return \array_merge(...$passes); // Flatten the array
    }

    private function ensureRequiredPackagesAvailable(RequiredPackagesInterface $extension): void
    {
        $missingPackages = [];

        foreach ($extension->getRequiredPackages() as $requiredClass => $packageName) {
            if (!\class_exists($requiredClass)) {
                $missingPackages[] = $packageName;
            }
        }

        if (!$missingPackages) {
            return;
        }

        throw new MissingPackageException(\sprintf('Missing package%s, to use the "%s" extension, run: composer require %s', \count($missingPackages) > 1 ? 's' : '', \get_class($extension), \implode(' ', $missingPackages)));
    }

    /**
     * Merges $b into $a.
     */
    private function mergeConfig(array $a, array $b, bool $replace): array
    {
        foreach ($b as $k => $v) {
            if (\array_key_exists($k, $a)) {
                if (!\is_array($v)) {
                    $replace || \is_string($k) ? $a[$k] = $v : $a[] = $v;

                    continue;
                }
                $a[$k] = $this->mergeConfig($a[$k], $v, $replace);
            } else {
                $a[$k] = $v;
            }
        }

        return $a;
    }
}
