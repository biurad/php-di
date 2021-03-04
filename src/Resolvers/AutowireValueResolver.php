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

namespace Rade\DI\Resolvers;

use DivineNii\Invoker\Interfaces\ArgumentValueResolverInterface;
use Nette\SmartObject;
use Nette\Utils\Reflection;
use Psr\Container\ContainerInterface;
use Rade\DI\Exceptions\ContainerResolutionException;
use Rade\DI\Exceptions\NotFoundServiceException;
use Rade\DI\ServiceLocator;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;
use Symfony\Contracts\Service\ServiceSubscriberInterface;

class AutowireValueResolver implements ArgumentValueResolverInterface
{
    use SmartObject;

    /** a unique identifier for not found parameter value */
    private const NONE = '\/\/:oxo:\/\/';

    /** @var array<string,string[]> type => services */
    protected array $wiring;

    /** @var array<string,bool> of classes excluded from autowiring */
    protected array $excluded = [
        \ArrayAccess::class => true,
        \Countable::class => true,
        \IteratorAggregate::class => true,
        \SplDoublyLinkedList::class => true,
        \stdClass::class => true,
        \SplStack::class => true,
        \Iterator::class => true,
        \Traversable::class => true,
        \Serializable::class => true,
        \JsonSerializable::class => true,
        ServiceLocator::class => true,
        ServiceProviderInterface::class => true,
        ResetInterface::class => true,
    ];

    private ContainerInterface $container;

    public function __construct(ContainerInterface $container, array $wiring = [])
    {
        $this->wiring = $wiring;
        $this->container = $container;
    }

    /**
     * {@inheritdoc}
     */
    public function resolve(\ReflectionParameter $parameter, array $providedParameters)
    {
        $paramName = $parameter->name;
        $position  = $parameter->getPosition();

        try {
            return $providedParameters[$position]
                ?? $providedParameters[$paramName]
                ?? $this->autowireArgument($parameter, [$this, 'getByType']);
        } finally {
            unset($providedParameters[$position], $providedParameters[$paramName]);
        }
    }

    /**
     * Resolve wiring classes + interfaces.
     *
     * @param string   $id
     * @param string[] $types
     */
    public function autowire(string $id, array $types): void
    {
        foreach ($types as $type) {
            if (!$this->isValidType($type)) {
                continue;
            }
            $parents = \class_parents($type) + \class_implements($type) + [$type];

            foreach ($parents as $parent) {
                if (isset($this->excluded[$parent]) && \count($parents) > 1) {
                    continue;
                }

                $this->wiring[$parent][] = $id;
            }
        }
    }

    /**
     * Add a class or interface that should be excluded from autowiring.
     *
     * @param string $type
     */
    public function exclude(string $type): void
    {
        $this->excluded[$type] = true;
    }

    /**
     * Resolves missing argument using autowiring.
     *
     * @param \ReflectionParameter $parameter
     * @param callable             $getter
     *
     * @throws ContainerResolutionException
     *
     * @return mixed
     */
    private function autowireArgument(\ReflectionParameter $parameter, callable $getter)
    {
        $types   = Reflection::getParameterTypes($parameter);
        $desc    = Reflection::toString($parameter);
        $invalid = [];

        foreach ($types as $typeName) {
            try {
                return $getter($typeName, !$parameter->isVariadic());
            } catch (NotFoundServiceException $e) {
                $res = null;
            } catch (ContainerResolutionException $e) {
                $res = $this->findByMethod($parameter, $getter, true);

                if (self::NONE === $res) {
                    throw new ContainerResolutionException("{$e->getMessage()} (needed by $desc)");
                }
            }

            $res = $res ?? $this->resolveNotFoundService($parameter, $typeName, $desc);

            if (self::NONE !== $res) {
                return $res;
            }

            $invalid[] = $typeName;
        }

        if (self::NONE !== $default = $this->getDefaultValue($parameter)) {
            return $default;
        }

        $message = "Parameter $desc has no class type hint or default value, so its value must be specified.";

        if (!empty($invalid)) {
            $invalid = implode('|', $invalid);
            $message = "Parameter $desc typehint(s) '$invalid' not found, and no default value specified.";
        }

        throw new ContainerResolutionException($message);
    }

