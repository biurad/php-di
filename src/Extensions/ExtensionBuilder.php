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

use Rade\DI\{Container, ContainerBuilder};
use Rade\DI\Exceptions\MissingPackageException;
use Symfony\Component\Config\Builder\{ConfigBuilderGenerator, ConfigBuilderGeneratorInterface};
use Symfony\Component\Config\Definition\{ConfigurationInterface, Processor};
use Symfony\Component\Config\Resource\{FileExistenceResource, FileResource};

/**
 * Provides ability to load container extensions.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class ExtensionBuilder
{
    protected Container $container;
    private ?ConfigBuilderGeneratorInterface $configBuilder = null;

    /** @var array<string,mixed> */
    private array $configuration;

    /** @var array<string,string> */
    private array $aliases = [];

    /** @var array<string,ExtensionInterface|null> */
    private array $extensions = [];

    /**
     * @param array<string,mixed> $config the default configuration for all extensions
     */
    public function __construct(Container $container, array $config = [])
    {
        if (\array_key_exists('parameters', $config)) {
            $container->parameters += $config['parameters'];
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
     * Get all loaded extension configs.
     *
     * @return array<int|string,mixed>
     */
    public function getConfigs(): array
    {
        return $this->configuration;
    }

    /**
     * Get all extensions configuration.
     *
     * @return array<string,mixed>
     */
    public function getConfig(string $extensionName, string $parent = null)
    {
        $configuration = &$this->configuration;

        if (!(\array_key_exists($extensionName, $this->extensions) || \array_key_exists($extensionName, $this->aliases))) {
            throw new \InvalidArgumentException(\sprintf('The extension "%s" provided in not valid, must be an extension\'s class name or alias.', $extensionName));
        }

        if ($hasParent = isset($parent, $configuration[$parent])) {
            $configuration = &$configuration[$parent];
        }
        $config = &$configuration[$extensionName] ?? [];

        if (false !== ($aliasedId = \array_search($extensionName, $this->aliases)) && isset($configuration[$aliasedId])) {
            $aliased = $configuration[$aliasedId] ?? [];
            $config = \is_array($aliased) ? $this->mergeConfig($config ?? [], $aliased, false) : $aliased;

            if ($hasParent) {
                unset($this->configuration[$parent][$aliasedId]);
            } else {
                unset($this->configuration[$aliasedId]);
            }
        }

        return $config;
    }

    /**
     * Modify the default configuration for an extension.
     *
     * @param array<string,mixed> $configuration
     * @param bool                $replace       If true, integer keys values will be replaceable
     */
    public function modifyConfig(string $extensionName, array $configuration, string $parent = null, bool $replace = false): void
    {
        if (!\array_key_exists($extensionName, $this->extensions)) {
            throw new \InvalidArgumentException(\sprintf('The extension "%s" provided in not valid, must be an extension\'s class name.', $extensionName));
        }

        if (!empty($defaults = $this->getConfig($extensionName, $parent))) {
            $values = $this->mergeConfig($defaults, $configuration, $replace);

            if (isset($parent, $this->configuration[$parent])) {
                $this->configuration[$parent][$extensionName] = $values;
            } else {
                $this->configuration[$extensionName] = $values;
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
        $this->container->runScope([ExtensionInterface::BUILDER], function () use ($extensions): void {
            /** @var \SplStack<int,BootExtensionInterface> */
            $afterLoading = new \SplStack();
            $this->container->set(ExtensionInterface::BUILDER, $this);
            $this->bootExtensions($this->sortExtensions($extensions), $afterLoading);

            foreach ($afterLoading as $bootable) {
                $bootable->boot($this->container);
            }
        });
    }

    /**
     * Resolve extensions and register them.
     *
     * @param mixed[]                           $extensions
     * @param \SplStack<int,BootExtensionInterface> $afterLoading
     */
    private function bootExtensions(array $extensions, \SplStack &$afterLoading, string $extraKey = null): void
    {
        $container = $this->container;

        foreach ($extensions as $resolved) {
            [$resolved, $dependencies] = \is_array($resolved) ? $resolved : [$resolved, null];

            if ($resolved instanceof DebugExtensionInterface && $resolved->inDevelopment() !== $container->parameters['debug']) {
                continue;
            }

            if ($container instanceof ContainerBuilder) {
                $container->addResource(new FileExistenceResource($rPath = (new \ReflectionClass($resolved))->getFileName()));
                $container->addResource(new FileResource($rPath));
            }

            if ($resolved instanceof RequiredPackagesInterface) {
                $this->ensureRequiredPackagesAvailable($resolved);
            }

            if ($dependencies) {
                $this->bootExtensions($dependencies, $afterLoading, \method_exists($resolved, 'dependOnConfigKey') ? $resolved->dependOnConfigKey() : $extraKey);
            }
            $configuration = $this->getConfig($id = \get_class($resolved), $extraKey);

            if ($resolved instanceof ConfigurationInterface) {
                if (null !== $this->configBuilder && (\is_string($configuration) && 0 === \substr_compare($configuration, '%file(', 0, 6))) {
                    $configLoader = $this->configBuilder->build($resolved)();

                    if (\file_exists($configuration = $container->parameter(\substr($configuration, 6, -1)))) {
                        (include $configuration)($configLoader);
                    }
                    $configuration = $configLoader->toArray();

                    if (isset($extraKey, $this->configuration[$extraKey][$id])) {
                        $this->configuration[$extraKey][$id] = $configuration;
                    } else {
                        $this->configuration[$id] = $configuration;
                    }
                } else {
                    $treeBuilder = $resolved->getConfigTreeBuilder()->buildTree();
                    $configuration = (new Processor())->process($treeBuilder, [$treeBuilder->getName() => $configuration]);
                }
            }
            $resolved->register($container, $configuration ?? []);

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
                $resolved = (new \ReflectionClass($extension))->newInstanceArgs(\array_map(fn ($v) => \is_string($v) ? $this->container->parameter($v) : $v, $args));
            } else {
                $resolved = $this->container->getResolver()->resolveClass($extension, $args);
            }

            if ($resolved instanceof AliasedInterface) {
                $aliasedId = $resolved->getAlias();

                if (isset($this->aliases[$aliasedId])) {
                    throw new \RuntimeException(\sprintf('The aliased id "%s" for %s extension class must be unqiue.', $aliasedId, $extension));
                }
                $this->aliases[$aliasedId] = $extension;
            }

            $this->extensions[$extension] = $resolved;
            $passes[$index][] = $resolved instanceof DependenciesInterface ? [$resolved, $this->sortExtensions($resolved->dependencies())] : $resolved;
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
