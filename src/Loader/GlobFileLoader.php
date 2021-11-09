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

use Rade\DI\ContainerBuilder;

/**
 * GlobFileLoader loads files from a glob pattern.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class GlobFileLoader extends FileLoader
{
    /**
     * {@inheritdoc}
     */
    public function load($resource, string $type = null): void
    {
        $container = $this->builder->getContainer();

        foreach ($this->glob($resource, false, $globResource) as $path => $info) {
            $this->import($path);
        }

        if ($container instanceof ContainerBuilder) {
            $container->addResource($globResource);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function supports($resource, string $type = null)
    {
        return 'glob' === $type;
    }
}
