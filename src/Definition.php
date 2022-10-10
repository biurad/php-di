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

/**
 * Represents definition of standard service.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class Definition
{
    use Traits\DefinitionAwareTrait;

    public function __construct(mixed $entity, private array $arguments = [])
    {
        $this->replace($entity);
    }

    public function hasContainer(): bool
    {
        return isset($this->container);
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    /**
     * Register a service definition into the container.
     *
     * @return $this
     */
    public function set(string $id, mixed $definition = null)
    {
        return $this->container?->set($id, $definition) ?? throw new Exceptions\ContainerResolutionException(\sprintf('Cannot set "%s" definition as container is not set.', $id));
    }

    /**
     * Alias this service to another service id.
     *
     * @param bool $clone If true, the alias will be cloned and returned
     *
     * @return $this
     */
    public function alias(string $id, bool $clone = false)
    {
        if (!$clone) {
            null === $this->container ? $this->options['aliases'][$id] = true : $this->container->alias($id, $this->id);

            return $this;
        }

        return $this->set($id, clone $this);
    }

    public function getEntity(): mixed
    {
        return $this->entity;
    }

    /**
     * Set the service entity.
     *
     * @return $this
     */
    public function replace(mixed $entity)
    {
        if ($entity instanceof Definitions\Statement) {
            $this->args($entity->getArguments());
            $this->lazy($entity->isClosureWrappable());
            $entity = $entity->getValue();
        } elseif ($entity instanceof self) {
            throw new Exceptions\ContainerResolutionException('Service definition cannot be nested');
        }
        $this->entity = $entity;

        return $this;
    }

    /**
     * Sets/Replace an argument for a service constructor/factory method.
     *
     * @return $this
     */
    public function arg(int|string $name, mixed $value)
    {
        $this->arguments[$name] = $value;

        return $this;
    }

    /**
     * Sets/Replaces arguments for service constructor/factory method.
     *
     * @param array<int|string,mixed> $arguments
     *
     * @return $this
     */
    public function args(array $arguments)
    {
        foreach ($arguments as $name => $value) {
            $this->arg($name, $value);
        }

        return $this;
    }

    /**
     * Removes an argument.
     */
    public function removeArgument(int|string $key): void
    {
        unset($this->arguments[$key]);
    }

    /**
     * Returns true if the definition has an argument.
     */
    public function hasArgument(int|string|null $key = null): bool
    {
        return null !== $key ? isset($this->arguments[$key]) : !empty($this->arguments);
    }

    /**
     * Get the definition's arguments.
     */
    public function getArgument(int|string|null $key = null): mixed
    {
        return null !== $key ? ($this->arguments[$key] ?? null) : $this->arguments;
    }

    /**
     * Set/Replace a method/property binding to service definition.
     *
     * @param string $propertyOrMethod A property name prefixed with a $, or a method name
     * @param mixed  $valueOrRef       The value, reference or statement to bind
     *
     * @return $this
     */
    public function bind(string $propertyOrMethod, mixed $value = null)
    {
        if ('$' === $propertyOrMethod[0]) {
            $this->calls['a'][\substr($propertyOrMethod, 1)] = $value;
        } else {
            $this->calls['b'][$propertyOrMethod][] = $value;
        }

        return $this;
    }

    /**
     * Sets a configurator to call after the service is fully initialized.
     *
     * @param mixed $configurator A PHP function, reference or an array containing a class/Reference and a method to call
     * @param bool  $extend       If true, this service is passed as first argument into $configurator
     *
     * @return $this
     */
    public function call(mixed $configurator, bool $extend = false)
    {
        $this->calls['c'][] = [$configurator, $extend];

        return $this;
    }

    /**
     * Removes a method/property/configurator binding.
     *
     * @param int|string $propertyOrMethod A property name prefixed with a $, a method name,
     *                                     or configurator index
     */
    public function removeBinding(int|string $propertyOrMethod): void
    {
        if (\is_string($propertyOrMethod)) {
            $type = 'b';

            if ('$' === $propertyOrMethod[0]) {
                $type = 'a';
                $propertyOrMethod = \substr($propertyOrMethod, 1);
            }

            if (\str_contains($propertyOrMethod, '.')) {
                [$method, $offset] = \explode('.', $propertyOrMethod, 2);

                unset($this->calls[$type][$method][(int) $offset]);
            } else {
                unset($this->calls[$type][$propertyOrMethod]);
            }
        } else {
            unset($this->calls['c'][$propertyOrMethod]);
        }
    }

    /**
     * Returns true if the definition has a method/property binding.\.
     *
     * @param string|null $propertyOrMethod A property name prefixed with a $, or a method name
     */
    public function hasBinding(string $propertyOrMethod = null): bool
    {
        if (!empty($propertyOrMethod)) {
            return isset($this->calls['b'][$propertyOrMethod]) || isset($this->calls['a'][\substr($propertyOrMethod, 1)]);
        }

        return !empty($this->calls);
    }

    /**
     * @param string|null $propertyOrMethod A property name prefixed with a $, or a method name
     *
     * @return array<int|string,mixed>
     */
    public function getBinding(string $propertyOrMethod = null): mixed
    {
        if (!empty($propertyOrMethod)) {
            return $this->calls['b'][$propertyOrMethod] ?? $this->calls['a'][\substr($propertyOrMethod, 1)] ?? null;
        }

        return \array_merge(...$this->calls);
    }

    /**
     * Set the service to be abstract.
     *
     * @return $this
     */
    public function abstract(bool $boolean = true)
    {
        $this->options['abstract'] = $boolean;

        return $this;
    }

    /**
     * Returns true if the service is abstract.
     */
    public function isAbstract(): bool
    {
        return $this->options['abstract'] ?? false;
    }

    /**
     * Sets the service to be shared.
     *
     * @return $this
     */
    public function shared(bool $boolean = true)
    {
        $this->options['shared'] = $boolean;

        return $this;
    }

    /**
     * Returns true if the service is shareable.
     */
    public function isShared(): bool
    {
        return $this->options['shared'] ??= true;
    }

    /**
     * Sets the service to be public.
     *
     * @return $this
     */
    public function public(bool $boolean = true)
    {
        $this->options['public'] = $boolean;

        return $this;
    }

    /**
     * Returns true if the service is public.
     */
    public function isPublic(): bool
    {
        return $this->options['public'] ??= true;
    }

    /**
     * Sets the service to be lazy.
     *
     * @return $this
     */
    public function lazy(bool $boolean = true)
    {
        $this->options['lazy'] = $boolean;

        return $this;
    }

    /**
     * Sets the service to be lazy.
     */
    public function isLazy(): bool
    {
        return $this->options['lazy'] ??= false;
    }

    /**
     * Set the PHP type-hint(s) for this definition.
     *
     * @return $this
     */
    public function typed(string ...$to)
    {
        if ([] === $to) {
            $to = Resolver::autowireService($this->entity, false);
        }

        if (null !== $this->container) {
            $this->container->type($this->id, ...$to);
            $this->options['typed'] = true;

            return $this;
        }

        foreach ($to as $typed) {
            if (isset($this->options['excludes'][$typed])) {
                continue;
            }
            $this->options['types'][$typed] = true;
        }

        return $this;
    }

    /**
     * Set the not expected PHP type-hint(s) for this definition.
     */
    public function excludeType(string ...$type): void
    {
        if (null !== $this->container) {
            $this->container->excludeType(...$type);

            return;
        }

        foreach ($type as $typed) {
            $this->options['excludes'][$typed] = true;
        }
    }

    /**
     * Returns true if the definition is type-hinted.
     */
    public function isTyped(): bool
    {
        return $this->options['typed'] ?? isset($this->options['types']);
    }

    /**
     * @return array<int,string> The list of PHP type-hints for this definition
     */
    public function getTypes(): array
    {
        return $this->container?->typed($this->id, true) ?? \array_keys($this->options['types']);
    }

    /**
     * Remove a PHP type-hint(s) from this definition.
     */
    public function removeType(string ...$type): void
    {
        foreach ($type as $t) {
            if (null !== $this->container) {
                $this->container->removeType($t, $this->id);
            } elseif (isset($this->options['types'][$t])) {
                unset($this->options['types'][$t]);
            }
        }
    }

    /**
     * Adds a tag for this definition.
     *
     * @return $this
     */
    public function tag(string $name, mixed $value = true)
    {
        null !== $this->container ? $this->container->tag($this->id, $name, $value) : $this->options['tags'][$name] = $value;

        return $this;
    }

    /**
     * Sets tags for this definition.
     *
     * @return $this
     */
    public function tags(array $tags)
    {
        foreach ($tags as $tag => $value) {
            $this->tag(\is_int($tag) ? $value : $tag, \is_int($tag) ? true : $value);
        }

        return $this;
    }

    /**
     * If definition has tag, a value will be returned else null.
     */
    public function tagged(string $name): mixed
    {
        return $this->container?->tagged($name, $this->id) ?? $this->options['tags'][$name] ?? null;
    }

    /**
     * Removes service tag(s).
     */
    public function removeTag(string ...$tag): void
    {
        foreach ($tag as $t) {
            if (null !== $this->container) {
                $this->container->removeTag($t, $this->id);
            } elseif (isset($this->options['tags'][$t])) {
                unset($this->options['tags'][$t]);
            }
        }
    }

    /**
     * Returns all tags.
     *
     * @return array<string,mixed>
     */
    public function getTags(): array
    {
        if (null !== $this->container) {
            $tags = [];

            foreach ($this->container->getTags() as $tag => $values) {
                if (isset($values[$this->id])) {
                    $tags[$tag] = $values[$this->id];
                }
            }

            return $tags;
        }

        return $this->options['tags'] ?? [];
    }

    /**
     * Whether this definition is deprecated, that means it should not be used anymore.
     *
     * @param string      $package The name of the composer package that is triggering the deprecation
     * @param float|null  $version The version of the package that introduced the deprecation
     * @param string|null $message The deprecation message to use
     *
     * @return $this
     */
    public function deprecate(string $package = '', float $version = null, string $message = null)
    {
        $this->deprecation['package'] = $package;
        $this->deprecation['version'] = $version ?? '';

        if (!empty($message) && !\str_contains($message, '%service_id%')) {
            throw new \InvalidArgumentException('The deprecation template must contain the "%service_id%" placeholder.');
        }
        $this->deprecation['message'] = $message ?? 'The "%service_id%" service is deprecated. avoid using it, as it will be removed in the future.';

        return $this;
    }

    /**
     * Whether this definition is deprecated, that means it should not be called anymore.
     */
    public function isDeprecated(): bool
    {
        return !empty($this->deprecation);
    }

    /**
     * Return a non-empty array if definition is deprecated.
     *
     * @param string|null $id Service id relying on this definition
     *
     * @return array<string,string>
     */
    public function getDeprecation(string $id = null): array
    {
        if (isset($this->deprecation['message'])) {
            $this->deprecation['message'] = \str_replace('%service_id%', $id ?? $this->id ?? 'definition', $this->deprecation['message']);
        }

        return $this->deprecation;
    }
}
