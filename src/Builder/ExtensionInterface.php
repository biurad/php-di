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

namespace Rade\DI\Builder;

use Rade\DI\ContainerBuilder;

/**
 * The interface implemented for building services into container.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
interface ExtensionInterface
{
    /**
     * Registers services on the given container.
     *
     * This method should only be used to configure services and parameters.
     * It should not get services.
     */
    public function build(ContainerBuilder $builder): void;

    /**
     * Adjusts DI container before is compiled to PHP class.
     */
    public function prepend(ContainerBuilder $builder): void;
}
