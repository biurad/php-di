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

namespace Rade\DI\Extensions;

use Rade\DI\Container;

/**
 * This interface is implement by the service extension providers to
 * adjust the container after extensions have been loaded.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
interface BootExtensionInterface
{
    /**
     * This method is called after all extensions have be loaded.
     * and should be used to register missing services, tags or even extend service definitions.
     */
    public function boot(Container $container): void;
}
