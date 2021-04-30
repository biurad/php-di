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
use Rade\DI\Exceptions\{
    CircularReferenceException, ContainerResolutionException, NotFoundServiceException
};
use Rade\DI\Services\ServiceProviderInterface;
use Symfony\Component\Config\Definition\{ConfigurationInterface, Processor};
use Symfony\Contracts\Service\ResetInterface;

/**
 * Internal shared container.
 *
 * @method call($callback, array $args = [])
 *      Resolve a service definition, class string, invocable object or callable using autowiring.
 * @method resolveClass(string $class, array $args = []) Resolves a class string.
 * @method autowire(string $id, array $types) Resolve wiring classes + interfaces to service id.
 * @method exclude(string $type) Exclude an interface or class type from being autowired.
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

    public function __construct()
    {
        self::$services = [];
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

            case 'autowire':
                if (!$this->has($args[0])) {
                    throw $this->createNotFound($args[0]);
                }

                $this->resolver->autowire($args[0], $args[1] ?? []);

                break;

            case 'exclude':
                $this->resolver->exclude($args[0]);

                break;

            default:
                if (\method_exists($this, $name)) {
                    $message = \sprintf('Method call \'%s()\' is either a member of container or a protected service method.', $name);
                }

                throw new \BadMethodCallException(
                    $message ?? \sprintf('Method call %s->%s() invalid, "%2$s" doesn\'t exist.', __CLASS__, $name)
                );
        }

        return $this;
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
     * Registers a service provider.
     *
     * @param ServiceProviderInterface $provider A ServiceProviderInterface instance
     * @param array                    $config   An array of config that customizes the provider
     */
    final public function register(ServiceProviderInterface $provider, array $config = []): self
    {
        $this->providers[\get_class($provider)] = $provider;

        if ($provider instanceof Config\ConfigurationInterface) {
            $values = isset($config[$provider->getId()]) ? $config : [$provider->getId() => $config];

            if ($provider instanceof ConfigurationInterface) {
                $values = (new Processor())->processConfiguration($provider, $values);
            }

            $provider->setConfiguration($values, $this);
        }

        // If service provider depends on other providers ...
        if ($provider instanceof Services\DependedInterface) {
            foreach ($provider->dependencies() as $dependency) {
                $dependency = $this->resolver->resolveClass($dependency);

                if ($dependency instanceof ServiceProviderInterface) {
                    $this->register($dependency);
                }
            }
        }

        $provider->register($this);

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
        $this->resolver->reset();

        $this->tags = $this->aliases = [];
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
