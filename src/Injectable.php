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

use Nette\Utils\Reflection;
use PhpParser\Builder\Method;
use PhpParser\BuilderFactory;
use PhpParser\Node\Expr\{Assign, Variable};
use Rade\DI\Attribute\Inject;
use Rade\DI\Definitions\Reference;
use Rade\DI\Exceptions\ContainerResolutionException;
use Rade\DI\Resolvers\Resolver;

/**
 * An injectable class used by service definitions.
 *
 * @internal This is almost a final class
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class Injectable
{
    private object $service;

    /** @var array<string,array<string,mixed[]>> */
    private array $properties;

    /**
     * @param array<string,array<string,mixed[]>> $properties
     */
    public function __construct(object $service, array $properties)
    {
        $this->service = $service;
        $this->properties = $properties;
    }

    public function resolve(): object
    {
        foreach ($this->properties[0] ?? [] as $property => $propertyValue) {
            $this->service->{$property} = $propertyValue;
        }

        foreach ($this->properties[1] ?? [] as $method => $methodValue) {
            \call_user_func_array([$this->service, $method], (array) $methodValue);
        }

        return $this->service;
    }

    /**
     * @param Definition|Method $definition
     */
    public function build(Method $definition, Variable $service, BuilderFactory $builder): Assign
    {
        $definition->addStmt($createdService = new Assign($service, $this->service));

        foreach ($this->properties[0] ?? [] as $property => $propertyValue) {
            $definition->addStmt(new Assign($builder->propertyFetch($service, $property), $builder->val($propertyValue)));
        }

        foreach ($this->properties[1] ?? [] as $method => $methodValue) {
            $definition->addStmt($builder->methodCall($service, $method, $methodValue));
        }

        return $createdService;
    }

    /**
     * Generates list of properties with #[Inject] attributes.
     *
     * @return array<string,array<string,mixed[]>>
     */
    public static function getProperties(Resolver $resolver, object $service, \ReflectionClass $reflection): object
    {
        if (\PHP_VERSION_ID < 80000) {
            return $service;
        }

        $properties = [];
        self::doProperties($reflection, $resolver, $properties);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ([] === $methodAttributes = $method->getAttributes(Inject::class)) {
                continue;
            }

            if (null === $methodAttribute = $methodAttributes[0] ?? null) {
                continue;
            }

            if ([] !== $methodAttribute->getArguments()) {
                throw new ContainerResolutionException(\sprintf('Method with Inject attributes does not support having arguments.'));
            }

            $properties[1][$method->getName()] = $resolver->autowireArguments($method);
        }

        if ([] === $properties) {
            return $service;
        }

        $injected = new self($service, $properties);

        return null === $resolver->getBuilder() ? $injected->resolve() : $injected;
    }

    private static function doProperties(\ReflectionClass $reflection, Resolver $resolver, array &$properties): void
    {
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ([] === $propertyAttributes = $property->getAttributes(Inject::class)) {
                continue;
            }

            if (null === $propertyAttribute = $propertyAttributes[0] ?? null) {
                continue;
            }

            if ([] !== $pValue = $propertyAttribute->getArguments()) {
                $properties[0][$property->getName()] = $resolver->resolve(new Reference($pValue[0]));

                continue;
            }

            foreach (Reflection::getPropertyTypes($property) as $pType) {
                if (Reflection::isBuiltinType($pType)) {
                    continue;
                }

                $properties[0][$property->getName()] = $resolver->resolve(new Reference($pType));
            }
        }
    }
}
