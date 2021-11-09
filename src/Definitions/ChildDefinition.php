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
 * Represents a clone of a/an parent/existing service definition.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class ChildDefinition implements \Stringable
{
    private string $parent;

    /**
     * The parent service definition must be cloneable.
     *
     * @param string $parent The id of Definition instance to decorate
     */
    public function __construct(string $parent)
    {
        $this->parent = $parent;
    }

    /**
     * Returns the Definition to inherit from.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->parent;
    }
}
