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
 * This interface controls deprecation status of a service definition.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
interface DepreciableDefinitionInterface
{
    /**
     * Whether this definition is deprecated, that means it should not be used anymore.
     *
     * @param string      $package The name of the composer package that is triggering the deprecation
     * @param float|null  $version The version of the package that introduced the deprecation
     * @param string|null $message The deprecation message to use
     *
     * @return $this
     */
    public function deprecate(string $package = '', float $version = null, string $message = null);

    /**
     * Whether this definition is deprecated, that means it should not be called anymore.
     */
    public function isDeprecated(): bool;

    /**
     * Return a non-empty array if definition is deprecated.
     *
     * @param string $id Service id relying on this definition
     *
     * @return array<string,string>
     */
    public function getDeprecation(string $id): array;
}
