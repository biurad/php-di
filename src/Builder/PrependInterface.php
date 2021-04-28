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
 * The interface implemented to adjust DI container before is compiled to PHP class.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
interface PrependInterface
{
    /**
     * This method is called after all services are registered
     * and should be used to register missing services, tags or even
     * extending a service definition.
     */
    public function before(ContainerBuilder $builder): void;
}
