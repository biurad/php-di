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

use Nette\Utils\{Callback, Reflection, Validators};
use PhpParser\Builder\Method;
use PhpParser\BuilderFactory;
use PhpParser\Node\{
    Expr,
    Expr\Assign,
    Expr\New_,
    Expr\Variable,
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

    private bool $autowired = false;

    /**
     * Resolves the Definition when in use in Container.
     *
     * @return mixed
     */
    public function __invoke()
    {
        $resolved = $this->entity;

        if (\function_exists('trigger_deprecation') && [] !== $deprecation = $this->deprecated) {
            \trigger_deprecation($deprecation['package'], $deprecation['version'], $deprecation['message']);
        }

        if ($resolved instanceof Statement) {
            $this->parameters += $resolved->args;
            $resolved = $resolved->value;
        }

        try {
            $resolved = $this->resolver->resolve($resolved, $this->resolveArguments($this->parameters, null, false));

            foreach ($this->calls as $bind => $value) {
                if (\is_object($resolved) && !\is_callable($resolved)) {
                    $arguments = $this->resolveArguments(\is_array($value) ? $value : [$value], null, false);

                    if (\property_exists($resolved, $bind)) {
                        $resolved->{$bind} = !\is_array($value) ? \current($arguments) : $arguments;
                    } elseif (\method_exists($resolved, $bind)) {
                        $this->resolver->resolve([$resolved, $bind], $arguments);
                    }
                }
            }
        } catch (ContainerResolutionException $e) {
            // No exception is need here ...
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
     * @param mixed $entity
     *
     * @throws \ReflectionException
     */
    protected function resolveEntity($entity, array $arguments = [], bool $type = true)
    {
        if ($entity instanceof Reference) {
            throw new ServiceCreationException(
                \sprintf('Referenced definition entity for "%s" is not supported. Alias %1$s to %s instead.', $this->id, (string) $entity)
            );
        }

        if ($entity instanceof Statement) {
            $arguments += $entity->args;

            return $this->resolveEntity($entity->value, $arguments, $type);
        }

        if (\is_string($entity)) {
            switch (true) {
                // normalize Class::method to [Class, method]
                case \str_contains($entity, '::'):
                    return $this->resolveEntity(\explode('::', $entity, 2), $arguments);

                case \function_exists($entity):
                    return $this->resolveCallable([$entity], $arguments, '', $type);

                case \method_exists($entity, '__invoke'):
                    return $this->resolveCallable([$entity, '__invoke'], $arguments, $entity, $type);

                case \class_exists($entity):
                    if ($type && empty($this->type)) {
                        $this->typeOf($entity);
                    }

                    return ($type && $this->lazy)
                        ? $this->resolveLazyEntity($entity, $arguments)
                        : $this->resolver->getContainer()->resolveClass($entity, $this->resolveArguments($arguments));
            }
        } elseif (\is_callable($entity)) {
            if ($entity instanceof \Closure || \is_object($entity[0])) {
                throw new \OutOfBoundsException('Using closure or object callable as service definition is not supported.');
            }

            if ($type && empty($this->type)) {
                $this->typeOf(Reflection::getReturnTypes(Callback::toReflection($entity)));
            }

            return $this->resolveCallable($entity, $arguments, $entity[0], $type);
        }

        if (\is_array($entity) && \array_keys($entity) === [0, 1]) {
            static $class;

            switch (true) {
                case $entity[0] instanceof Expr\BinaryOp\Coalesce:
                    $class = $entity[0]->left->dim->value;

                    break;
                case $entity[0] instanceof Statement:
                    $entity[0] = $this->resolveEntity($class = $entity[0]->value, $entity[0]->args);

                    if (!\is_string($class)) {
                        $class = '';
                    }

                    break;

                case $entity[0] instanceof self:
                    $entity[0] = new Reference($entity[0]->id);

                    // no break
                case \is_string($entity[0]) && \class_exists($class = $entity[0]):
                    break;

                case \is_string($entity[0]) && \str_starts_with($entity[0], '@'):
                    $entity[0] = new Reference(\substr($entity[0], 1));

                    // no break
                case $entity[0] instanceof Reference:
                    $class = (string) $entity[0];
                    $entity[0] = $this->resolveReference($entity[0], true);

                    break;
            }

            return null !== $class ? $this->resolveCallable($entity, $arguments, $class, $type) : $entity;
        }

        throw new ServiceCreationException(\sprintf('Definition entity for %s provided is not valid or supported.', $this->id));
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
    protected function resolveCallable($service, array $arguments, string $class, bool $type): Expr
    {
        static $bind;

        /** @var \Rade\DI\ContainerBuilder $container */
        $container = $this->resolver->getContainer();

        if ('' !== $class && $container->has($class)) {
            if (!$service[0] instanceof Expr) {
                $service[0] = $this->resolveReference(new Reference($class));
            }

            if (\count($found = $this->resolver->find($class)) > 1) {
                throw new ServiceCreationException(\sprintf('Multiple services found for %s.', $class));
            }

            $class = $container->extend(\current($found) ?: $class)->entity;

            if ($class instanceof Statement && $service[0] instanceof Expr) {
                $class = $class->value;
            } elseif (\is_array($class)) {
                $class = '';
            }
        }

        if (isset($service[1]) && \class_exists($class)) {
            $bind = new \ReflectionMethod($class, $service[1]);
        } elseif ('' === $class && 1 === \count($service)) {
            $bind = new \ReflectionFunction($service[0]);
        }

        if ($type && (null !== $bind && empty($this->type))) {
            $this->typeOf($types = Reflection::getReturnTypes($bind));

            if ($this->autowired) {
                $this->resolver->autowire($this->id, $types);
            }
        }

        if ($type && $this->lazy) {
            return $this->resolveLazyEntity($service, $arguments);
        }

        $arguments = $this->resolveArguments($arguments, $bind);

        if ($bind instanceof \ReflectionFunction) {
            return $this->builder->funcCall($service[0], $arguments);
        }

        $service[0] = $service[0] instanceof Expr ? $service[0] : $this->resolveEntity($service[0], [], $type);

        if ($bind instanceof \ReflectionMethod) {
            return $this->builder->{$bind->isStatic() ? 'staticCall' : 'methodCall'}($service[0], $service[1], $arguments);
        }

        return $this->builder->funcCall($this->builder->val($service), $arguments);
    }

    protected function resolveCalls(Variable $service, Expr $factory, Method $node): Method
    {
        foreach ($this->calls as $name => $value) {
            $arguments = \is_array($value) ? $value : [$value];

            if (
                $factory instanceof New_ ||
                ($factory instanceof Expr\MethodCall && \current($factory->args)->value instanceof Expr\ConstFetch)
            ) {
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

            if (\str_starts_with($name, '$')) {
                $arguments = $this->resolveArguments($arguments);
                $node->addStmt(new Assign($this->builder->var(\substr($name, 1)), !\is_array($value) ? \reset($arguments) : $arguments));
            }
        }

        if ([] !== $this->extras) {
            $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);

            foreach ($this->extras as $code) {
                if ($code instanceof Statement) {
                    $node->addStmt($this->resolveEntity($code->value, $code->args));

                    continue;
                }

                $node->addStmts($parser->parse("<?php\n" . $code));
            }
        }

        return $node;
    }

    protected function resolveArguments(array $arguments = [], ?\ReflectionFunctionAbstract $bind = null, bool $compile = true): array
    {
        foreach ($arguments as $key => $value) {
            if (\is_array($value)) {
                $arguments[$key] = $this->resolveArguments($value, null, $compile);

                continue;
            }

            if ($value instanceof self) {
                $arguments[$key] = $this->resolver->getContainer()->get($value->id);

                continue;
            }

            if ($value instanceof Reference) {
                $arguments[$key] = $this->resolveReference($value);

                continue;
            }

            if ($value instanceof Statement) {
                $resolve = !$compile ? [$this->resolver, 'resolve'] : [$this, 'resolveEntity'];
                $arguments[$key] = $resolve($value->value, $value->args, false);

                continue;
            }

            if ($value instanceof RawDefinition) {
                $value = $value();
            }

            $arguments[$key] = !$compile ? $value : $this->builder->val($value);
        }

        return null === $bind ? $arguments : $this->resolver->autowireArguments($bind, $arguments);
    }

    /**
     * @param array|string $entity
     */
    protected function resolveLazyEntity($entity, array $arguments): Expr\MethodCall
    {
        $arguments = $this->resolveArguments($arguments);
        $resolver = $this->builder->propertyFetch($this->builder->var('this'), 'resolver');

        if (\is_string($entity) && Validators::isType($entity)) {
            $entity = $this->builder->constFetch($entity . '::class');
        }

        return $this->builder->methodCall($resolver, 'resolve', [] !== $arguments ? [$entity, $arguments] : [$entity]);
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
            ($deprecation['package'] || $deprecation['version'] ? "Since {$deprecation['package']} {$deprecation['version']}: " : '') . $deprecation['message']
        );
        $node->setDocComment($deprecatedComment);

        return $node;
    }
}
