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

namespace Rade\DI\Services;

use Rade\DI\Extensions\ExtensionInterface;

/**
 * The interface implemented for building services into container.
 *
 * @deprecated use the ExtensionInterface instead, will be removed in the future.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
interface ServiceProviderInterface extends ExtensionInterface
{
}
