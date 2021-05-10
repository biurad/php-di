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

use Rade\DI\AbstractContainer;
use Rade\DI\Config\Loader\ClosureLoader;

class ClosureLoaderTest extends LoaderTestCase
{
    /**
     * @dataProvider loadContainers
     */
    public function testSupports(AbstractContainer $container): void
    {
        $loader = new ClosureLoader($container);

        $this->assertTrue($loader->supports(function (AbstractContainer $container): void {
        }));
        $this->assertFalse($loader->supports('foo.foo'));
    }

    /**
     * @dataProvider loadContainers
     */
    public function testLoad(AbstractContainer $container): void
    {
        $loader = new ClosureLoader($container);

        $loader->load(function (AbstractContainer $container): void {
            $container->parameters['foo'] = 'foo';
        });

        $this->assertSame(['foo' => 'foo'], $container->parameters);
    }
}
