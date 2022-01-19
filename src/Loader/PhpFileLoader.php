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

use Psr\Container\ContainerInterface;
use Rade\DI\{AbstractContainer, ContainerBuilder, DefinitionBuilder};
use Symfony\Component\Config\Resource\{FileExistenceResource, FileResource};

/**
 * PhpFileLoader loads service definitions from a PHP file.
 *
 * @experimental in 1.0
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class PhpFileLoader extends FileLoader
{
    /**
     * {@inheritdoc}
     */
    public function load($resource, string $type = null): void
    {
        $container = $this->builder->getContainer();

        $path = $this->locator->locate($resource);
        $this->setCurrentDir($dir = \dirname($path));
        $this->builder->directory($dir);

        if ($container instanceof ContainerBuilder) {
            $container->addResource(new FileExistenceResource($path));
            $container->addResource(new FileResource($path));
        }

        $ref = new \ReflectionFunction(include $path);
        $arguments = [];

        foreach ($ref->getParameters() as $offset => $parameter) {
            $reflectionType = $parameter->getType();

            if (!$reflectionType instanceof \ReflectionNamedType) {
                throw new \InvalidArgumentException(\sprintf('Could not resolve argument "$%s" for "%s". You must typehint it (for example with "%s" or "%s").', $parameter->getName(), $path, DefinitionBuilder::class, AbstractContainer::class));
            }

            $type = $reflectionType->getName();

            if (DefinitionBuilder::class === $type) {
                $arguments[$offset] = $this->builder;
            } elseif (\is_subclass_of($type, ContainerInterface::class)) {
                $arguments[$offset] = $container;
            } elseif (\is_subclass_of($type, FileLoader::class)) {
                $arguments[$offset] = $this;
            } else {
                throw new \InvalidArgumentException(\sprintf('Could not resolve argument "%s" for "%s".', $type . ' $' . $parameter->getName(), $path));
            }
        }

        $ref->invokeArgs($arguments);
    }

    /**
     * {@inheritdoc}
     */
    public function supports(mixed $resource, string $type = null): bool
    {
        if (!\is_string($resource)) {
            return false;
        }

        if (null === $type && 'php' === \pathinfo($resource, \PATHINFO_EXTENSION)) {
            return true;
        }

        return 'php' === $type;
    }
}
