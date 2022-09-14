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
 * This class represents a global parameter from the container.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class Parameter implements \Stringable
{
    public function __construct(private string $parameter, private bool $resolve = false)
    {
    }

    public function isResolvable(): bool
    {
        return $this->resolve;
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string
    {
        return $this->parameter;
    }
}
