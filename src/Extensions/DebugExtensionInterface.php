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

/**
 * Implementing this interface in a extension type class
 * will explicitly allow registering the extension in a dev or prod environment.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
interface DebugExtensionInterface
{
    /**
     * Return true if this extension is for dev mode else false.
     */
    public static function inDevelopment(): bool;
}
