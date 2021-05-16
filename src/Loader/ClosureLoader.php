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

use Rade\DI\AbstractContainer;
use Rade\DI\Container;
use Rade\DI\ContainerBuilder;
use Symfony\Component\Config\Loader\Loader;

/**
 * ClosureLoader loads service definitions from a PHP closure.
 *
 * The Closure has access to the container as its first argument.
 *
 * @author Divine Niiquaye Ibok <divineibok@gmail.com>
 */
class ClosureLoader extends Loader
{
    /** @var Container|ContainerBuilder */
    private AbstractContainer $container;

    public function __construct(AbstractContainer $container)
    {
        $this->container = $container;
    }

    /**
     * {@inheritdoc}
     */
    public function load($resource, string $type = null): void
    {
        $resource($this->container);
    }

    /**
     * {@inheritdoc}
     */
    public function supports($resource, string $type = null)
    {
        return $resource instanceof \Closure;
    }
}
