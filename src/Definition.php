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

use Nette\Utils\{Callback, Reflection};
use PhpParser\Node\{Expr\ArrayDimFetch,
    Expr\ArrowFunction,
    Expr\Assign,
    Expr\New_,
    Expr\StaticPropertyFetch,
    Name,
    Scalar\String_,
    Stmt\Return_,
    UnionType};
use PhpParser\ParserFactory;
use Rade\DI\Builder\Statement;

/**
 * Represents definition of standard service.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class Definition implements \Stringable
{
    use Traits\ResolveTrait;

    /** Marks a definition as being a factory service. */
    public const FACTORY = 1;

    /** This is useful when you want to autowire a callable or class string lazily. */
    public const LAZY = 2;

    /** Marks a definition as being deprecated. */
    public const DEPRECATED = 3;

    /** Marks a definition as a private service. */
    public const PRIVATE = 4;

    public ?string $id = null;

    private bool $factory = false;

    private bool $lazy = false;

    private bool $public = true;

    private array $deprecated = [];

    /**
     * Definition constructor
     *
     * @param mixed                   $entity
     * @param array<int|string,mixed> $arguments
     */
    public function __construct($entity, array $arguments = [])
    {
        $this->entity     = $entity;
        $this->parameters = $arguments;
    }

    /**
     * The method name generated for a service definition.
     */
    public function __toString(): string
    {
        return 'get' . \str_replace(['.', '_'], '', \ucwords($this->id, '._'));
    }

    /**
     * Replace existing entity to a new entity.
     * NB: Using this method must be done before autowiring.
     *
     * @return $this
     */
    final public function replace($entity): self
    {
        $this->entity = $entity;

        return $this;
    }

    /**
     * Sets the arguments to pass to the service constructor/factory method.
     *
     * @return $this
     */
    final public function args(array $arguments): self
    {
        if ($this->entity instanceof Statement) {
            $this->entity->args = $arguments;
        } elseif (!$this->entity instanceof RawService) {
            $this->parameters = $arguments;
        }

        return $this;
    }

    /**
     * Sets/Replace one argument to pass to the service constructor/factory method.
     *
     * @param int|string $key
     * @param mixed      $value
     *
     * @return $this
     */
    final public function arg($key, $value): self
    {
        if ($this->entity instanceof Statement) {
            $this->entity->args[$key] = $value;
        } elseif (!$this->entity instanceof RawService) {
            $this->parameters[$key] = $value;
        }

        return $this;
    }

    /**
     * Sets method, property, FNQC or php code bindings.
     *
     * Binding map method name, property name, FNQC or php code that should be
     * injected in the definition's entity as assigned property, method or
     * extra code added in running that entity.
     *
     * @param string $nameOrMethod A parameter name, or a method name
     * @param mixed  $valueOrRef   The value or reference to bind
     *
     * @return $this
     */
    final public function bind(string $nameOrMethod, $valueOrRef): self
    {
        $this->calls[$nameOrMethod] = $valueOrRef;

        return $this;
    }

    /**
     * Enables autowiring.
     *
     * @throws \ReflectionException
     *
     * @return $this
     */
    final public function autowire(array $types = []): self
    {
        if ([] === $types) {
            if (\is_string($service = $this->entity) && \class_exists($service)) {
                $types = [$service];
            } elseif (\is_callable($service)) {
                $types = Reflection::getReturnTypes(Callback::toReflection($service));
            }
        }

        $this->resolver->autowire($this->id, $types);

        return $this->type($types);
    }

    /**
     * Represents a PHP type-hinted for this definition.
     *
     * @param array|string $types
     *
     * @return $this
     */
    final public function type($types): self
    {
        if (\PHP_VERSION_ID < 80000 && \is_array($types)) {
            $types = \current($types) ?: null;
        }

        $this->type = $types;

        return $this;
    }

    /**
     * Whether this definition is deprecated, that means it should not be used anymore.
     *
     * @param string $package The name of the composer package that is triggering the deprecation
     * @param string $version The version of the package that introduced the deprecation
     * @param string $message The deprecation message to use
     *
     * @return $this
     */
    final public function deprecate(/* string $package, string $version, string $message */): self
    {
        $args = \func_get_args();

        $message = $args[2] ?? \sprintf('The "%s" service is deprecated. You should stop using it, as it will be removed in the future.', $this->id);

        $this->deprecated['package'] = $args[0] ?? '';
        $this->deprecated['version'] = $args[1] ?? '';
        $this->deprecated['message'] = $message;

        return $this;
    }

    /**
     * Checks if this definition is factory, or lazy type.
     */
    public function is(int $type = self::FACTORY): bool
    {
        if (self::FACTORY === $type) {
            return $this->factory;
        }

        if (self::LAZY === $type) {
            return $this->lazy;
        }

        if (self::DEPRECATED === $type) {
            return (bool) $this->deprecated;
        }

        if (self::PRIVATE === $type) {
            return !$this->public;
        }

        return false;
    }

    public function should(int $be = self::FACTORY, bool $enabled = true): self
    {
        switch ($be) {
            case self::FACTORY:
                $this->factory = $enabled;

                break;

            case self::LAZY:
                $this->lazy = $enabled;

                break;

            case self::PRIVATE:
                $this->public = !$enabled;

                break;

            case self::PRIVATE | self::FACTORY:
                $this->public = !$enabled;
                $this->factory = $enabled;

                break;

            case self::PRIVATE | self::LAZY:
                $this->public = !$enabled;
                $this->lazy = $enabled;

                break;

            case self::FACTORY | self::LAZY:
                $this->factory = $enabled;
                $this->lazy = $enabled;

                break;

            case self::FACTORY | self::LAZY | self::PRIVATE:
                $this->public = !$enabled;
                $this->factory = $enabled;
                $this->lazy = $enabled;

                break;
        }

        return $this;
    }

}
