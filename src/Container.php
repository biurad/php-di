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

use Nette\Utils\Callback;
use Nette\Utils\Reflection;
use Psr\Container\ContainerInterface;
use Rade\DI\{
    Builder\Statement,
    Exceptions\CircularReferenceException,
    Exceptions\FrozenServiceException,
    Exceptions\NotFoundServiceException,
    Exceptions\ContainerResolutionException,
    Services\ServiceProviderInterface
};
use Symfony\Contracts\Service\ResetInterface;

/**
 * Dependency injection container.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class Container extends AbstractContainer implements \ArrayAccess
{
    protected array $types = [
        ContainerInterface::class => ['container'],
        Container::class => ['container'],
    ];

    /** @var array<string,string> internal cached services */
    protected array $methodsMap = ['container' => 'getServiceContainer'];

    /** @var array<string,mixed> service name => instance */
    private array $values = [];

    /** @var array<string,bool> service name => bool */
    private array $frozen = [];

    /** @var array<string,bool> service name => bool */
    private array $keys = [];

    /**
     * Instantiates the container.
     */
    public function __construct()
    {
        parent::__construct();

        // Incase this class it extended ...
        if (__CLASS__ !== static::class) {
            $this->types += [static::class => ['container']];
        }

        $this->resolver = new Resolvers\Resolver($this, $this->types);
    }

    /**
     * Sets a new service to a unique identifier.
     *
     * @param string $offset The unique identifier for the parameter or object
     * @param mixed  $value  The value of the service assign to the $offset
     *
     * @throws FrozenServiceException Prevent override of a frozen service
     */
    public function offsetSet($offset, $value): void
    {
        $this->set($offset, $value, true);
    }

    /**
     * Gets a registered service definition.
     *
     * @param string $offset The unique identifier for the service
     *
     * @throws NotFoundServiceException If the identifier is not defined
     *
     * @return mixed The value of the service
     */
    public function offsetGet($offset)
    {
        return $this->get($offset);
    }

    /**
     * Checks if a service is set.
     *
     * @param string $offset The unique identifier for the service
     */
    public function offsetExists($offset): bool
    {
        return $this->has($offset);
    }

    /**
     * Unset a service by given offset.
     *
     * @param string $offset The unique identifier for service definition
     */
    public function offsetUnset($offset): void
    {
        $this->remove($offset);
    }

    /**
     * This is useful when you want to autowire a callable or class string lazily.
     *
     * @param callable|string $definition A class string or a callable
     */
    public function lazy($definition): Definition
    {
        return $this->definition($definition, Definition::LAZY);
    }

    /**
     * Marks a definition as being a factory service.
     *
     * @param callable|object|string $callable A service definition to be used as a factory
     */
    public function factory($callable): Definition
    {
        return $this->definition($callable, Definition::FACTORY);
    }

    /**
     * Create a definition service.
     *
     * @param Definition|Statement|object|callable|string $service
     * @param int|null                                    $type    of Definition::FACTORY | Definition::LAZY
     */
    public function definition($service, int $type = null): Definition
    {
        $definition = new Definition($service);

        return null === $type ? $definition : $definition->should($type);
    }

    /**
     * Extends an object definition.
     *
     * Useful when you want to extend an existing object definition,
     * without necessarily loading that object.
     *
     * @param string   $id    The unique identifier for the object
     * @param callable $scope A service definition to extend the original
     *
     * @throws NotFoundServiceException   If the identifier is not defined
     * @throws FrozenServiceException     If the service is frozen
     * @throws CircularReferenceException If infinite loop among service is detected
     *
     * @return mixed The wrapped scope or Definition instance
     */
    public function extend(string $id, callable $scope)
    {
        if ($this->frozen[$id] ?? isset($this->methodsMap[$id])) {
            throw new FrozenServiceException($id);
        }

        $extended = $this->values[$id] ?? $this->createNotFound($id, true);

        if ($extended instanceof RawDefinition) {
            return $this->values[$id] = new RawDefinition($scope($extended(), $this));
        }

        if (!$extended instanceof Definition && \is_callable($extended)) {
            $extended = $this->doCreate($id, $extended);
        }

        return $this->values[$id] = $scope($extended, $this);
    }

    /**
     * {@inheritdoc}
     */
    public function keys(): array
    {
        return \array_keys($this->keys + $this->methodsMap);
    }

    /**
     * {@inheritdoc}
     */
    public function reset(): void
    {
        parent::reset();

        foreach ($this->values as $id => $service) {
            if (isset(self::$services[$id])) {
                $service = self::$services[$id];
            }

            if ($service instanceof ResetInterface) {
                $service->reset();
            }

            $this->remove($id);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function remove(string $id): void
    {
        if (isset($this->keys[$id])) {
            unset($this->values[$id], $this->keys[$id], $this->frozen[$id], self::$services[$id]);
        }

        parent::remove($id);
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $id, int $invalidBehavior = /* self::EXCEPTION_ON_MULTIPLE_SERVICE */ 1)
    {
        try {
            return self::$services[$id] ?? $this->providers[$id] ?? $this->{$this->methodsMap[$id] ?? 'getService'}($id, $invalidBehavior);
        } catch (NotFoundServiceException $serviceError) {
            if (\class_exists($id)) {
                try {
                    return $this->resolver->resolveClass($id);
                } catch (ContainerResolutionException $e) {
                    // Only resolves class string and not throw it's error.
                }
            }

            if (isset($this->aliases[$id])) {
                return $this->get($this->aliases[$id]);
            }

            throw $serviceError;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $id): bool
    {
        return $this->keys[$id] ?? isset($this->methodsMap[$id]) ||
            (isset($this->providers[$id]) || isset($this->aliases[$id]));
    }

    /**
     * Set a service definition.
     *
     * @param Definition|RawDefinition|Statement|\Closure|object $definition
     *
     * @throws FrozenServiceException Prevent override of a frozen service
     *
     * @return Definition|RawDefinition|object|\Closure of Definition, RawService, class object or closure
     */
    public function set(string $id, object $definition, bool $autowire = false)
    {
        if ($this->frozen[$id] ?? isset($this->methodsMap[$id])) {
            throw new FrozenServiceException($id);
        }

        // Incase new service definition exists in aliases.
        unset($this->aliases[$id]);

        if ($definition instanceof Definition) {
            $definition->attach($id, $this->resolver);
            $definition = $autowire ? $definition->autowire() : $definition;
        } elseif ($definition instanceof Statement) {
            if ($autowire) {
                $this->autowireService($id, $definition->value);
            }

            $definition = fn () => $this->resolver->resolve($definition->value, $definition->args);
        } elseif ($autowire && !$definition instanceof RawDefinition) {
            $this->autowireService($id, $definition);
        }

        $this->keys[$id] = true;

        return $this->values[$id] = $definition;
    }

    /**
     * @internal
     *
     * Get the mapped service container instance
     */
    protected function getServiceContainer(): self
    {
        return self::$services['container'] = $this;
    }

    /**
     * Build an entry of the container by its name.
     *
     * @throws CircularReferenceException|NotFoundServiceException
     *
     * @return mixed
     */
    protected function getService(string $id, int $invalidBehavior)
    {
        if ($this->resolver->has($id)) {
            return $this->resolver->get($id, self::EXCEPTION_ON_MULTIPLE_SERVICE === $invalidBehavior);
        }

        if (!\is_callable($definition = $this->values[$id] ?? $this->createNotFound($id, true))) {
            $this->frozen[$id] = true;

            return self::$services[$id] = $definition; // If definition is frozen, cache it ...
        }

        if ($definition instanceof Definition) {
            if ($definition->is(Definition::PRIVATE)) {
                throw new ContainerResolutionException(\sprintf('Using service definition for \'%s\' as private is not supported.', $id));
            }

            if ($definition->is(Definition::FACTORY)) {
                return $this->doCreate($id, $definition);
            }
        }

        return $this->values[$id] = self::$services[$id] = $this->doCreate($id, $definition, true);
    }

    /**
     * {@inheritdoc}
     */
    protected function doCreate(string $id, $service, bool $freeze = false)
    {
        // Checking if circular reference exists ...
        if (isset($this->loading[$id])) {
            throw new CircularReferenceException($id, [...\array_keys($this->loading), $id]);
        }
        $this->loading[$id] = true;

        try {
            return $this->resolver->resolve($service);
        } finally {
            unset($this->loading[$id]);

            if ($freeze) {
                $this->frozen[$id] = true; // Freeze resolved service ...
            }
        }
    }

    /**
     * @param mixed $definition
     */
    private function autowireService(string $id, $definition): void
    {
        static $types = [];

        if (\is_callable($definition)) {
            $types = Reflection::getReturnTypes(Callback::toReflection($definition));
        } elseif (\is_object($definition) && !$definition instanceof \stdClass) {
            $types = [\get_class($definition)];
        } elseif (\is_string($definition) && \class_exists($definition)) {
            $types = [$definition];
        }

        // Resolving wiring so we could call the service parent classes and interfaces.
        $this->resolver->autowire($id, $types);
    }
}
