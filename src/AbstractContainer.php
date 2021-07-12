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

namespace Rade\DI;

use Nette\Utils\Helpers;
use Psr\Container\ContainerInterface;
use Rade\DI\Exceptions\{CircularReferenceException, ContainerResolutionException, NotFoundServiceException};
use Rade\DI\Resolvers\Resolver;
use Rade\DI\Services\ServiceProviderInterface;
use Symfony\Component\Config\Definition\{ConfigurationInterface, Processor};
use Symfony\Contracts\Service\ResetInterface;

/**
 * Internal shared container.
 *
 * @method call($callback, array $args = [])             Resolve a service definition, class string, invocable object or callable using autowiring.
 * @method resolveClass(string $class, array $args = []) Resolves a class string.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
abstract class AbstractContainer implements ContainerInterface, ResetInterface
{
    public const IGNORE_MULTIPLE_SERVICE = 0;

    public const EXCEPTION_ON_MULTIPLE_SERVICE = 1;

    /** @var array<string,mixed> For handling a global config around services */
    public array $parameters = [];

    /** @var array<string,mixed> A list of already loaded services (this act as a local cache) */
    protected static array $services;

    /** @var Services\ServiceProviderInterface[] A list of service providers */
    protected array $providers = [];

    protected Resolvers\Resolver $resolver;

    /** @var array<string,bool> service name => bool */
    protected array $loading = [];

    /** @var string[] alias => service name */
    protected array $aliases = [];

    /** @var array[] tag name => service name => tag value */
    private array $tags = [];

    /** @var array<string,string[]> type => services */
    protected array $types = [ContainerInterface::class => ['container'], AbstractContainer::class => ['container']];

    /** @var array<string,bool> of classes excluded from autowiring */
    private array $excluded = [
        \ArrayAccess::class => true,
        \Countable::class => true,
        \IteratorAggregate::class => true,
        \SplDoublyLinkedList::class => true,
        \stdClass::class => true,
        \SplStack::class => true,
        \Stringable::class => true,
        \Iterator::class => true,
        \Traversable::class => true,
        \Serializable::class => true,
        \JsonSerializable::class => true,
        ServiceProviderInterface::class => true,
        ResetInterface::class => true,
        Services\ServiceLocator::class => true,
        Builder\Reference::class => true,
        Builder\Statement::class => true,
        RawDefinition::class => true,
        Definition::class => true,
    ];

    public function __construct()
    {
        self::$services = [];
        $this->resolver = new Resolver($this);
    }

    /**
     * Container can not be cloned.
     */
    public function __clone()
    {
        throw new \LogicException('Container is not cloneable');
    }

    /**
     * @throws \ReflectionException
     *
     * @return mixed
     */
    public function __call(string $name, array $args)
    {
        switch ($name) {
            case 'resolveClass':
                return $this->resolver->resolveClass($args[0], $args[1] ?? []);

            case 'call':
                return $this->resolver->resolve($args[0], $args[1] ?? []);

            default:
                if (\method_exists($this, $name)) {
                    $message = \sprintf('Method call \'%s()\' is either a member of container or a protected service method.', $name);
                }

                throw new \BadMethodCallException(
                    $message ?? \sprintf('Method call %s->%s() invalid, "%2$s" doesn\'t exist.', __CLASS__, $name)
                );
        }
    }

    /**
     * {@inheritdoc}
     *
     * @param string $id              identifier of the entry to look for
     * @param int    $invalidBehavior The behavior when multiple services returns for $id
     */
    abstract public function get(string $id, int $invalidBehavior = /* self::EXCEPTION_ON_MULTIPLE_SERVICE */ 1);

    /**
     * {@inheritdoc}
     */
    abstract public function has(string $id): bool;

    /**
     * Returns all defined value names.
     *
     * @return string[] An array of value names
     */
    abstract public function keys(): array;

    /**
     * Gets the service definition or aliased entry from the container.
     *
     * @param string $id service id relying on this definition
     *
     * @throws NotFoundServiceException No entry was found for identifier
     *
     * @return Definition|RawDefinition|object
     */
    abstract public function service(string $id);

    /**
     * Returns the registered service provider.
     *
     * @param string $id The class name of the service provider
     */
    final public function provider(string $id): ?ServiceProviderInterface
    {
        return $this->providers[$id] ?? null;
    }

    /**
     * Registers a service provider.
     *
     * @param ServiceProviderInterface $provider A ServiceProviderInterface instance
     * @param array                    $config   An array of config that customizes the provider
     */
    final public function register(ServiceProviderInterface $provider, array $config = []): self
    {
        // If service provider depends on other providers ...
        if ($provider instanceof Services\DependedInterface) {
            foreach ($provider->dependencies() as $name => $dependency) {
                $dependencyProvider = $this->resolver->resolveClass($dependency);

                if ($dependencyProvider instanceof ServiceProviderInterface) {
                    $this->register($dependencyProvider, $config[!\is_numeric($name) ? $name : $dependency] ?? []);
                }
            }
        }

        $this->providers[$providerId = \get_class($provider)] = $provider;

        // Override $providerId if method exists ...
        if (\method_exists($provider, 'getId')) {
            $providerId = $providerId::getId();
        }

        // If symfony's config is present ...
        if ($provider instanceof ConfigurationInterface) {
            $config = (new Processor())->processConfiguration($provider, [$providerId => $config[$providerId] ?? $config]);
        }

        $provider->register($this, $config[$providerId] ?? $config);

        return $this;
    }

    /**
     * Returns true if the given service has actually been initialized.
     *
     * @param string $id The service identifier
     *
     * @return bool true if service has already been initialized, false otherwise
     */
    public function initialized(string $id): bool
    {
        return isset(self::$services[$id]) || (isset($this->aliases[$id]) && $this->initialized($this->aliases[$id]));
    }

    /**
     * Remove an alias, service definition id, or a tagged service.
     */
    public function remove(string $id): void
    {
        unset($this->aliases[$id], $this->tags[$id]);
    }

    /**
     * Resets the container.
     */
    public function reset(): void
    {
        $this->tags = $this->aliases = $this->types = [];
    }

    /**
     * Marks an alias id to service id.
     *
     * @param string $id        The alias id
     * @param string $serviceId The registered service id
     *
     * @throws ContainerResolutionException Service id is not found in container
     */
    public function alias(string $id, string $serviceId): void
    {
        if ($id === $serviceId) {
            throw new \LogicException("[$id] is aliased to itself.");
        }

        if (!$this->has($serviceId)) {
            throw new ContainerResolutionException("Service id '$serviceId' is not found in container");
        }

        $this->aliases[$id] = $this->aliases[$serviceId] ?? $serviceId;
    }

    /**
     * Checks if a service definition has been aliased.
     *
     * @param string $id The registered service id
     */
    public function aliased(string $id): bool
    {
        foreach ($this->aliases as $serviceId) {
            if ($id === $serviceId) {
                return true;
            }
        }

        return false;
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
     * @param array<string,string[]> $types The types associated with service definition
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
        return $ids ? $this->types[$id] ?? [] : isset($this->types[$id]);
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
        if (isset($this->types[$id])) {
            if (1 === \count($autowired = $this->types[$id])) {
                return $single ? $this->get($autowired[0]) : [$this->get($autowired[0])];
            }

            if ($single) {
                \natsort($autowired);

                throw new ContainerResolutionException(\sprintf('Multiple services of type %s found: %s.', $id, \implode(', ', $autowired)));
            }

            return \array_map([$this, 'get'], $autowired);
        }

        throw new NotFoundServiceException("Service of type '$id' not found. Check class name because it cannot be found.");
    }

    /**
     * Add a class or interface that should be excluded from autowiring.
     *
     * @param string|string[] $types
     */
    public function exclude($types): void
    {
        foreach ((array) $types as $type) {
            $this->excluded[$type] = true;
        }
    }

    /**
     * Assign a set of tags to service(s).
     *
     * @param string[]|string         $serviceIds
     * @param array<int|string,mixed> $tags
     */
    public function tag($serviceIds, array $tags): void
    {
        foreach ((array) $serviceIds as $service) {
            foreach ($tags as $tag => $attributes) {
                // Exchange values if $tag is an integer
                if (\is_int($tmp = $tag)) {
                    $tag = $attributes;
                    $attributes = $tmp;
                }

                $this->tags[$service][$tag] = $attributes;
            }
        }
    }

    /**
     * Resolve all of the bindings for a given tag.
     *
     * @return array of [service, attributes]
     */
    public function tagged(string $tag, bool $resolve = true): array
    {
        $tags = [];

        foreach ($this->tags as $service => $tagged) {
            if (isset($tagged[$tag])) {
                $tags[] = [$resolve ? $this->get($service) : $service, $tagged[$tag]];
            }
        }

        return $tags;
    }

    /**
     * The resolver associated with the container.
     */
    public function getResolver(): Resolver
    {
        return $this->resolver;
    }

    /**
     * Marks a definition from being interpreted as a service.
     *
     * @param mixed $definition from being evaluated
     */
    public function raw($definition): RawDefinition
    {
        return new RawDefinition($definition);
    }

    /**
     * @internal prevent service looping
     *
     * @param Definition|RawDefinition|callable $service
     *
     * @throws CircularReferenceException
     *
     * @return mixed
     */
    abstract protected function doCreate(string $id, $service);

    /**
     * Throw a PSR-11 not found exception.
     */
    protected function createNotFound(string $id, bool $throw = false): NotFoundServiceException
    {
        if (null !== $suggest = Helpers::getSuggestion($this->keys(), $id)) {
            $suggest = " Did you mean: \"$suggest\" ?";
        }

        $error = new NotFoundServiceException(\sprintf('Identifier "%s" is not defined.' . $suggest, $id));

        if ($throw) {
            throw $error;
        }

        return $error;
    }
}
