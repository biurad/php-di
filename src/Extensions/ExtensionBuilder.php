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
use Rade\DI\Services\{AliasedInterface, DependenciesInterface};
use Symfony\Component\Config\Builder\{ConfigBuilderGenerator, ConfigBuilderGeneratorInterface};
use Symfony\Component\Config\Definition\{ConfigurationInterface, Processor};
use Symfony\Component\Config\Resource\{ClassExistenceResource, FileExistenceResource, FileResource, ResourceInterface};

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
        return \array_filter($this->extensions);
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
     */
    public function modifyConfig(string $extensionName, array $configuration, string $parent = null): void
    {
        if (!\array_key_exists($extensionName, $this->extensions)) {
            throw new \InvalidArgumentException(\sprintf('The extension name provided in not valid, must be an extension\'s class name.', $extensionName));
        }

        $defaults = $this->getConfig($extensionName, $parent, $extensionKey);

        if (!empty($defaults)) {
            $values = \array_replace_recursive($defaults, $configuration);

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

        if ($extension instanceof AliasedInterface) {
            $aliasedId = $extension->getAlias();

            if (isset($this->aliases[$aliasedId])) {
                throw new \RuntimeException(\sprintf('The aliased id "%s" for %s extension class must be unqiue.', $aliasedId, \get_class($extension)));
            }

            $this->aliases[$aliasedId] = \get_class($extension);
        }

        if (isset($extraKey, $configuration[$extraKey])) {
            $configuration = $configuration[$parent = $extraKey];
        }

        if (isset($aliasedId, $configuration[$aliasedId]) || null !== ($configuration[$id = \get_class($extension)] ?? null)) {
            $configuration = $configuration[$id ?? $aliasedId];
        } else {
            $configuration = [];
        }

        if ($extension instanceof ConfigurationInterface) {
            if (null !== $this->configBuilder && \is_string($configuration)) {
                $configLoader = $this->configBuilder->build($extension)();

                if (\file_exists($configuration = $this->container->parameter($configuration))) {
                    (include $configuration)($configLoader);
                }

                $config = $configLoader->toArray();
            } else {
                $treeBuilder = $extension->getConfigTreeBuilder()->buildTree();
                $config = (new Processor())->process($treeBuilder, [($id = $treeBuilder->getName()) => $configuration]);
            }

            if (isset($parent)) {
                unset($this->configuration[$parent][$aliasedId], $this->configuration[$parent][$id]);

                return $this->configuration[$parent][$id] = $config;
            }

            unset($this->configuration[$aliasedId], $this->configuration[$id]);

            return $this->configuration[$id ?? $aliasedId] = $config;
        }

        return $configuration;
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

        foreach ($this->sortExtensions($extensions) as $extension) {
            [$extension, $args] = \is_array($extension) ? $extension : [$extension, []];

            if (\is_subclass_of($extension, DebugExtensionInterface::class) && $extension::inDevelopment() !== $container->parameters['debug']) {
                continue;
            }

            if ($container instanceof ContainerBuilder) {
                if (\interface_exists(ResourceInterface::class)) {
                    $container->addResource(new ClassExistenceResource($extension, false));
                    $container->addResource(new FileExistenceResource($rPath = ($ref = new \ReflectionClass($extension))->getFileName()));
                    $container->addResource(new FileResource($rPath));
                }

                $ref = $ref ?? new \ReflectionClass($extension);
                $resolved = $ref->newInstanceArgs(\array_map(static fn ($value) => \is_string($value) ? $container->parameter($value) : $value, $args));
            } else {
                $resolved = $container->getResolver()->resolveClass($extension, $args);
            }

            /** @var ExtensionInterface $resolved */
            $this->extensions[$extension] = $resolved; // Add to stack before registering it ...

            if ($resolved instanceof DependenciesInterface) {
                $this->bootExtensions($resolved->dependencies(), $afterLoading, $extraKey = \method_exists($resolved, 'dependOnConfigKey') ? $resolved->dependOnConfigKey() : $extraKey);
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
     * @return array<int,mixed>
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

            $passes[$index][] = $extension;
            $this->extensions[\is_array($extension) ? $extension[0] : $extension] = null;
        }

        \krsort($passes);

        // Flatten the array
        return \array_merge(...$passes);
    }
}