    /**
     * Parses a methods doc comments or return default value
     *
     * @param \ReflectionParameter $parameter
     * @param callable             $getter
     * @param bool                 $single
     *
     * @return mixed
     */
    private function findByMethod(\ReflectionParameter $parameter, callable $getter, bool $single = false)
    {
        $method = $parameter->getDeclaringFunction();

        if ($method instanceof \ReflectionMethod) {
            \preg_match(
                "#@param[ \\t]+([\\w\\\\]+?)(\\[])?[ \\t]+\\\${$parameter->name}#",
                (string) $method->getDocComment(),
                $matches
            );

            $itemType = isset($matches[1]) ? Reflection::expandClassName($matches[1], $method->getDeclaringClass()) : '';

            if ($this->isValidType($itemType) && !($single && \count($this->wiring[$itemType] ?? []) > 1)) {
                return $getter($itemType, $single);
            }
        }

        return self::NONE;
    }

    /**
     * Resolves service by type.
     *
     * @param string $type
     * @param bool   $single
     *
     * @return mixed
     */
    public function getByType(string $type, bool $single = null)
    {
        if (!empty($this->wiring[$type])) {
            $autowired  = $this->wiring[$type];
            $getService = $this->container instanceof \ArrayAccess ? 'offsetGet' : 'get';

            if (\count($autowired) === 1) {
                return $this->container->{$getService}(reset($autowired));
            }

            if (!$single) {
                return \array_map([$this->container, $getService], $autowired);
            }
            \natsort($autowired);

            throw new ContainerResolutionException("Multiple services of type $type found: " . \implode(', ', $autowired) . '.');
        }

        throw new NotFoundServiceException("Service of type '$type' not found. Check class name because it cannot be found.");
    }

    /**
     * Resolve services which may or not exist in container.
     *
     * @return mixed
     */
    private function resolveNotFoundService(\ReflectionParameter $parameter, string $type, string $desc)
    {
        if ($type === ServiceProviderInterface::class) {
            $class = $parameter->getDeclaringClass();

            if ($class instanceof \ReflectionClass) {
                return $this->autowireServiceSubscriber($class, $type);
            }
        }

        if (\in_array($type, ['array', 'iterable'], true)) {
            return $this->findByMethod($parameter, [$this, 'getByType']);
        }

        // Incase a valid class/interface is found or default value ...
        if ($this->isValidType($type) || ($parameter->isDefaultValueAvailable() || $parameter->allowsNull())) {
            return self::NONE;
        }

        $message = "Type '$type' needed by $desc not found. Check type hint and 'use' statements.";

        if (Reflection::isBuiltinType($type)) {
            $message = "Builtin Type '$type' needed by $desc is not supported for autowiring.";
        }

        throw new ContainerResolutionException($message);
    }

    /**
     * Get the parameter's default value else null
     *
     * @param \ReflectionParameter $parameter
     *
     * @return mixed
     */
    private function getDefaultValue(\ReflectionParameter $parameter)
    {
        $value = self::NONE;

        if ($parameter->isOptional() || $parameter->isDefaultValueAvailable()) {
            // optional + !defaultAvailable = i.e. Exception::__construct, mysqli::mysqli, ...
            $value = $parameter->isDefaultValueAvailable()
                ? Reflection::getParameterDefaultValue($parameter) : null;
        } elseif ($parameter->allowsNull()) {
            $value = null;
        }

        return $value;
    }

    /**
     * @param mixed $type
     *
     * @return bool
     */
    private function isValidType($type): bool
    {
        return \class_exists($type) || \interface_exists($type);
    }

    private function autowireServiceSubscriber(\ReflectionClass $class, string $type): ServiceProviderInterface
    {
        /** @var class-string $className */
        $className = $class->getName();
        $services  = [];

        if (!$class->isSubclassOf(ServiceSubscriberInterface::class)) {
            throw new ContainerResolutionException(sprintf(
                'Service of type %s needs parent class %s to implement %s.',
                $type,
                $class->getName(),
                ServiceSubscriberInterface::class
            ));
        }

        foreach ($className::getSubscribedServices() as $id => $service) {
            $services[\is_int($id) ? $service : $id] = $this->container->get($service);
        }

        return new ServiceLocator($services);
    }
}
