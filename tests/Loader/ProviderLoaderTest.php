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
use Rade\DI\ContainerBuilder;
use Rade\DI\Loader\ProviderLoader;
use Rade\DI\Tests\Fixtures\OtherServiceProvider;
use Rade\DI\Tests\Fixtures\RadeServiceProvider;

class ProviderLoaderTest extends LoaderTestCase
{
    /**
     * @dataProvider loadContainers
     */
    public function testLoadMethod(AbstractContainer $container): void
    {
        $config = [
            RadeServiceProvider::class => [
                'rade_provider' => ['hello' => 'Divine'],
                OtherServiceProvider::class => ['great'],
            ],
        ];
        $loader = new ProviderLoader([new RadeServiceProvider()], $config);
        $loader->load($container);

        $this->assertTrue(isset($container->parameters['rade_di']['hello']));
        $this->assertEquals(['great'], $container->parameters['other']);

        $this->assertCount($container instanceof ContainerBuilder ? 3 : 4, $container->keys());
    }
}
