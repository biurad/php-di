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

namespace Rade\DI\Definitions;

/**
 * Represents a type of dynamic referencing allowing us to resolve a service not defined in the container.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class Statement
{
    /**
     * Statement constructor.
     *
     * @param mixed                   $value
     * @param array<int|string,mixed> $args
     * @param bool $closure Resolved value will be wrapped in a closure
     */
    public function __construct(private $value, private array $args = [], private bool $closure = false)
    {
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * @return array<int|string,mixed>
     */
    public function getArguments(): array
    {
        return $this->args;
    }

    public function isClosureWrappable(): bool
    {
        return $this->closure;
    }
}
