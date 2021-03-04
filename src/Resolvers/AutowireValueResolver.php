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

use DivineNii\Invoker\ArgumentResolver\DefaultValueResolver;
use DivineNii\Invoker\Interfaces\ArgumentValueResolverInterface;
use Nette\SmartObject;
use Nette\Utils\Reflection;
use Rade\DI\Container;
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

    private Container $container;

    public function __construct(Container $container, array $wiring = [])
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

        if (isset($providedParameters[$position])) {
            $providedParameters[$paramName] = $providedParameters[$position];
            unset($providedParameters[$position]);
        }

        if (\array_key_exists($paramName, $providedParameters)) {
            $value = $providedParameters[$paramName];
            unset($providedParameters[$paramName]);

            return $value;
        }

        return $this->autowireArgument($parameter, [$this, 'getByType']);
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
        $this->excluded[] = $type;
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
        $type = $parameter->getType();
        $desc = Reflection::toString($parameter);

        $types   = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
        $invalid = [];

        foreach ($types as $type) {
            if ($type instanceof \ReflectionNamedType) {
                if (\in_array($typeName = $type->getName(), ['array', 'iterable'], true)) {
                    $result = $this->findByMethod($parameter, true, $getter);

                    if (self::NONE !== $result) {
                        return $result;
                    }
                }

                try {
                    $res = $getter($typeName, !$parameter->isVariadic());
                } catch (NotFoundServiceException $e) {
                    $res = null;
                } catch (ContainerResolutionException $e) {
                    $res = $this->findByMethod($parameter, true, $getter, true);

                    if (self::NONE !== $res) {
                        return $res;
                    }

                    throw new ContainerResolutionException("{$e->getMessage()} (needed by $desc)");
                }

                if (!$type->isBuiltin()) {
                    $res = $this->getNullable($parameter, $typeName, $res, $desc);

                    if (self::NONE !== $res) {
                        return $res;
                    }

                    $invalid[] = $typeName;
                }
            }
        }

        if (self::NONE !== $default = $this->getDefaultValue($parameter)) {
            return $default;
        }

        $message = "Parameter $desc has no class type hint or default value, so its value must be specified.";

        if (!empty($invalid)) {
            $invalid = join('|', $invalid);
            $message = "Parameter $desc typehint(s) '$invalid' not found, and no default value specified.";
        }

        throw new ContainerResolutionException($message);
    }

    /**
     * Parses a methods doc comments or return default value
     *
     * @param \ReflectionParameter $parameter
     * @param bool                 $type
     * @param callable             $getter
     * @param bool                 $single
     *
     * @return mixed
     */
    private function findByMethod(\ReflectionParameter $parameter, bool $type, callable $getter, bool $single = false)
    {
        $method = $parameter->getDeclaringFunction();

        \preg_match(
            '#@param[ \t]+([\w\\\\]+?)(\[\])?[ \t]+\$' . $parameter->name . '#',
            (string) $method->getDocComment(),
            $matches
        );

        if (($method instanceof \ReflectionMethod && $type) && isset($matches[1])) {
            $itemType = Reflection::expandClassName($matches[1], $method->getDeclaringClass());

            if ($this->isValidType($itemType)) {
                if ($single && \count($this->findByType($itemType)) > 1) {
                    return $this->findByMethod($parameter, false, $getter);
                }

                return $getter($itemType, $single);
            }
        }

        return $this->getDefaultValue($parameter);
    }

    /**
     * Gets the service names of the specified type.
     *
     * @return string[]
     */
    private function findByType(string $type): array
    {
        if (empty($this->wiring[$type])) {
            return [];
        }

        return \array_merge(...\array_values($this->wiring[$type]));
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
        if (!empty($this->wiring[$type][0])) {
            $autowired = \array_merge(...$this->wiring[$type]);

            if (\count($autowired) === 1) {
                return $this->container->offsetGet($autowired[0]);
            } elseif (!$single) {
                return \array_map([$this->container, 'offsetGet'], $autowired);
            }

            \natsort($autowired);

            throw new ContainerResolutionException(
                "Multiple services of type $type found: " . \implode(', ', $autowired) . '.'
            );
        }

        throw new NotFoundServiceException(
            "Service of type '$type' not found. Check class name because it cannot be found."
        );
    }

    /**
     * If parameter is nullable, return null else value
     *
     * @param mixed $res
     *
     * @return mixed
     */
    private function getNullable(\ReflectionParameter $parameter, string $type, $res, string $desc)
    {
        if (null === $res && $this->isValidType($type)) {
            if ($type === ServiceProviderInterface::class) {
                $class = $parameter->getDeclaringClass();

                if ($class instanceof \ReflectionClass) {
                    return $this->autowireServiceSubscriber($class, $type);
                }
            }

            return self::NONE;
        }

        if ($res !== null || $parameter->allowsNull()) {
            return null === $res ? DefaultValueResolver::class : $res;
        }

        throw new ContainerResolutionException(
            "Class '$type' needed by $desc not found. Check type hint and 'use' statements."
        );
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

        return null === $value ? DefaultValueResolver::class : $value;
    }

    /**
     * @param mixed $type
     *
     * @return bool
     */
    private function isValidType($type): bool
    {
        return \is_string($type) && (\class_exists($type) || \interface_exists($type));
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
