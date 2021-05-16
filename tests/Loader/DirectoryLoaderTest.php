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
use Rade\DI\Config\Loader\DirectoryLoader;
use Rade\DI\Config\Loader\YamlFileLoader;
use Rade\DI\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\LoaderResolver;

class DirectoryLoaderTest extends LoaderTestCase
{
    private static $fixturesPath;

    private ContainerBuilder $container;

    private DirectoryLoader $loader;

    public static function setUpBeforeClass(): void
    {
        self::$fixturesPath = \realpath(__DIR__ . '/../Fixtures/');
    }

    protected function setUp(): void
    {
        $locator = new FileLocator(self::$fixturesPath);
        $this->container = new ContainerBuilder();
        $this->loader = new DirectoryLoader($this->container, $locator);
        $resolver = new LoaderResolver([
            new YamlFileLoader($this->container, $locator),
            $this->loader,
        ]);
        $this->loader->setResolver($resolver);
    }

    public function testDirectoryCanBeLoadedRecursively(): void
    {
        $this->loader->load('directory/');
        $this->assertEquals(['yaml' => 'yaml'], $this->container->parameters);
    }

    public function testImports(): void
    {
        $this->loader->resolve('directory/import/import.yml')->load('directory/import/import.yml');
        $this->assertEquals(['yaml' => 'yaml'], $this->container->parameters);
    }

    public function testExceptionIsRaisedWhenDirectoryDoesNotExist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The file "foo" does not exist (in:');

        $this->loader->load('foo/');
    }

    /**
     * @dataProvider loadContainers
     */
    public function testSupports(AbstractContainer $container): void
    {
        $loader = new DirectoryLoader($container, new FileLocator());

        $this->assertTrue($loader->supports('directory/'));
        $this->assertTrue($loader->supports('directory/'));
        $this->assertFalse($loader->supports('directory'));
        $this->assertTrue($loader->supports('directory', 'directory'));
        $this->assertFalse($loader->supports('directory', 'foo'));
    }
}
