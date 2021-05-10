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

namespace Rade\DI\Tests\Loader;

use PHPUnit\Framework\TestCase;
use Rade\DI\AbstractContainer;
use Rade\DI\Container;
use Rade\DI\ContainerBuilder;

abstract class LoaderTestCase extends TestCase
{
    /**
     * Load Supported Containers.
     */
    public function loadContainers(): array
    {
        return [
            'Optimize Container' => [new Container()],
            'Compilable Container' => [new ContainerBuilder()],
        ];
    }

    protected function getServices(AbstractContainer $container): array
    {
        $r = new \ReflectionProperty($container, $container instanceof ContainerBuilder ? 'definitions' : 'values');
        $r->setAccessible(true);

        return $r->getValue($container);
    }
}
