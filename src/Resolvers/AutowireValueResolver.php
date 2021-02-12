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

class AutowireValueResolver implements ArgumentValueResolverInterface
{
    use SmartObject;

    /** a unique identifier for not found parameter value */
    private const NONE = '\/\/:oxo:\/\/';

    /** @var array<string,mixed[]> type => level => services */
    protected $wiring = [];

    /** @var string[] of classes excluded from autowiring */
    private $excluded = [
        \ArrayAccess::class,
        \Countable::class,
        \IteratorAggregate::class,
        \SplDoublyLinkedList::class,
        \stdClass::class,
        \SplStack::class,
        \Iterator::class,
        \Traversable::class,
        \Serializable::class,
        \JsonSerializable::class,
    ];

    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * {@inheritdoc}
     */
    public function resolve(\ReflectionParameter $parameter, array $providedParameters)
    {
        $paramName = $parameter->name;

        if (!$parameter->isVariadic() && \array_key_exists($paramName, $providedParameters)) {
            $value = $providedParameters[$paramName];
            unset($providedParameters[$paramName]);

            return $value;
        }

        return $this->autowireArgument($parameter, function ($type, bool $single) {
            if ($single) {
                return $this->getByType($type);
            }

            return \array_map(
                [$this->container, 'offsetGet'],
                \array_merge($this->wiring[$type][0] ?? [], $this->wiring[$type][1] ?? [])
            );
        });
    }

    /**
     * Resolve wiring classes + interfaces.
     *
     * @param string $id
     * @param mixed  $type
     */
    public function autowire(string $id, $type): void
    {
        if (null === $type || !$this->isValidType($type)) {
            return;
        }

        $excludedTypes = array_fill_keys($this->excluded, true);

        foreach (\class_parents($type) + \class_implements($type) + [$type] as $parent) {
            if (isset($excludedTypes[$parent])) {
                continue;
            }

            $this->wiring[$parent] = \array_merge(\array_filter([$this->findByType($parent), [$id]]));
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
     * @param callable $getter
     *
     * @throws ContainerResolutionException
     *
     * @return mixed
     */
    private function autowireArgument(\ReflectionParameter $parameter, callable $getter)
    {
        /** @var null|\ReflectionNamedType $type */
        $type = $parameter->getType();
        $desc = Reflection::toString($parameter);

        if (null !== $type && !$type->isBuiltin()) {
            $typeName = $type->getName();

            try {
                $res = $getter($typeName, true);
            } catch (NotFoundServiceException $e) {
                $res = null;
            } catch (ContainerResolutionException $e) {
                if (self::NONE !== $res = $this->findByMethod($parameter, true, $getter, true)) {
                    return $res;
                }

                throw new ContainerResolutionException("{$e->getMessage()} (needed by $desc)", 0, $e);
            }

            return $this->getNullable($parameter, $typeName, $res, $desc);
        }

        if (null !== $type) {
            $result = $this->findByMethod($parameter, $type->getName() === 'array', $getter);

            if (self::NONE !== $result) {
                return $result;
            }
        } elseif (self::NONE !== $default = $this->getDefaultValue($parameter)) {
            return $default;
        }

        throw new ContainerResolutionException(
            "Parameter $desc has no class type hint or default value, so its value must be specified."
        );
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
     *
     * @return mixed
     */
    private function getByType(string $type)
    {
        if (!empty($this->wiring[$type][0])) {
            $autowired = \array_merge(...$this->wiring[$type]);

            if (\count($names = $autowired) === 1) {
                return $this->container->offsetGet($names[0]);
            }

            \natsort($names);

            throw new ContainerResolutionException(
                "Multiple services of type $type found: " . \implode(', ', $names) . '.'
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
        if ($res !== null || $parameter->allowsNull()) {
            return null === $res ? DefaultValueResolver::class : $res;
        }

        if ($this->isValidType($type)) {
            throw new ContainerResolutionException("Service of type $type needed by $desc not found.");
        }

        throw new ContainerResolutionException(
            "Class $type needed by $desc not found. Check type hint and 'use' statements."
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
}
