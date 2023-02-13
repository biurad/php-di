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

use Rade\DI\{Definition, Definitions, Extensions, Services};
use Rade\DI\Exceptions\{ContainerResolutionException, NotFoundServiceException};
use Rade\DI\Resolver;
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
    protected array $types = [];

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
        Definitions\Reference::class => true,
        Definitions\Statement::class => true,
        Definition::class => true,
    ];

    /**
     * Remove an aliased type set to service(s).
     */
    public function removeType(string $type, string $serviceId = null): void
    {
        if (null !== $serviceId) {
            foreach ($this->types[$type] ?? [] as $offset => $typed) {
                if ($serviceId === $typed) {
                    unset($this->types[$type][$offset]);
                }
            }
        } else {
            unset($this->types[$type]);
        }
    }

    /**
     * Add a PHP type-hint(s) which should be excluded from autowiring.
     */
    public function excludeType(string ...$type): void
    {
        foreach ($type as $exclude) {
            $this->excluded[$exclude] = true;
        }
    }

    /**
     * Set the PHP type-hint(s) for a service.
     *
     * @param string $id      The registered service id
     * @param string ...$type The PHP type-hint(s)
     */
    public function type(string $id, string ...$type): void
    {
        foreach ((array) $type as $typed) {
            if (!\Nette\Utils\Validators::isType($typed)) {
                continue;
            }

            if (!($this->excluded[$typed] ?? \in_array($id, $this->types[$typed] ?? [], true))) {
                $this->types[$typed][] = $id;
            }
        }
    }

    /**
     * Set types for multiple services.
     *
     * @param array<string,array<int,string>> $types The types associated with service definition
     */
    public function types(array $types): void
    {
        foreach ($types as $id => $wiring) {
            if (\is_int($id)) {
                throw new ContainerResolutionException('Service identifier is not defined, integer found.');
            }

            $this->type($id, ...(\is_string($wiring) ? [$wiring] : $wiring));
        }
    }

    /**
     * If class or interface is a autowired typed value.
     *
     * @param string $id of class or interface
     *
     * @return array<int,string>|bool If $ids is true, returns found ids else bool
     */
    public function typed(string $id, bool $ids = false): array|bool
    {
        if (!$this->has($id)) {
            return $ids ? $this->types[$id] ?? [] : !empty($this->types[$id] ?? []);
        }

        $types = [];

        foreach ($this->types as $type => $idx) {
            if (\in_array($id, $idx, true)) {
                if (!$ids) {
                    return true;
                }

                $types[] = $type;
            }
        }

        return $ids ? $types : false;
    }

    /**
     * Get all services type-hints.
     *
     * @return array<string,array<int,string>>
     */
    public function getTypes(): array
    {
        return $this->types;
    }

    /**
     * Alias of container's set method with default autowiring.
     */
    public function autowire(string $id, mixed $definition = null): Definition
    {
        return $this->set($id, $definition)->typed();
    }

    /**
     * If id is a registered service id, return bool else if types is set on id,
     * resolve value and return it.
     *
     * @param string $id A class or an interface name
     *
     * @throws ContainerResolutionException|NotFoundServiceException
     */
    public function autowired(string $id, bool $single = false): mixed
    {
        if (empty($autowired = $this->types[$id] ?? [])) {
            throw new NotFoundServiceException(\sprintf('Typed service "%s" not found. Check class name because it cannot be found.', $id));
        }

        if ($single) {
            if (\count($autowired) > 1) {
                $c = \count($t = $this->types[$id]) <= 3 ? \implode(', ', $t) : \current($t) . ', ...' . \end($t);

                throw new ContainerResolutionException(\sprintf('Multiple typed services %s found: %s.', $id, $c));
            }

            return $this->get(\current($autowired));
        }

        return \array_map(fn (string $id) => $this->get($id), $autowired);
    }

    /**
     * Alias of resolver's resolve method.
     *
     * @param array<int|string,mixed> $args
     */
    public function call(mixed $value, array $args = []): mixed
    {
        return $this->resolver->resolve($value, $args);
    }

    /**
     * The resolver associated with the container.
     */
    public function getResolver(): Resolver
    {
        return $this->resolver;
    }
}
