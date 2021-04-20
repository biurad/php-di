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

use Nette\Utils\{Callback, Reflection};
use PhpParser\Builder\Method;
use PhpParser\BuilderFactory;
use PhpParser\Node\{
    Expr,
    Expr\Array_,
    Expr\ArrayItem,
    Expr\Assign,
    Expr\New_,
    Expr\Variable,
    Scalar\String_
};
use PhpParser\ParserFactory;
use Rade\DI\{
    Builder\Statement,
    Exceptions\ServiceCreationException,
    RawDefinition,
    Builder\Reference,
    Resolvers\Resolver
};
use Rade\DI\Exceptions\ContainerResolutionException;

/**
 * Resolves entity, parameters and calls in definition tree.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
trait ResolveTrait
{
    private Resolver $resolver;

    private BuilderFactory $builder;

    /** @var mixed */
    private $entity;

    private array $parameters;

    /** @var string[]|string|null */
    private $type = null;

    private array $calls = [];

    private array $extras = [];

    private bool $autowire = false;

    /**
     * Resolves the Definition when in use in Container.
     *
     * @return mixed
     */
    public function __invoke()
    {
        if (($resolved = $this->entity) instanceof Statement) {
            $this->parameters += $resolved->args;
            $resolved = $resolved->value;
        }

        try {
            $resolved = $this->resolver->resolve($resolved, $this->resolveArguments($this->parameters, null, false));
        } catch (ContainerResolutionException $e) {
            // May be a valid object class
            if (!\is_object($resolved)) {
                throw $e;
            }
        }

        if (\function_exists('trigger_deprecation') && [] !== $deprecation = $this->deprecated) {
            \trigger_deprecation($deprecation['package'], $deprecation['version'], $deprecation['message']);
        }

        foreach ($this->calls as $bind => $value) {
            $arguments = $this->resolveArguments(\is_array($value) ? $value : [$value], null, false);

            if (\is_object($resolved) && !\is_callable($resolved)) {
                if (\property_exists($resolved, $bind)) {
                    $resolved->{$bind} = !\is_array($value) ? \current($arguments) : $arguments;

                    continue;
                }

                if (\method_exists($resolved, $bind)) {
                    $this->resolver->resolve([$resolved, $bind], $arguments);

                    continue;
                }
            }
        }

        foreach ($this->extras as $code) {
            if ($code instanceof Statement) {
                $args = $this->resolveArguments($code->args, null, false);
                $code = $code->value;
            }

            $this->resolver->resolve($code, $args ?? []);
        }

        return $resolved;
    }

    /**
     * Processes a entity found in a definition tree.
     *
     * @throws \ReflectionException
     */
    protected function resolveEntity($entity, array $arguments = [])
    {
        if ($entity instanceof Statement) {
            $arguments += $entity->args;
            $entity = $entity->value;
        }

        if (!$entity instanceof Reference && \is_string($entity)) {
            // normalize Class::method to [Class, method]
            if (\str_contains($entity, '::')) {
                [$ref, $method] = \explode('::', $entity, 2);

                return $this->resolveCallable([$ref, $method], $arguments, $ref);
            }

            if (\function_exists($entity)) {
                return $this->resolveCallable([$entity], $arguments, $entity);
            }

            if (\method_exists($entity, '__invoke')) {
                return $this->resolveCallable([$entity, '__invoke'], $arguments, $entity);
            }

            if (\class_exists($entity)) {
                if (empty($this->type)) {
                    $this->typeOf($entity);
                }

                $arguments = $this->resolveArguments($arguments);

                if ($this->lazy) {
                    $entity = $this->builder->constFetch($entity.'::class');

                    return $this->builder->methodCall(
                        $this->builder->propertyFetch($this->builder->var('this'), 'resolver'),
                        'resolveClass',
                        [] !== $arguments ? [$entity, $arguments] : [$entity]
                    );
                }

                return $this->resolver->getContainer()->resolveClass($entity, $arguments);
            }
        }

        if (\is_callable($entity)) {
            if ($entity instanceof \Closure || \is_object($entity[0])) {
                throw new \OutOfBoundsException('Using closure or object callable as service definition is not supported.');
            }

            if (empty($this->type)) {
                $this->typeOf(Reflection::getReturnTypes(Callback::toReflection($entity)));
            }

            return $this->resolveCallable($entity, $arguments, $entity[0]);
        }

        if (\is_array($entity) && 2 === \count($entity)) {
            static $class;

            switch (true) {
                case $entity[0] instanceof Statement:
                    $entity[0] = $this->resolveEntity($class = $entity[0]->value, $entity[0]->args);

                    if (!\is_string($class)) {
                        $class = null;
                    }

                    break;

                case $entity[0] instanceof self:
                    $entity[0] = $this->resolver->getContainer()->get($class = $entity[0]->id);

                    break;

                case \is_string($entity[0]) && \class_exists($class = $entity[0], false):
                    break;

                case \is_string($entity[0]) && \str_starts_with($entity[0], '@'):
                    $entity[0] = new Reference(\substr($entity[0], 1));

                    // no break
                case $entity[0] instanceof Reference:
                    $class = (string) $entity[0];
                    $entity[0] = $this->resolveReference($entity[0], true);

                    break;

                default:
                    throw new ServiceCreationException('Definition entity is not a valid callable type.');
            }

            return $this->resolveCallable($entity, $arguments, $class);
        }

        throw new ServiceCreationException('Definition entity is provided is not valid or supported.');
    }

    protected function resolveReference(Reference $reference, bool $callback = false)
    {
        if ('[]' === \substr($referenced = (string) $reference, -2)) {
            $referenced = \substr($referenced, 0, -2);

            if ($callback) {
                throw new ServiceCreationException(
                    \sprintf('Using a array like service %s reference for callable service is not supported.', $referenced)
                );
            }

            if ($this->resolver->has($referenced)) {
                return $this->resolver->get($referenced);
            }

            return [$this->resolver->getContainer()->get($referenced)];
        }

        return $this->resolver->getContainer()->get($referenced);
    }

    /**
     * @param array|callable $service
     *
     * @throws \ReflectionException
     */
    protected function resolveCallable($service, array $arguments, ?string $class = null): Expr
    {
        if (null !== $class) {
            static $bind;

            if ($this->resolver->getContainer()->has($class)) {
                if (!$service[0] instanceof Expr) {
                    $service[0] = $this->resolveReference(new Reference($class));
                }

                if (\count($found = $this->resolver->find($class)) > 1) {
                    throw new ServiceCreationException(\sprintf('Multiple services found for %s.', $class));
                }

                $class = $this->resolver->getContainer()->extend([] === $found ? $class : \current($found))->entity;

                if ($class instanceof Statement && $service[0] instanceof Expr) {
                    $class = $class->value;
                }
            }

            if (isset($service[1]) && (\is_string($class) && \class_exists($class))) {
                $bind = new \ReflectionMethod($class, $service[1]);
            } elseif (null === $service[1] ?? null) {
                $bind = new \ReflectionFunction($class);
            }

            if (null !== $bind && empty($this->type)) {
                $this->typeOf($types = Reflection::getReturnTypes($bind));

                if ($this->autowire) {
                    $this->resolver->autowire($this->id, $types);
                }
            }

            if ($this->lazy) {
                $arguments = $this->resolveArguments($arguments);

                return $this->builder->methodCall(
                    $this->builder->propertyFetch($this->builder->var('this'), 'resolver'),
                    'resolve',
                    [$service, $this->resolveArguments($arguments)]
                );
            }

            $arguments = $this->resolveArguments($arguments, $bind);

            if ($bind instanceof \ReflectionFunction) {
                return $this->builder->funcCall($class, $arguments);
            }

            $service[0] = $service[0] instanceof Expr ? $service[0] : $this->resolveEntity($service[0], $arguments);

            if ($bind instanceof \ReflectionMethod) {
                return $this->builder->{$bind->isStatic() ? 'staticCall' : 'methodCall'}($service[0], $service[1], $arguments);
            }
        }

        return $this->builder->funcCall(new Array_([new ArrayItem($service[0]), new ArrayItem(new String_($service[1]))]), $arguments);
    }

    protected function resolveCalls(Variable $service, Expr $factory, Method $node): Method
    {
        foreach ($this->calls as $name => $value) {
            if ($factory instanceof New_ || ($factory instanceof Expr\MethodCall && 'resolveClass' === (string) $factory->name)) {
                $arguments = \is_array($value) ? $value : [$value];

                if (\property_exists($class = $this->entity, $name)) {
                    $arguments = $this->resolveArguments($arguments);
                    $node->addStmt(new Assign($this->builder->propertyFetch($service, $name), !\is_array($value) ? \current($arguments) : $arguments));

                    continue;
                }

                if (\method_exists($class, $name)) {
                    $arguments = $this->resolveArguments($arguments, new \ReflectionMethod($class, $name));
                    $node->addStmt($this->builder->methodCall($service, $name, $arguments));

                    continue;
                }
            }
        }

        if ([] !== $this->extras) {
            $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);

            foreach ($this->extras as $code) {
                if ($code instanceof Statement) {
                    $node->addStmt($this->resolveEntity($code->value, $code->args));

                    continue;
                }

                $node->addStmts($parser->parse("<?php\n".$code));
            }
        }

        return $node;
    }

    protected function resolveArguments(array $arguments = [], ?\ReflectionFunctionAbstract $bind = null, bool $compile = true): array
    {
        $arguments = \array_map(function ($value) use ($bind, $compile) {
            if ($value instanceof RawDefinition) {
                return !$compile ? $value() : $this->builder->val($value());
            }

            if ($value instanceof self) {
                return $this->resolver->getContainer()->get($value->id);
            }

            if ($value instanceof Reference) {
                return $this->resolveReference($value);
            }

            if (!$compile) {
                if ($value instanceof Statement) {
                    return $this->resolver->resolve($value->value, $value->args);
                }

                if (\is_array($value)) {
                    return $this->resolveArguments($value, $bind, $compile);
                }

                return $value;
            }

            try {
                return $this->resolveEntity($value);
            } catch (ContainerResolutionException $e) {
                if (\is_array($value)) {
                    return $this->resolveArguments($value, $bind, $compile);
                }
            }

            return $this->builder->val($value);
        }, $arguments);

        return null === $bind ? $arguments : $this->resolver->autowireArguments($bind, $arguments);
    }

    protected function resolveDeprecation(array $deprecation, Method $node): Method
    {
        if ([] === $deprecation) {
            return $node;
        }

        if (\function_exists('trigger_deprecation')) {
            return $node->addStmt(
                $this->builder->funcCall('\trigger_deprecation', [$deprecation['package'], $deprecation['version'], $deprecation['message']])
            );
        }

        $comment = <<<'COMMENT'
/**
 * @deprecated %s
 */
COMMENT;

        $deprecatedComment = \sprintf(
            $comment,
            ($deprecation['package'] || $deprecation['version'] ? "Since {$deprecation['package']} {$deprecation['version']}: " : '').$deprecation['message']
        );
        $node->setDocComment($deprecatedComment);

        return $node;
    }
}
