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

use Nette\Utils\Callback;
use PhpParser\Builder\Method;
use PhpParser\BuilderFactory;
use PhpParser\Node\Expr\Assign;
use Rade\DI\Definitions\Statement;
use Rade\DI\Exceptions\ContainerResolutionException;
use Rade\DI\Resolver;

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
     * Set/Replace a method/property binding to service definition.
     *
     * @param string $nameOrMethod A property name prefixed with a $, or a method name
     * @param mixed  $valueOrRef   The value, reference or statement to bind
     *
     * @return $this
     */
    public function bind(string $nameOrMethod, $valueOrRef = null)
    {
        if ('$' === $nameOrMethod[0]) {
            $this->parameters[\substr($nameOrMethod, 1)] = $valueOrRef;
        } else {
            $this->calls[] = [$nameOrMethod, $valueOrRef];
        }

        return $this;
    }

    /**
     * Sets a configurator to call after the service is fully initialized.
     *
     * @param mixed $configurator A PHP function, reference or an array containing a class/Reference and a method to call
     * @param bool $extend If false, the initialized service will not be passed, but it will be resolved inline service.
     *
     * @return $this
     */
    public function call($configurator, bool $extend = true)
    {
        $this->extras[] = [$extend, $configurator];

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
    public function binds(array $bindings)
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
    public function unbind(string $parameterOrMethod)
    {
        foreach ($this->calls as $offset => [$method, $mCall]) {
            if (\in_array($parameterOrMethod, [$offset . $method, $method], true)) {
                unset($this->calls[$offset]);

                break;
            }
        }

        if (\array_key_exists($parameterOrMethod, $this->parameters)) {
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

    /**
     * Resolves bindings for container builder class.
     */
    protected function resolveBinding(Method $defNode, Assign $createdDef, Resolver $resolver, BuilderFactory $builder): void
    {
        foreach ($this->parameters as $parameter => $pValue) {
            $pValue = $resolver->resolve($pValue);

            if ($pValue instanceof \PhpParser\Node\Stmt) {
                throw new ContainerResolutionException(\sprintf('Constructing property "%s" for service "%s" failed, expression not supported.', $parameter, $this->innerId));
            }

            $defNode->addStmt(new Assign($builder->propertyFetch($createdDef->var, $parameter), $pValue));
        }

        foreach ($this->calls as [$method, $mCall]) {
            $mCall = \is_array($mCall) ? $mCall : [$mCall];

            if (\is_string($this->entity) && \method_exists($this->entity, $method)) {
                $mCall = $resolver->autowireArguments(Callback::toReflection([$this->entity, $method]), $mCall);
            } else {
                $mCall = $resolver->resolveArguments($mCall);
            }

            $defNode->addStmt($builder->methodCall($createdDef->var, $method, $mCall));
        }

        if ([] !== $this->extras) {
            foreach ($this->extras as [$extend, $code]) {
                $defNode->addStmt($resolver->resolve($code, $extend ? [$createdDef->var] : []));
            }
        }
    }
}
