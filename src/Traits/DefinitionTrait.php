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

use Nette\Utils\{Arrays, Helpers};
use Rade\DI\Definition;
use Rade\DI\Definitions\{ChildDefinition, DecorateDefinition, DefinitionAwareInterface, DefinitionInterface, Statement, TypedDefinitionInterface};
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
    }

    /**
     * {@inheritdoc}
     *
     * @param DefinitionInterface|string|object|null $definition
     *
     * @return Definition or DefinitionInterface, mixed value which maybe object
     */
    public function set(string $id, $definition = null)
    {
        $this->validateDefinition($id);

        if (\array_key_exists($id, $this->aliases)) {
            unset($this->aliases[$id]); // Incase new service definition exists in aliases.
        }

        return $this->definitions[$id] = $this->createDefinition($id, $definition ?? new Definition($id));
    }

    /**
     * Sets multiple definitions at once into the container.
     *
     * @param array<int|string,mixed> $definitions indexed by their ids
     */
    public function multiple(array $definitions): void
    {
        $definitions = Arrays::normalize($definitions);

        foreach ($definitions as $id => $definition) {
            $this->set($id, $definition);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return Definition or DefinitionInterface, mixed value which maybe object
     */
    public function extend(string $id, callable $scope = null)
    {
        $this->validateDefinition($id);

        if (null === $definition = $this->definitions[$id] ?? null) {
            throw $this->createNotFound($id);
        }

        if (!$definition instanceof DefinitionInterface) {
            $definition = $scope($definition, $this);
        }

        return $this->definitions[$id] = $definition;
    }

    /**
     * Decorate a list of definitions belonging to a type or tag.
     */
	public function decorate(string $typeOrTag): DecorateDefinition
	{
        $definitions = [];

        foreach (\array_keys($this->tagged($typeOrTag)) as $serviceId) {
            $tagDef = $this->definition($serviceId);

            if ($tagDef instanceof Definition) {
                $definitions[] = $tagDef;
            }
        }

		foreach ($this->typed($typeOrTag, true) as $typedId) {
			$typedDef = $this->definition($typedId);

            if ($typedDef instanceof Definition) {
                $definitions[] = $typedDef;
            }
		}

		return new DecorateDefinition($definitions);
	}

    /**
     * Create a valid service definition.
     *
     * @param string|object|DefinitionInterface $definition
     *
     * @return DefinitionInterface|object
     */
    protected function createDefinition(string $id, $definition)
    {
        if ($definition instanceof ChildDefinition) {
            $definition = \serialize($this->definitions[(string) $definition] ?? null);

            if (\strlen($definition) < 5) {
                throw new ServiceCreationException(\sprintf('Constructing service "%s" from a parent definition encountered an error, parent definition seems non-existing.', $id));
            }

            $definition = \unserialize($definition);
        }

        if ($definition instanceof TypedDefinitionInterface) {
            $definition->isTyped() && $this->type($id, $definition->getTypes());
        } elseif ($definition instanceof Statement) {
            $definition = new Definition($definition->getValue(), $definition->getArguments());
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

        return $definition;
    }

    /**
     * Throw a PSR-11 not found exception.
     */
    protected function createNotFound(string $id): NotFoundServiceException
    {
        if (null !== $suggest = Helpers::getSuggestion($this->keys(), $id)) {
            $suggest = " Did you mean: \"$suggest\" ?";
        }

        return new NotFoundServiceException(\sprintf('Identifier "%s" is not defined.' . $suggest, $id));
    }

    /**
     * @param string $id The unique identifier for the service definition
     *
     * @throws FrozenServiceException
     */
    private function validateDefinition(string $id): void
    {
        if (\array_key_exists($id, $this->services)) {
            throw new FrozenServiceException(\sprintf('The "%s" service is already initialized, and cannot be replaced.', $id));
        }

        if (\array_key_exists($id, $this->privates)) {
            throw new FrozenServiceException(\sprintf('The "%s" service is private, and cannot be replaced.', $id));
        }
    }
}
