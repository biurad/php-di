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

use Psr\Container\ContainerInterface;
use Rade\DI\Exceptions\NotFoundServiceException;

/**
 * Rade PSR-11 service locator.
 *
 * @author Pascal Luna <skalpa@zetareticuli.org>
 */
class ServiceLocator implements ContainerInterface
{
    private Container $container;

    /** @var array<string,string> */
    private array $aliases = [];

    /**
     * @param Container $container The Container instance used to locate services
     * @param array     $ids       Array of service ids that can be located.
     *                             String keys can be used to define aliases
     */
    public function __construct(Container $container, array $ids)
    {
        $this->container = $container;

        foreach ($ids as $key => $id) {
            $this->aliases[\is_int($key) ? $id : $key] = $id;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get($id)
    {
        if (!isset($this->aliases[$id])) {
            throw new NotFoundServiceException($id);
        }

        return $this->container[$this->aliases[$id]];
    }

    /**
     * {@inheritdoc}
     */
    public function has($id)
    {
        return isset($this->aliases[$id]) && isset($this->container[$this->aliases[$id]]);
    }
}
