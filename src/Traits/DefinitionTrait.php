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

namespace Rade\DI\Traits;

use Nette\Utils\Helpers;
use Rade\DI\{ContainerBuilder, Definition, Definitions};
use Rade\DI\Exceptions\{FrozenServiceException, NotFoundServiceException};

/**
 * This trait adds definition's functionality to container.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
trait DefinitionTrait
{
    use AliasTrait;

    /** @var array<string,Definition> */
    protected array $definitions = [];

    /** @var array<string,mixed> */
    protected array $services = [], $privates = [];

    /** @var array<string,string> service name => method name */
    protected array $methodsMap = [];

    /**
     * Gets/Extends a service definition from the container by its id.
     *
     * @param string $id service id relying on this definition
     */
    public function definition(string $id): ?Definition
    {
        return $this->definitions[$this->aliases[$id] ?? $id] ?? null;
    }

    /**
     * Gets all service definitions.
     *
     * @return array<string,Definition>
     */
    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    /**
     * Returns true if the given shared service has actually been initialized.
     *
     * @param string $id The service identifier
     */
    public function shared(string $id): bool
    {
        return \array_key_exists($this->aliases[$id] ?? $id, $this->services);
    }

    /**
     * Remove a registered definition.
     */
    public function removeDefinition(string $id): void
    {
        unset($this->definitions[$id], $this->services[$id]);

        foreach ($this->aliases as $alias => $aliased) {
            if ($id === $aliased) {
                $this->removeAlias($alias);
            }
        }

        foreach ($this->types as $typed => $serviceIds) {
            if (!\in_array($id, $serviceIds, true)) {
                continue;
            }

            foreach ($serviceIds as $offset => $serviceId) {
                if ($id === $serviceId) {
                    unset($this->types[$typed][$offset]);
                }
            }
        }

        foreach ($this->tags as $tag => $attr) {
            if (!isset($attr[$id])) {
                continue;
            }
            unset($this->tags[$tag][$id]);
        }
    }

    /**
     * Removes an service which has been initialized for reinitialization.
     *
     * @param string $id The service identifier
     */
    public function removeShared(string $id): void
    {
        unset($this->services[$id]);
    }

    /**
     * Register a service definition into the container.
     */
    public function set(string $id, mixed $definition = null): Definition
    {
        unset($this->aliases[$id]); // remove alias

        if (null !== ($this->services[$id] ?? $this->privates[$id] ?? $this->methodsMap[$id] ?? null)) {
            throw new FrozenServiceException(\sprintf('The "%s" service is already initialized, and cannot be replaced.', $id));
        }

        if ($definition instanceof Definitions\Reference) {
            $parent = (string) $definition;
            $child = $this->definitions[$this->aliases[$parent] ?? $parent] ?? throw $this->createNotFound($parent);

            if (($definition = clone $child)->hasContainer()) {
                return $this->definitions[$id] = $definition->abstract(false);
            }
        } elseif (!$definition instanceof Definition) {
            $definition = new Definition($definition ?? $id);
        }
        $this->definitions[$id] = $definition;

        return $definition->setContainer($this, $id);
    }

    /**
     * Sets multiple definitions at once into the container.
     *
     * @param array<int|string,mixed> $definitions indexed by their ids
     */
    public function multiple(array $definitions): void
    {
        foreach ($definitions as $id => $definition) {
            [$id, $definition] = \is_int($id) ? [$definition, null] : [$id, $definition];
            $this->set($id, $definition);
        }
    }

    /**
     * Replaces old service with a new one, but keeps a reference
     * of the old one as: service_id.inner.
     *
     * All decorated services under the tag: container.decorated_services
     *
     * @param Definitions|null $definition
     */
    public function decorate(string $id, mixed $definition = null, string $newId = null): Definition
    {
        if (null === $innerDefinition = $this->definitions[$id] ?? null) {
            throw $this->createNotFound($id);
        }

        $this->removeDefinition($id);
        $this->set($i = $id . '.inner', $innerDefinition);

        if (\method_exists($this, 'tag')) {
            $this->tag($i, 'container.decorated_services');
        }

        return $this->set($newId ?? $id, $definition);
    }

    /**
     * Throw a PSR-11 not found exception.
     */
    protected function createNotFound(string $id, \Throwable $e = null): NotFoundServiceException
    {
        if ($this instanceof ContainerBuilder) {
            $suggest = Helpers::getSuggestion(\array_keys($this->definitions), $id);

            if (null !== $suggest) {
                $suggest = " Did you mean: \"$suggest\"?";
            }
        }

        return new NotFoundServiceException(\sprintf('The "%s" requested service is not defined in container.' . $suggest ?? '', $id), 0, $e);
    }
}
