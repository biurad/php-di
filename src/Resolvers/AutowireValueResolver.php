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

use Nette\Utils\Reflection;
use Rade\DI\Exceptions\{ContainerResolutionException, NotFoundServiceException};
use Symfony\Contracts\Service\{ServiceProviderInterface, ServiceSubscriberInterface};

/**
 * An advanced autowiring used for PSR-11 implementation.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class AutowireValueResolver
{
    /** a unique identifier for not found parameter value */
    private const NONE = '\/\/:oxo:\/\/';

    /**
     * Resolve parameters for service definition.
     *
     * @param array<int|string,mixed> $providedParameters
     *
     * @return mixed
     */
    public function resolve(callable $resolver, \ReflectionParameter $parameter, array $providedParameters)
    {
        $paramName = $parameter->name;
        $position = $parameter->getPosition();

        try {
            return $providedParameters[$position]
                ?? $providedParameters[$paramName]
                ?? $this->autowireArgument($parameter, $resolver, $providedParameters);
        } finally {
            unset($providedParameters[$position], $providedParameters[$paramName]);
        }
    }

    /**
     * Resolves missing argument using autowiring.
     *
     * @param array<int|string,mixed> $providedParameters
     *
     * @throws ContainerResolutionException
     *
     * @return mixed
     */
    private function autowireArgument(\ReflectionParameter $parameter, callable $getter, array $providedParameters)
    {
        $types = Reflection::getParameterTypes($parameter);
        $invalid = [];

        foreach ($types as $typeName) {
            if ('null' === $typeName) {
                continue;
            }

            try {
                return $providedParameters[$typeName] ?? $getter($typeName, !$parameter->isVariadic());
            } catch (NotFoundServiceException $e) {
                $res = null;

                if (\in_array($typeName, ['array', 'iterable'], true)) {
                    $res = $this->findByMethod($parameter, $getter);
                }
            } catch (ContainerResolutionException $e) {
                $res = $this->findByMethod($parameter, $getter);

                if (self::NONE !== $res && 1 === \count($res)) {
                    return \current($res);
                }

                throw new ContainerResolutionException(
                    \sprintf("{$e->getMessage()} (needed by %s)", Reflection::toString($parameter))
                );
            }

            $res = $res ?? $this->resolveNotFoundService($parameter, $getter, $typeName);

            if (self::NONE !== $res) {
                return $res;
            }

            $invalid[] = $typeName;
        }

        return $this->getDefaultValue($parameter, $invalid);
    }

    /**
     * Parses a methods doc comments or return default value.
     *
     * @return string|object[]
     */
    private function findByMethod(\ReflectionParameter $parameter, callable $getter)
    {
        $method = $parameter->getDeclaringFunction();

        if ($method instanceof \ReflectionMethod && null != $class = $method->getDeclaringClass()) {
            \preg_match(
                "#@param[ \\t]+([\\w\\\\]+?)(\\[])?[ \\t]+\\\${$parameter->name}#",
                (string) $method->getDocComment(),
                $matches
            );

            $itemType = isset($matches[1]) ? Reflection::expandClassName($matches[1], $class) : '';

            if ($this->isValidType($itemType)) {
                return $getter($itemType, false);
            }
        }

        return self::NONE;
    }

    /**
     * Resolve services which may or not exist in container.
     *
     * @return mixed
     */
    private function resolveNotFoundService(\ReflectionParameter $parameter, callable $getter, string $type)
    {
        if (ServiceProviderInterface::class === $type && null !== $class = $parameter->getDeclaringClass()) {
            if (!$class->isSubclassOf(ServiceSubscriberInterface::class)) {
                throw new ContainerResolutionException(\sprintf(
                    'Service of type %s needs parent class %s to implement %s.',
                    $type,
                    $class->getName(),
                    ServiceSubscriberInterface::class
                ));
            }

            return $getter($class->getName());
        }

        // Incase a valid class/interface is found or default value ...
        if ($this->isValidType($type) || ($parameter->isDefaultValueAvailable() || $parameter->allowsNull())) {
            return self::NONE;
        }

        $desc = Reflection::toString($parameter);
        $message = "Type '$type' needed by $desc not found. Check type hint and 'use' statements.";

        if (Reflection::isBuiltinType($type)) {
            $message = "Builtin Type '$type' needed by $desc is not supported for autowiring.";
        }

        throw new ContainerResolutionException($message);
    }

    /**
     * Get the parameter's default value else null.
     *
     * @param string[] $invalid
     *
     * @throws \ReflectionException
     *
     * @return mixed
     */
    private function getDefaultValue(\ReflectionParameter $parameter, array $invalid)
    {
        // optional + !defaultAvailable = i.e. Exception::__construct, mysqli::mysqli, ...
        if ($parameter->isOptional() && $parameter->isDefaultValueAvailable()) {
            return \PHP_VERSION_ID < 80000 ? Reflection::getParameterDefaultValue($parameter) : null;
        }

        // Return null if = i.e. doSomething(?$hello, $value) ...
        if ($parameter->allowsNull()) {
            return null;
        }

        $desc = Reflection::toString($parameter);
        $message = "Parameter $desc has no class type hint or default value, so its value must be specified.";

        if (!empty($invalid)) {
            $invalid = \implode('|', $invalid);
            $message = "Parameter $desc typehint(s) '$invalid' not found, and no default value specified.";
        }

        throw new ContainerResolutionException($message);
    }

    /**
     * @param mixed $type
     */
    private function isValidType($type): bool
    {
        return \class_exists($type) || \interface_exists($type);
    }
}
