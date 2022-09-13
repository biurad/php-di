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

namespace Rade\DI\Loader;

use Rade\DI\{Container, DefinitionBuilder};
use Symfony\Component\Config\FileLocatorInterface;
use Symfony\Component\Config\Loader\FileLoader as BaseFileLoader;

abstract class FileLoader extends BaseFileLoader
{
    protected DefinitionBuilder $builder;

    public function __construct(Container $container, FileLocatorInterface $locator)
    {
        $this->builder = new DefinitionBuilder($container);
        parent::__construct($locator);
    }
}
