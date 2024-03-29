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

namespace Rade\DI\Tests\Fixtures;

use Psr\Container\ContainerInterface;
use Symfony\Contracts\Service\ResetInterface;

class SomeService implements ResetInterface
{
    public ?ContainerInterface $container;

    public function __construct(ContainerInterface $provider = null)
    {
        $this->container = $provider;
    }

    public function getFoo()
    {
        return $this->container->get('foo');
    }

    public function reset(): void
    {
        $this->container = null;
    }
}
