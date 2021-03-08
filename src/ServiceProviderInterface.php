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

/**
 * Rade service provider interface.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
interface ServiceProviderInterface
{
    /**
     * This should be set if Symfony's config interface
     * is extended to, and also set in TreeBuilder instance.
     * 
     * @var null|string Unique name for a given provider
     */
    public const NAME = null;

    /**
     * Registers services on the given container.
     *
     * This method should only be used to configure services and parameters.
     * It should not get services.
     *
     * @param Container $rade A container instance
     */
    public function register(Container $rade): void;
}
