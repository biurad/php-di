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
use Rade\DI\{ContainerInterface, Definition, Definitions};
use Rade\DI\Definitions\{DefinitionAwareInterface, DefinitionInterface, TypedDefinitionInterface};
use Rade\DI\Exceptions\{FrozenServiceException, NotFoundServiceException, ServiceCreationException};

/**
 * This trait adds definition's functionality to container.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
trait DefinitionTrait
{
    use AliasTrait;

    /** @var array<string,DefinitionInterface|object> service name => instance */
    protected array $definitions = [];

    /** @var array<string,mixed> A list of already public loaded services (this act as a local cache) */
    protected array $services = [];

    /** @var array<string,mixed> A list of already private loaded services (this act as a local cache) */
    protected array $privates = [];

    /**
     * {@inheritdoc}
     *
     * @return Definition or DefinitionInterface, mixed value which maybe object
     */
    public function definition(string $id)
    {
        return $this->definitions[$this->aliases[$id] ?? $id] ?? null;
    }

    /**
     * Gets all service definitions.
     *
     * @return array<string,DefinitionInterface|object>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * Returns all defined value names.
     *
     * @return string[] An array of value names
     */
    public function keys(): array
    {
        return \array_keys($this->definitions);
    }

    /**
     * {@inheritdoc}
     */
    public function initialized(string $id): bool
    {
        return \array_key_exists($this->aliases[$id] ?? $id, $this->services);
    }

    /**
     * Remove a registered definition.
     */
    public function removeDefinition(string $id): void
    {
        unset($this->definitions[$id], $this->services[$id]);

        if (isset($this->types)) {
            foreach ($this->types as &$serviceIds) {
                foreach ($serviceIds as $offset => $serviceId) {
                    if ($id !== $serviceId) {
                        continue;
                    }

                    unset($serviceIds[$offset]);
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     *
     * @param DefinitionInterface|object|null $definition
     *
     * @return Definition||Definitions\ValueDefinition or DefinitionInterface, mixed value which maybe object
     */
    public function set(string $id, object $definition = null): object
    {
        $this->validateDefinition($id);

        if (\array_key_exists($id, $this->aliases)) {
            unset($this->aliases[$id]); // Incase new service definition exists in aliases.
        }

        if (null === $definition || $definition instanceof Definitions\Statement) {
            ($definition = new Definition($definition ? $definition->getValue() : $id, $definition ? $definition->getArguments() : []))->bindWith($id, $this);

            return $this->definitions[$id] = $definition;
        }

        if ($definition instanceof DefinitionInterface) {
            if ($definition instanceof TypedDefinitionInterface) {
                $definition->isTyped() && $this->type($id, $definition->getTypes());
            }

            if ($definition instanceof DefinitionAwareInterface) {
                /** @var \Rade\DI\Definitions\Traits\DefinitionAwareTrait $definition */
                if ($definition->hasTags()) {
                    foreach ($definition->getTags() as $tag => $value) {
                        $this->tag($id, $tag, $value);
                    }
                }

                $definition->bindWith($id, $this);
            }
        } elseif ($definition instanceof Definitions\ChildDefinition) {
            if (!\array_key_exists($id, $this->definitions)) {
                throw new ServiceCreationException(\sprintf('Constructing service "%s" from a parent definition encountered an error, parent definition seems non-existing.', $id));
            }

            $definition = clone $definition;
        }

        return $this->definitions[$id] = $definition;
    }

    /**
     * Sets multiple definitions at once into the container.
     *
     * @param array<int|string,mixed> $definitions indexed by their ids
     */
    public function multiple(array $definitions): void
    {
        foreach ($definitions as $id => $definition) {
            if (\is_int($id)) {
                [$id, $definition] = [$definition, null];
            }

            $this->set($id, $definition);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return Definition|Definitions\ValueDefinition or DefinitionInterface, mixed value which maybe object
     */
    public function extend(string $id, callable $scope = null)
    {
        $this->validateDefinition($id);

        if (null === $definition = $this->definitions[$id] ?? null) {
            throw $this->createNotFound($id);
        }

        if (null !== $scope) {
            $definition = $scope($definition, $this);
        }

        return $this->definitions[$id] = $definition;
    }

    /**
     * Replaces old service with a new one, but keeps a reference
     * of the old one as: service_id.inner.
     *
     * All decorated services under the tag: container.decorated_services
     *
     * @param DefinitionInterface|object|null $definition
     *
     * @return Definition||Definitions\ValueDefinition or DefinitionInterface, mixed value which maybe object
     */
    public function decorate(string $id, object $definition = null)
    {
        if (null === $innerDefinition = $this->definitions[$id] ?? null) {
            throw $this->createNotFound($id);
        }

        $this->removeDefinition($id);
        $this->set($id . '.inner', $innerDefinition)->tag('container.decorated_services');

        return $this->set($id, $definition);
    }

    /**
     * Throw a PSR-11 not found exception.
     */
    protected function createNotFound(string $id): NotFoundServiceException
    {
        if (null !== $suggest = Helpers::getSuggestion($this->keys(), $id)) {
            $suggest = " Did you mean: \"$suggest\"?";
        }

        return new NotFoundServiceException(\sprintf('The "%s" requested service is not defined in container.' . $suggest, $id));
    }

    /**
     * @param string $id The unique identifier for the service definition
     *
     * @throws FrozenServiceException|ServiceCreationException
     */
    private function validateDefinition(string $id): void
    {
        if (\array_key_exists($id, $this->services)) {
            throw new FrozenServiceException(\sprintf('The "%s" service is already initialized, and cannot be replaced.', $id));
        }

        if (\array_key_exists($id, $this->privates)) {
            throw new FrozenServiceException(\sprintf('The "%s" service is private, and cannot be replaced.', $id));
        }

        if (ContainerInterface::SERVICE_CONTAINER === $id) {
            throw new ServiceCreationException(\sprintf('The "%s" service is reserved, and cannot be set or modified.'));
        }
    }
}
