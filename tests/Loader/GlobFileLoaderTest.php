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
use Rade\DI\Config\Loader\GlobFileLoader;
use Rade\DI\ContainerBuilder;
use Rade\DI\Tests\Fixtures\GlobFileLoaderWithoutImport;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\GlobResource;

class GlobFileLoaderTest extends LoaderTestCase
{
    /**
     * @dataProvider loadContainers
     */
    public function testSupports(AbstractContainer $container): void
    {
        $loader = new GlobFileLoader($container, new FileLocator());

        $this->assertTrue($loader->supports('any-path', 'glob'));
        $this->assertFalse($loader->supports('any-path'));
    }

    public function testLoadAddsTheGlobResourceToTheContainer(): void
    {
        $loader = new GlobFileLoaderWithoutImport($container = new ContainerBuilder(), new FileLocator());
        $loader->load(__DIR__.'/../Fixtures/yaml/*');

        $this->assertEquals(new GlobResource(__DIR__.'/../Fixtures/yaml', '/*', false), $container->getResources()[0]);
    }
}
