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

namespace Rade\DI\Injector;

use Nette\Utils\Reflection;
use PhpParser\Builder\FunctionLike;
use PhpParser\BuilderFactory;
use PhpParser\Node\Expr\{Assign, Variable};
use Rade\DI\Attribute\Inject;
use Rade\DI\Exceptions\ContainerResolutionException;
use Rade\DI\Resolver;

/**
 * An injectable class used by service definitions.
 *
 * @internal This is almost a final class
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class Injectable
{
    private object $service;

    /** @var array<int,array<string,mixed>> */
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

    public function build(FunctionLike|array &$definition, Variable $service, BuilderFactory $builder): Assign
    {
        $calls = [];
        $calls[] = $createdService = new Assign($service, $this->service);

        foreach ($this->properties[0] ?? [] as $property => $propertyValue) {
            $calls[] = new Assign($builder->propertyFetch($service, $property), $builder->val($propertyValue));
        }

        foreach ($this->properties[1] ?? [] as $method => $methodValue) {
            $calls[] = $builder->methodCall($service, $method, $methodValue);
        }

        try {
            return $createdService;
        } finally {
            $definition = $definition instanceof FunctionLike ? $definition->addStmts($calls) : $calls;
        }
    }

    /**
     * Return the properties with the #[Inject] attribute.
     *
     * @return array<string,mixed>
     */
    public function getProperties(): array
    {
        return $this->properties[0] ?? [];
    }

    /**
     * Return the methods with the #[Inject] attribute.
     *
     * @return array<string,mixed>
     */
    public function getMethods(): array
    {
        return $this->properties[1] ?? [];
    }

    /**
     * Generates list of properties with #[Inject] attributes.
     *
     * @return array<string,array<string,mixed[]>>
     */
    public static function getResolved(Resolver $resolver, object $service, \ReflectionClass $reflection): object
    {
        $properties = [];

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if (empty($propertyAttributes = $property->getAttributes(Inject::class))) {
                continue;
            }

            if (\count($propertyAttributes) > 1) {
                throw new ContainerResolutionException(
                    \sprintf('Property "%s" has more than one #[Inject] attribute.', $property->getName())
                );
            }

            if (!empty($pValue = $propertyAttributes[0]->getArguments()[0] ?? null)) {
                $properties[0][$property->getName()] = $propertyAttributes[0]->newInstance()->resolve($resolver);
                continue;
            }

            foreach (Resolver::getTypes($property) as $pType) {
                if (Reflection::isBuiltinType($pType)) {
                    continue;
                }

                if (!empty($pValue = $resolver->resolveReference('?' . $pType))) {
                    $properties[0][$property->getName()] = $pValue;
                }
            }
        }

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (empty($methodAttributes = $method->getAttributes(Inject::class))) {
                continue;
            }

            if (\count($methodAttributes) > 1) {
                throw new ContainerResolutionException(
                    \sprintf('Method %s::%s has more than one #[Inject] attribute.', $reflection->getName(), $method->getName())
                );
            }

            if (!empty($methodAttributes[0]->getArguments())) {
                throw new ContainerResolutionException(\sprintf('Method with #[Inject] attribute does not support having arguments.'));
            }

            $properties[1][$method->getName()] = $resolver->autowireArguments($method);
        }

        if (!empty($properties)) {
            $service = new self($service, $properties);

            if (null === $resolver->getBuilder()) {
                $service = $service->resolve();
            }
        }

        return $service;
    }
}
