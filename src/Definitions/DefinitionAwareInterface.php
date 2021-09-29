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

use Rade\DI\AbstractContainer;

/**
 * Attaches container to a definition class, adding features such as tagging.
 *
 * This interface is currently under re-thinking process, and can potentially changed to
 * be (deprecated) for a more stable approach in attaching container to definition class.

 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
interface DefinitionAwareInterface
{
    /**
     * Sets the container.
     */
    public function bindWith(string $id, AbstractContainer $container): void;
}
