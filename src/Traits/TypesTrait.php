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

use Nette\Utils\Validators;
use Psr\Container\ContainerInterface;
use Rade\DI\{AbstractContainer, Definition, Definitions, Extensions, Services};
use Rade\DI\Definitions\{DefinitionInterface, TypedDefinitionInterface};
use Rade\DI\Exceptions\{ContainerResolutionException, NotFoundServiceException};
use Rade\DI\Resolvers\Resolver;
use Symfony\Contracts\Service\{ResetInterface, ServiceSubscriberInterface};

/**
 * This trait adds autowiring functionality to container.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
trait TypesTrait
{
    protected Resolver $resolver;

    /** @var array<string,string[]> type => services */
    protected array $types = [ContainerInterface::class => [AbstractContainer::SERVICE_CONTAINER]];

    /** @var array<string,bool> of classes excluded from autowiring */
    protected array $excluded = [
        \ArrayAccess::class => true,
        \Countable::class => true,
        \IteratorAggregate::class => true,
        \SplDoublyLinkedList::class => true,
        \stdClass::class => true,
        \SplStack::class => true,
        \Stringable::class => true,
        \Reflector::class => true,
        \Iterator::class => true,
        \Traversable::class => true,
        \RecursiveIterator::class => true,
        \Serializable::class => true,
        \JsonSerializable::class => true,
        ResetInterface::class => true,
        ServiceSubscriberInterface::class => true,
        Extensions\BootExtensionInterface::class => true,
        Extensions\DebugExtensionInterface::class => true,
        Extensions\ExtensionInterface::class => true,
        Services\DependenciesInterface::class => true,
        Services\AliasedInterface::class => true,
        Services\ServiceProviderInterface::class => true,
        Services\ServiceLocator::class => true,
        Definitions\DefinitionInterface::class => true,
        Definitions\TypedDefinitionInterface::class => true,
        Definitions\ShareableDefinitionInterface::class => true,
        Definitions\DepreciableDefinitionInterface::class => true,
        Definitions\DefinitionAwareInterface::class => true,
        Definitions\ChildDefinition::class => true,
        Definitions\ValueDefinition::class => true,
        Definitions\Reference::class => true,
        Definitions\Statement::class => true,
        Definition::class => true,
    ];

    /**
     * Remove an aliased type set to service(s).
     */
    final public function removeType(string $type): void
    {
        unset($this->types[$type]);
    }

    /**
     * Add a class or interface that should be excluded from autowiring.
     *
     * @param string ...$types
     */
    public function excludeType(string ...$types): void
    {
        foreach ($types as $type) {
            $this->excluded[$type] = true;
        }
    }

    /**
     * Add a aliased type of classes or interfaces for a service definition.
     *
     * @param string          $id    The registered service id
     * @param string|string[] $types The types associated with service definition
     */
    public function type(string $id, $types): void
    {
        foreach ((array) $types as $typed) {
            if (!Validators::isType($typed)) {
                continue;
            }

            if ($this->excluded[$typed] ?? \in_array($id, $this->types[$typed] ?? [], true)) {
                continue;
            }

            $this->types[$typed][] = $id;
        }
    }

    /**
     * Set types for multiple services.
     *
     * @see type method
     *
     * @param string[] $types The types associated with service definition
     */
    public function types(array $types): void
    {
        foreach ($types as $id => $wiring) {
            if (\is_int($id)) {
                throw new ContainerResolutionException('Service identifier is not defined, integer found.');
            }

            $this->type($id, (array) $wiring);
        }
    }

    /**
     * If class or interface is a autowired typed value.
     *
     * @param string $id of class or interface
     *
     * @return bool|string[] If $ids is true, returns found ids else bool
     */
    public function typed(string $id, bool $ids = false)
    {
        return $ids ? $this->types[$id] ?? [] : \array_key_exists($id, $this->types);
    }

    /**
     * Alias of container's set method with default autowiring.
     *
     * @param DefinitionInterface|object|null $definition
     *
     * @return Definition or DefinitionInterface, mixed value which maybe object
     */
    public function autowire(string $id, object $definition = null): object
    {
        $definition = $this->set($id, $definition);

        if ($this->typed($id)) {
            return $definition;
        }

        if ($definition instanceof TypedDefinitionInterface) {
            return $definition->autowire();
        }

        if ($definition instanceof DefinitionInterface) {
            return $definition;
        }

        $this->type($id, Resolver::autowireService($definition, false, $this));

        return $definition;
    }

    /**
     * If id is a registered service id, return bool else if types is set on id,
     * resolve value and return it.
     *
     * @param string $id A class or an interface name
     *
     * @throws ContainerResolutionException|NotFoundServiceException
     *
     * @return mixed
     */
    public function autowired(string $id, bool $single = false)
    {
        $autowired = $this->types[$id] ?? [];

        if ($single && 1 === \count($autowired)) {
            return $this->services[$autowired[0]] ?? $this->get($autowired[0]);
        }

        if (empty($autowired)) {
            throw new NotFoundServiceException(\sprintf('Service of type "%s" not found. Check class name because it cannot be found.', $id));
        }

        if ($single) {
            \natsort($autowired);
            $autowired = count($autowired) <= 3 ? \implode(', ', $autowired) : $autowired[0] . ', ...' . \end($autowired);

            throw new ContainerResolutionException(\sprintf('Multiple services of type %s found: %s.', $id, $autowired));
        }

        return \array_map(fn (string $id) => $this->services[$id] ?? $this->get($id), $autowired);
    }

    /**
     * The resolver associated with the container.
     */
    public function getResolver(): Resolver
    {
        return $this->resolver;
    }
}
