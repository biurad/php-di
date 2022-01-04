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

use Nette\Utils\{Reflection, Type, Validators};
use Rade\DI\Attribute\Inject;
use Rade\DI\Exceptions\{ContainerResolutionException, NotFoundServiceException};
use Symfony\Contracts\Service\{ServiceProviderInterface, ServiceSubscriberInterface};

/**
 * An advanced autowiring used for PSR-11 implementation.
 *
 * @internal use not be use externally
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class AutowireValueResolver
{
    /**
     * Resolve parameters for service definition.
     *
     * @param array<int|string,mixed> $providedParameters
     *
     * @return mixed
     */
    public static function resolve(callable $resolver, \ReflectionParameter $parameter, array $providedParameters)
    {
        $paramName = $parameter->name;
        $position = $parameter->getPosition();

        try {
            return $providedParameters[$position]
                ?? $providedParameters[$paramName]
                ?? self::autowireArgument($parameter, $resolver, $providedParameters);
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
    private static function autowireArgument(\ReflectionParameter $parameter, callable $getter, array $providedParameters)
    {
        $types = ($t = Type::fromReflection($parameter)) ? $t->getNames() : [];
        $method = $parameter->getDeclaringFunction();

        foreach ($types as $typeName) {
            if (!Reflection::isBuiltinType($typeName)) {
                try {
                    return $providedParameters[$typeName] ?? $getter($typeName, !$parameter->isVariadic());
                } catch (NotFoundServiceException $e) {
                    // Ignore this exception ...
                } catch (ContainerResolutionException $e) {
                    $errorException = new ContainerResolutionException(\sprintf("{$e->getMessage()} (needed by %s)", Reflection::toString($parameter)));
                }

                if (
                    ServiceProviderInterface::class === $typeName &&
                    null !== $class = $parameter->getDeclaringClass()
                ) {
                    if (!$class->implementsInterface(ServiceSubscriberInterface::class)) {
                        throw new ContainerResolutionException(\sprintf(
                            'Service of type %s needs parent class %s to implement %s.',
                            $typeName,
                            $class->getName(),
                            ServiceSubscriberInterface::class
                        ));
                    }

                    return $getter($class->getName());
                }
            }

            if (\PHP_MAJOR_VERSION >= 8 && $attributes = $parameter->getAttributes()) {
                foreach ($attributes as $attribute) {
                    if (Inject::class === $attribute->getName()) {
                        if (null === $attrName = $attribute->getArguments()[0] ?? null) {
                            throw new ContainerResolutionException(\sprintf('Using the Inject attribute on parameter %s requires a value to be set.', $parameter->getName()));
                        }

                        if ($arrayLike = \str_ends_with($attrName, '[]')) {
                            $attrName = \substr($attrName, 0, -2);
                        }

                        try {
                            return $getter($attrName, !$arrayLike);
                        } catch (NotFoundServiceException $e) {
                            // Ignore this exception ...
                        }
                    }
                }
            }

            if (
                $method instanceof \ReflectionMethod
                && \preg_match('#@param[ \t]+([\w\\\\]+)(?:\[\])?[ \t]+\$' . $parameter->name . '#', (string) $method->getDocComment(), $m)
                && ($itemType = Reflection::expandClassName($m[1], $method->getDeclaringClass()))
                && (\class_exists($itemType) || \interface_exists($itemType))
            ) {
                try {
                    if (\in_array($typeName, ['array', 'iterable'], true)) {
                        return $getter($itemType, false);
                    }

                    if ('object' === $typeName || \is_subclass_of($itemType, $typeName)) {
                        return $getter($itemType, true);
                    }
                } catch (NotFoundServiceException $e) {
                    // Ignore this exception ...
                }
            }

            if (isset($errorException)) {
                throw $errorException;
            }
        }

        return self::getDefaultValue($parameter, \implode('|', $types));
    }

    /**
     * Get the parameter's default value else null.
     *
     * @throws \ReflectionException|ContainerResolutionException
     *
     * @return mixed
     */
    private static function getDefaultValue(\ReflectionParameter $parameter, string $typedHint)
    {
        if ($parameter->isOptional() || $parameter->allowsNull()) {
            return null;
        }

        $desc = Reflection::toString($parameter);

        if (Reflection::isBuiltinType($typedHint)) {
            throw new ContainerResolutionException(\sprintf('Builtin type "%s" defined in %s is not supported for autowiring. Did you forget to set a value for the parameter?', $typedHint, $desc));
        }

        if (Validators::isType($typedHint)) {
            throw new ContainerResolutionException(\sprintf('Parameter type hint "%s" needed by %s not found in container. Did you forgot to autowire it?', $typedHint, $desc));
        }

        if (empty($typedHint)) {
            $message = 'Parameter %s%s has no type hint or default value, so its value must be specified.';
        }

        throw new ContainerResolutionException(\sprintf($message ?? 'Parameter type hint "%s" needed by %s not found. Check type hint and \'use\' statements.', $typedHint, $desc));
    }
}
