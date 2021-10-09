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
 * If a service definition support types hinting, this interface
 * should be included in implementation.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
interface TypedDefinitionInterface
{
    /**
     * Enables autowiring.
     *
     * @param array<int,string> $types
     *
     * @return $this
     */
    public function autowire(array $types = []);

    /**
     * Represents a PHP type-hinted for this definition.
     *
     * @return $this
     */
    public function typed(array $to);

    /**
     * Whether definition has been typed hinted.
     */
    public function isTyped(): bool;

    /**
     * Get the return types for service definition.
     *
     * @return array<int|string,mixed>
     */
    public function getTypes(): array;
}
