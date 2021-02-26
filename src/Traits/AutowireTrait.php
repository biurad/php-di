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

namespace Rade\DI\Traits;

use DivineNii\Invoker\ArgumentResolver\DefaultValueResolver;
use DivineNii\Invoker\CallableReflection;
use DivineNii\Invoker\Exceptions\NotCallableException;
use Rade\DI\Exceptions\ContainerResolutionException;
use Rade\DI\Resolvers\AutowireValueResolver;
use Symfony\Component\Config\Definition\Processor;

trait AutowireTrait
{
    /** @var array<string,mixed> service name => instance */
    private array $values = [];

    /** @var array<string,bool> service name => bool */
    private array $loading = [];

    /** @var array<string,bool> service name => bool */
    private array $frozen = [];

    /** @var array<string,bool> service name => bool */
    protected array $keys = [];

    /** @var string[] alias => service name */
    protected array $aliases = [];

    /** @var array[] tag name => service name => tag value */
    protected array $tags = [];

    /** @var ServiceProviderInterface[] */
    protected $providers = [];

    private \SplObjectStorage $factories;

    private \SplObjectStorage $protected;

    private AutowireValueResolver $resolver;

    private Processor $process;

    /**
     * Creates new instance from class string or callable using autowiring.
     *
     * @param string|callable|object  $callback
     * @param array<int|string,mixed> $args
     *
     * @throws ContainerResolutionException
     *
     * @return mixed
     */
    public function call($callback, array $args = [])
    {
        try {
            $callable = CallableReflection::create($callback);
        } catch (NotCallableException $e) {
            if (\is_string($callback)) {
                return $this->autowireClass($callback, $args);
            }

            throw new ContainerResolutionException($e->getMessage());
        }

        /** @var callable $callback */
        return $callback(...$this->autowireArguments($callable, $args));
    }

    /**
     * Add a clas or interface that should be excluded from autowiring.
     *
     * @param string ...$types
     */
    public function exclude(string ...$types): void
    {
        foreach ($types as $type) {
            $this->resolver->exclude($type);
        }
    }

    /**
     * @param object|callable $service
     */
    private function autowireService(string $id, $service): void
    {
        // Resolving the closure of the service to return it's type hint or class.
        $type = \is_callable($service) ? CallableReflection::create($service)->getReturnType() : \get_class($service);

        if ($type instanceof \ReflectionType) {
            $types = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];

            $type = \array_map(fn (\ReflectionNamedType $type): string => $type->getName(), $types);
        }

        // Resolving wiring so we could call the service parent classes and interfaces.
        if (!isset($this->keys[$id])) {
            $this->resolver->autowire($id, $type);
        }
    }

    /**
     * Resolves arguments for callables
     *
     * @param \ReflectionFunctionAbstract $function
     * @param array<int|string,mixed>     $args
     *
     * @return array<int,mixed>
     */
    private function autowireArguments(\ReflectionFunctionAbstract $function, array $args = []): array
    {
        $resolvedParameters   = [];
        $reflectionParameters = $function->getParameters();

        foreach ($reflectionParameters as $parameter) {
            $position = $parameter->getPosition();

            if (null !== $resolved = $this->resolver->resolve($parameter, $args)) {
                if ($resolved === DefaultValueResolver::class) {
                    $resolved = null;
                } elseif ($parameter->isVariadic() && (\is_array($resolved) && \count($resolved) > 1)) {
                    foreach (\array_chunk($resolved, 1) as $index => [$value]) {
                        $resolvedParameters[$index + 1] = $value;
                    }

                    continue;
                }

                $resolvedParameters[$position] = $resolved;
            }

            if (empty(\array_diff_key($reflectionParameters, $resolvedParameters))) {
                // Stop traversing: all parameters are resolved
                return $resolvedParameters;
            }
        }

        return $resolvedParameters;
    }

    /**
     * @param string                  $class
     * @param array<int|string,mixed> $args
     *
     * @return object
     */
    private function autowireClass(string $class, array $args)
    {
        /** @var class-string $class */
        $reflection = new \ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new ContainerResolutionException("Class $class is not instantiable.");
        }

        if (null !== $constructor = $reflection->getConstructor()) {
            return $reflection->newInstanceArgs($this->autowireArguments($constructor, $args));
        }

        if (!empty($args)) {
            throw new ContainerResolutionException(
                "Unable to pass arguments, class $class has no constructor.",
                \strlen($class)
            );
        }

        return new $class();
    }
}
