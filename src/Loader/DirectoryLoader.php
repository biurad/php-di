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
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Config\Resource\GlobResource;

/**
 * DirectoryLoader is a recursive loader to go through directories.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class DirectoryLoader extends FileLoader
{
    /**
     * {@inheritdoc}
     */
    public function load($file, string $type = null): void
    {
        $file = \rtrim($file, '/');
        $path = $this->locator->locate($file);
        $container = $this->builder->getContainer();

        if ($container instanceof ContainerBuilder) {
            $container->addResource(new FileExistenceResource($file));
            $container->addResource(new GlobResource($path, '/*', false));
        }

        foreach (\scandir($path) as $dir) {
            if ('.' !== $dir[0]) {
                if (\is_dir($path . '/' . $dir)) {
                    $dir .= '/'; // append / to allow recursion
                }

                $this->setCurrentDir($path);
                $this->import($dir, null, false, $path);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function supports($resource, string $type = null)
    {
        if ('directory' === $type) {
            return true;
        }

        return null === $type && \is_string($resource) && '/' === \substr($resource, -1);
    }
}
