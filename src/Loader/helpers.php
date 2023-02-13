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

use Rade\DI\Builder\PhpLiteral;
use Rade\DI\Definition;
use Rade\DI\Definitions\{Parameter, Reference, Statement, TaggedLocator};


/**
 * Wraps a raw value as a definition.
 *
 * @param bool $closure Resolved value will be wrapped in a closure
 */
function value(mixed $value, $closure = false): Statement
{
    return Statement::rawValue($value, $closure);
}

/**
 * Wraps a value as a dynamic typed reference.
 *
 * @param array<int|string,mixed> $arguments
 * @param bool $closure Resolved value will be wrapped in a closure
 */
function wrap(mixed $value, array $arguments = [], $closure = false): Statement
{
    return new Statement($value, $arguments, $closure);
}

/**
 * Marks a service as being interpreted as a definition.
 *
 * @param mixed $definition being evaluated
 * @param array<int|string,mixed> $args
 */
function service(mixed $definition, array $args = []): Definition
{
    return new Definition($definition, $args);
}

/**
 * Represents a registered service id.
 *
 * @param string $id service identifier
 */
function reference(string $id): Reference
{
    return new Reference($id);
}

/**
 * Represents a registered parameter id.
 *
 * @param string $id parameter identifier
 */
function param(string $id, bool $resolve = false): Parameter
{
    return new Parameter($id, $resolve);
}

/**
 * Creates a lazy iterator by tag name.
 *
 * @param string,array<int,string> $exclude
 */
function tagged(string $tag, string $indexAttribute = null, $exclude = []): TaggedLocator
{
    return new TaggedLocator($tag, $indexAttribute, false, (array) $exclude);
}

/**
 * Represent a php code which will be parsed into ast.
 *
 * @param array<int,mixed> $args
 */
function phpCode(string $phpCode, array $args = []): PhpLiteral
{
    return new PhpLiteral($phpCode, $args);
}
