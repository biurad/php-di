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
use Symfony\Component\Config\Definition\{ConfigurationInterface, Processor};
use Symfony\Component\Config\Resource\{ClassExistenceResource, FileResource, FileExistenceResource, ResourceInterface};

/**
 * Provides ability to load container extensions.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class ExtensionBuilder
{
    protected AbstractContainer $container;

    /** @var \ArrayIterator<string,mixed> */
    private \ArrayIterator $configuration;

    /** @var array<string,string> */
    private array $aliases = [];

    /** @var array<string,ExtensionInterface> */
    private array $extensions = [];

    /**
     * @param array<string,mixed> $config the default configuration for all extensions
     */
    public function __construct(AbstractContainer $container, array $config = [])
    {
        $this->container = $container;
        $this->configuration = new \ArrayIterator($config);
    }

    /**
     * Get a registered extension instance for extending purpose or etc.
     */
    public function get(string $extensionName): ?ExtensionInterface
    {
        return $this->extensions[$this->aliases[$extensionName] ?? $extensionName] ?? null;
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
     * @return \ArrayIterator<string,mixed>
     */
    public function getConfig(): \ArrayIterator
    {
        return $this->configuration;
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

            foreach ($afterLoading as $bootable) {
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
        if ($extension instanceof AliasedInterface) {
            $this->aliases[$aliasedId = $extension->getAlias()] = \get_class($extension);
            $config = $this->configuration[$aliasedId] ?? null;
        }

        $configuration = $config ?? $this->configuration[\get_class($extension)] ?? [];

        if (null !== $extraKey) {
            $configuration = $configuration[$extraKey] ?? []; // Overridden by extra key
        }

        if ($extension instanceof ConfigurationInterface) {
            $treeBuilder = $extension->getConfigTreeBuilder()->buildTree();

            return (new Processor())->process($treeBuilder, [$treeBuilder->getName() => $configuration]);
        }

        return $configuration;
    }

    /**
     * Resolve extensions and register them.
     *
     * @param mixed[] $extensions
     * @param array<int,BootExtensionInterface> $afterLoading
     *
     * @return void
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
                $resolved = $ref->newInstanceArgs(\array_map(static fn ($value) => \is_string($value) && \str_contains($value, '%') ? $container->parameter($value) : $value, $args));
            } else {
                $resolved = $container->getResolver()->resolveClass($extension, $args);
            }

            if ($resolved instanceof DependenciesInterface) {
                $this->bootExtensions($resolved->dependencies(), $afterLoading, \method_exists($resolved, 'dependOnConfigKey') ? $resolved->dependOnConfigKey() : $extraKey);
            }

            $resolved->register($container, $this->process($this->extensions[$extension] = $resolved, $extraKey));

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
        }

        \krsort($passes);

        // Flatten the array
        return \array_merge(...$passes);
    }
}
