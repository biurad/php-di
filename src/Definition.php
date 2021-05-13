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
use PhpParser\Node\{
    Expr\ArrayDimFetch,
    Expr\Assign,
    Expr\BinaryOp,
    Expr\StaticPropertyFetch,
    Name,
    Scalar\String_,
    Stmt\Return_,
    UnionType
};
use PhpParser\BuilderFactory;
use Rade\DI\{Builder\Statement, Exceptions\ServiceCreationException};

/**
 * Represents definition of standard service.
 *
 * @method string getId() Get the definition's id.
 * @method mixed getEntity() Get the definition's entity.
 * @method array<string,mixed> getParameters() Get the definition's parameters.
 * @method string|string[] getType() Get the return types for definition.
 * @method array<string,mixed> getCalls() Get the bind calls to definition.
 * @method array<int,mixed> getExtras() Get the list of extras binds.
 * @method string[] getDeprecation() Return a non-empty array if definition is deprecated.
 * @method bool isDeprecated() Whether this definition is deprecated, that means it should not be used anymore.
 * @method bool isLazy() Whether this service is lazy.
 * @method bool isFactory() Whether this service is not a shared service.
 * @method bool isPublic() Whether this service is a public type.
 * @method bool isAutowired() Whether this service is autowired.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class Definition
{
    use Traits\ResolveTrait;

    /** Marks a definition as being a factory service. */
    public const FACTORY = 1;

    /** This is useful when you want to autowire a callable or class string lazily. */
    public const LAZY = 2;

    /** Marks a definition as a private service. */
    public const PRIVATE = 4;

    /** Use in second parameter of bind method. */
    public const EXTRA_BIND = '@code@';

    /** supported call in get() method. */
    private const SUPPORTED_GET = [
        'id'  => 'id',
        'entity' => 'entity',
        'parameters' => 'parameters',
        'type' => 'type',
        'calls' => 'calls',
        'extras' => 'extras',
    ];

    private const IS_TYPE_OF = [
        'lazy' => 'lazy',
        'factory' => 'factory',
        'public' => 'public',
        'deprecated' => 'deprecated',
    ];

    private string $id;

    private bool $factory = false;

    private bool $lazy = false;

    private bool $public = true;

    private array $deprecated = [];

    /**
     * Definition constructor.
     *
     * @param mixed                   $entity
     * @param array<int|string,mixed> $arguments
     */
    public function __construct($entity, array $arguments = [])
    {
        $this->replace($entity, true);
        $this->parameters = $arguments;
    }

    /**
     * @param string   $method
     * @param mixed[] $arguments
     *
     * @throws \BadMethodCallException
     *
     * @return mixed
     */
    public function __call($method, $arguments)
    {
        // Fix autowired conflict
        if ('isAutowired' === $method) {
            return $this->autowired;
        }

        $method = (string) \preg_replace('/^get|is([A-Z]{1}[a-z]+)$/', '\1', $method, 1);
        $method = \strtolower($method);

        if (isset(self::IS_TYPE_OF[$method])) {
            return (bool) $this->{$method};
        }

        return $this->get($method);
    }

    /**
     * The method name generated for a service definition.
     */
    final public static function createMethod(string $id): string
    {
        return 'get' . \str_replace(['.', '_'], '', \ucwords($id, '._'));
    }

    /**
     * Attach the missing id and resolver to this definition.
     * NB: This method is used internally and should not be used directly.
     *
     * @internal
     */
    final public function attach(string $id, Resolvers\Resolver $resolver): void
    {
        $this->id = $id;
        $this->resolver = $resolver;
    }

    /**
     * Get any of (id, entity, parameters, type, calls, extras, deprecation).
     *
     * @throws \BadMethodCallException if $name does not exist as property
     *
     * @return mixed
     */
    final public function get(string $name)
    {
        if ('deprecation' === $name) {
            $deprecation = $this->deprecated;

            if (isset($deprecation['message'])) {
                $deprecation['message'] = \sprintf($deprecation['message'], $this->id);
            }

            return $deprecation;
        }

        if (!isset(self::SUPPORTED_GET[$name])) {
            throw new \BadMethodCallException(\sprintf('Property call for %s invalid, %s::get(\'%1$s\') not supported.', $name, __CLASS__));
        }

        return $this->{$name};
    }

    /**
     * Replace existing entity to a new entity.
     *
     * NB: Using this method must be done before autowiring
     * else autowire manually.
     *
     * @param mixed $entity
     * @param bool  $if     rule matched
     *
     * @return $this
     */
    final public function replace($entity, bool $if): self
    {
        if ($entity instanceof RawDefinition) {
            throw new ServiceCreationException(\sprintf('An instance of %s is not a valid definition entity.', RawDefinition::class));
        }

        if ($if /* Replace if matches a rule */) {
            $this->entity = $entity;
        }

        return $this;
    }

    /**
     * Sets the arguments to pass to the service constructor/factory method.
     *
     * @return $this
     */
    final public function args(array $arguments): self
    {
        $this->parameters = $arguments;

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
        $this->parameters[$key] = $value;

        return $this;
    }

    /**
     * Sets method, property, Class|@Ref::Method or php code bindings.
     *
     * Binding map method name, property name, mixed type or php code that should be
     * injected in the definition's entity as assigned property, method or
     * extra code added in running that entity.
     *
     * @param string $nameOrMethod A parameter name, a method name, or self::EXTRA_BIND
     * @param mixed  $valueOrRef   The value, reference or statement to bind
     *
     * @return $this
     */
    final public function bind(string $nameOrMethod, $valueOrRef): self
    {
        if (self::EXTRA_BIND === $nameOrMethod) {
            $this->extras[] = $valueOrRef;

            return $this;
        }

        $this->calls[$nameOrMethod] = $valueOrRef;

        return $this;
    }

    /**
     * Enables autowiring.
     *
     * @return $this
     */
    final public function autowire(array $types = []): self
    {
        $this->autowired = true;
        $service = $this->entity;

        if ($service instanceof Statement) {
            $service = $service->value;
        }

        if ([] === $types) {
            if (\is_string($service) && \class_exists($service)) {
                $types = [$service];
            } elseif (\is_callable($service)) {
                $types = Reflection::getReturnTypes(Callback::toReflection($service));
            }
        }

        $this->resolver->autowire($this->id, $types);

        return $this->typeOf($types);
    }

    /**
     * Represents a PHP type-hinted for this definition.
     *
     * @param string[]|string $types
     *
     * @return $this
     */
    final public function typeOf($types): self
    {
        if (\is_array($types) && (1 === \count($types) || \PHP_VERSION_ID < 80000)) {
            foreach ($types as $type) {
                if (\class_exists($type)) {
                    $types = $type;

                    break;
                }
            }
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

        $message = $args[2] ?? 'The "%s" service is deprecated. You should stop using it, as it will be removed in the future.';

        $this->deprecated['package'] = $args[0] ?? '';
        $this->deprecated['version'] = $args[1] ?? '';
        $this->deprecated['message'] = $message;

        return $this;
    }

    /**
     * Should the this definition be a type of
     * self::FACTORY|self::PRIVATE|self::LAZY, then set enabled or not.
     *
     * @return $this
     */
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

    /**
     * Resolves the Definition when in use in ContainerBuilder.
     */
    public function resolve(BuilderFactory $builder): \PhpParser\Node\Expr
    {
        $resolved = $builder->methodCall($builder->var('this'), self::createMethod($this->id));

        if ($this->factory) {
            return $resolved;
        }

        return new BinaryOp\Coalesce(
            new ArrayDimFetch(
                new StaticPropertyFetch(new Name('self'), $this->public ? 'services' : 'privates'),
                new String_($this->id)
            ),
            $resolved
        );
    }

    /**
     * Build the definition service.
     *
     * @throws \ReflectionException
     */
    public function build(BuilderFactory $builder): \PhpParser\Builder\Method
    {
        $this->builder = $builder;

        $node = $this->resolveDeprecation($this->deprecated, $builder->method(self::createMethod($this->id))->makeProtected());
        $factory = $this->resolveEntity($this->entity, $this->parameters);

        if (!empty($this->calls + $this->extras)) {
            $node->addStmt(new Assign($resolved = $builder->var($this->public ? 'service' : 'private'), $factory));
            $node = $this->resolveCalls($resolved, $factory, $node);
        }

        if (!empty($types = $this->type)) {
            $node->setReturnType(\is_array($types) ? new UnionType(\array_map(fn ($type) => new Name($type), $types)) : $types);
        }

        if (!$this->factory) {
            $cached = new StaticPropertyFetch(new Name('self'), $this->public ? 'services' : 'privates');
            $resolved = new Assign(new ArrayDimFetch($cached, new String_($this->id)), $resolved ?? $factory);
        }

        return $node->addStmt(new Return_($resolved ?? $factory));
    }
}
