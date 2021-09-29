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

namespace Rade\DI\Loader;

use Rade\DI\Definition;
use Rade\DI\Definitions\{ChildDefinition, Reference, Statement, ValueDefinition};

/**
 * Marks a definition from being interpreted as a service.
 *
 * @param mixed $definition from being evaluated
 */
function value($definition): ValueDefinition
{
    return new ValueDefinition($definition);
}

/**
 * Wraps a value as a dynamic typed reference.
 *
 * @param mixed $value
 * @param array<int|string,mixed> $arguments
 */
function wrap($value, array $arguments = []): Statement
{
    return new Statement($value, $arguments);
}

/**
 * Marks a service as being interpreted as a definition.
 *
 * @param mixed $definition being evaluated
 * @param array<int|string,mixed> $args
 */
function service($definition, array $args = []): Definition
{
    return new Definition($definition, $args);
}

/**
 * Represents a registered service id.
 *
 * @param string $id service identifier
 */
function referenced(string $id): Reference
{
    return new Reference($id);
}

/**
 * Represents a parent abstract definition to be extended into.
 */
function parent(string $abstract): ChildDefinition
{
    return new ChildDefinition($abstract);
}
