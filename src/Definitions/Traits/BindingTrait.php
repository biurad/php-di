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

namespace Rade\DI\Definitions\Traits;

use Rade\DI\Definition;
use Rade\DI\Definitions\Statement;

/**
 * This trait adds method binding functionality to the service definition
 * after service initialization.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
trait BindingTrait
{
    /** @var array<string,mixed> */
    private array $parameters = [];

    /** @var array<int|string,mixed> */
    private array $calls = [];

    /** @var array<int,Statement|string> */
    private array $extras = [];

    /**
     * Set/Replace a method binding or php code binding to service definition.
     *
     * Features this bind methods supports:
     * - Method Binding - Takes in the method's name and its value.
     * - Property Binding - Takes in a property name prefixed $ and its value.
     * - PHP code Binding - Takes in Definition::EXTRA_BIND as key and a string|statement class with code as value.
     *
     * @param string $nameOrMethod A parameter name, a method name, or self::EXTRA_BIND
     * @param mixed  $valueOrRef   The value, reference or statement to bind
     *
     * @return $this
     */
    public function bind(string $nameOrMethod, $valueOrRef): self
    {
        if ('$' === $nameOrMethod[0]) {
            $this->parameters[\substr($nameOrMethod, 1)] = $valueOrRef;
        } elseif (Definition::EXTRA_BIND === $nameOrMethod) {
            $this->extras[] = $valueOrRef;
        } else {
            $this->calls[$nameOrMethod] = $valueOrRef;
        }

        return $this;
    }

    /**
     * Set/Replace a method binding or php code binding to service definition.
     *
     * @see Rade\DI\Definitions\Traits\BindingTrait::bind()
     *
     * @param array<string,mixed> $bindings
     *
     * @return $this
     */
    public function binds(array $bindings): self
    {
        foreach ($bindings as $nameOrMethod => $valueOrRef) {
            $this->bind($nameOrMethod, $valueOrRef);
        }

        return $this;
    }

    /**
     * Removes a method/parameter binding from service definition.
     *
     * @return $this
     */
    public function unbind(string $parameterOrMethod): self
    {
        if (\array_key_exists($parameterOrMethod, $this->calls)) {
            unset($this->calls[$parameterOrMethod]);
        } elseif (\array_key_exists($parameterOrMethod, $this->parameters)) {
            unset($this->parameters[$parameterOrMethod]);
        }

        return $this;
    }

    /**
     * Whether this definition has parameters and/or methods.
     */
    public function hasBinding(): bool
    {
        return !empty($this->parameters) || !empty($this->calls) || !empty($this->extras);
    }

    /**
     * Get the definition's bindings calls available.
     *
     * @return array<int|string,mixed>
     */
    public function getBindings(): array
    {
        return $this->calls;
    }

    /**
     * Get the definition's parameters.
     *
     * @return array<int|string,mixed>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Get the list of extra php code bindings.
     */
    public function getExtras(): array
    {
        return $this->extras;
    }
}
