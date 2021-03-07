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

use Rade\DI\Exceptions\ContainerResolutionException;

/**
 * A scope for non-shareable, non-resolveable and lazy class-string service definiton.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
final class ScopedDefinition
{
    /** Non resolveable service definition */
    public const RAW = 'raw';

    /** Non shareable service definition */
    public const FACTORY = 'factories';

    /** A class property which can be found in container */
    public string $property;

    /** @var mixed A definition that suite the $property used in container */
    public $service;

    /**
     * @param mixed $definition
     */
    public function __construct($definition, string $type = self::FACTORY)
    {
        if ($definition instanceof self) {
            throw new ContainerResolutionException('Scoped definition cannot be a definiton of itself.');
        }

        $this->property = $type;
        $this->service  = $definition;
    }
}
