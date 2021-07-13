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

use Psr\Container\ContainerInterface;
use Rade\DI\AbstractContainer;
use Rade\DI\Container;
use Rade\DI\Definition;
use Rade\DI\Tests\Fixtures\Prototype\Foo;
use Rade\DI\Tests\Fixtures\Prototype\FooInterface;
use Rade\DI\Tests\Fixtures\Prototype\OtherDir\AnotherSub\DeeperBaz;
use Rade\DI\Tests\Fixtures\Prototype\OtherDir\Baz;
use Rade\DI\Tests\Fixtures\Prototype\Sub\Bar;
use Rade\DI\Tests\Fixtures\Prototype\Sub\BarInterface;
use Rade\DI\Tests\Fixtures\TestFileLoader;
use Symfony\Component\Config\FileLocator;

class FileLoaderTest extends LoaderTestCase
{
    protected static string $fixturesPath;

    public static function setUpBeforeClass(): void
    {
        self::$fixturesPath = \realpath(__DIR__ . '/../');
    }

    /**
     * @dataProvider loadContainers
     */
    public function testRegisterClassesWithInvalidNamespaceEnd(AbstractContainer $container): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('Namespace prefix must end with a "\\": "Rade\DI\Tests\Fixtures\Prototype\Sub".'));

        $loader = new TestFileLoader($container, new FileLocator(self::$fixturesPath . '/Fixtures'));
        $loader->registerClasses(new Definition(null), 'Rade\DI\Tests\Fixtures\Prototype\Sub', 'Prototype/*');
    }

    /**
     * @dataProvider loadContainers
     */
    public function testRegisterClassesWithInvalidNamespaceType(AbstractContainer $container): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('Namespace is not a valid PSR-4 prefix: "0Rade_DI_Tests_Fixtures_Prototype_Sub\\".'));

        $loader = new TestFileLoader($container, new FileLocator(self::$fixturesPath . '/Fixtures'));
        $loader->registerClasses(new Definition(null), '0Rade_DI_Tests_Fixtures_Prototype_Sub\\', 'Prototype/*');
    }

    /**
     * @dataProvider loadContainers
     */
    public function testRegisterClassWithNotFoundParameter(AbstractContainer $container): void
    {
        $this->expectExceptionMessage('You have requested a non-existent parameter "sub_dir".');
        $this->expectException(\RuntimeException::class);

        $loader = new TestFileLoader($container, new FileLocator(self::$fixturesPath . '/Fixtures'));
        $loader->registerClasses(new Definition(null), 'Rade\DI\Tests\Fixtures\Prototype\Sub\\', 'Prototype/%sub_dir%/*');
    }

    /**
     * @dataProvider loadContainers
     */
    public function testRegisterClassWithNonScalarParameter(AbstractContainer $container): void
    {
        $this->expectExceptionMessage('Unable to concatenate non-scalar parameter "sub_dir" into Prototype/%sub_dir%/*.');
        $this->expectException(\InvalidArgumentException::class);

        $container->parameters['sub_dir'] = ['sub', 'dir'];
        $loader = new TestFileLoader($container, new FileLocator(self::$fixturesPath . '/Fixtures'));
        $loader->registerClasses(new Definition(null), 'Rade\DI\Tests\Fixtures\Prototype\Sub\\', 'Prototype/%sub_dir%/*');
    }

    /**
     * @dataProvider loadContainers
     */
    public function testRegisterClasses(AbstractContainer $container): void
    {
        $container->parameters['sub_dir'] = 'Sub';
        $loader = new TestFileLoader($container, new FileLocator(self::$fixturesPath . '/Fixtures'));

        $loader->registerClasses(new Definition(null), 'Rade\DI\Tests\Fixtures\Prototype\Sub\\', 'Prototype/%sub_dir%/*');
        $loader->registerClasses(new Definition(null), 'Rade\DI\Tests\Fixtures\Prototype\Sub\\', 'Prototype/%sub_dir%/*'); // loading twice should not be an issue

        $expectedKeys = [Bar::class];

        if ($container instanceof Container) {
            $this->assertTrue($container->initialized('container'));
            $this->assertTrue($container->typed(ContainerInterface::class));
        } else {
            $this->assertTrue($container->initialized('container'));
            $this->assertTrue($container->has(ContainerInterface::class));
        }

        $this->assertEquals($expectedKeys, $container->keys());
        $this->assertFalse($container->has(BarInterface::class)); // Found if autowired
    }

    /**
     * @dataProvider loadContainers
     */
    public function testRegisterClassesWithExclude(AbstractContainer $container): void
    {
        $container->parameters['other_dir'] = 'OtherDir';
        $loader = new TestFileLoader($container, new FileLocator(self::$fixturesPath . '/Fixtures'));

        $loader->registerClasses(
            new Definition(null),
            'Rade\DI\Tests\Fixtures\Prototype\\',
            'Prototype/*',
            // load everything, except OtherDir/AnotherSub & Foo.php
            'Prototype/{%other_dir%/AnotherSub,Foo.php}'
        );

        $this->assertTrue($container->has(Bar::class));
        $this->assertTrue($container->has(Baz::class));
        $this->assertFalse($container->has(Foo::class));
        $this->assertFalse($container->has(DeeperBaz::class));

        $loader->registerClasses(
            new Definition(null),
            'Rade\DI\Tests\Fixtures\Prototype\\',
            'Prototype/*',
            'Prototype/NotExistingDir'
        );
    }

    /**
     * @dataProvider loadContainers
     */
    public function testRegisterClassesWithExcludeAsArray(AbstractContainer $container): void
    {
        $container->parameters['sub_dir'] = 'Sub';
        $loader = new TestFileLoader($container, new FileLocator(self::$fixturesPath . '/Fixtures'));
        $loader->registerClasses(
            new Definition(null),
            'Rade\DI\Tests\Fixtures\Prototype\\',
            'Prototype/*',
            [
                'Prototype/%sub_dir%',
                'Prototype/OtherDir/AnotherSub/DeeperBaz.php',
            ]
        );

        $this->assertTrue($container->has(Foo::class));
        $this->assertTrue($container->has(Baz::class));
        $this->assertFalse($container->has(Bar::class));
        $this->assertFalse($container->has(DeeperBaz::class));
    }

    /**
     * @dataProvider loadContainers
     */
    public function testNestedRegisterClasses(AbstractContainer $container): void
    {
        $loader = new TestFileLoader($container, new FileLocator(self::$fixturesPath . '/Fixtures'));
        $loader->registerClasses(new Definition(null), 'Rade\DI\Tests\Fixtures\Prototype\\', 'Prototype/*');

        $this->assertTrue($container->has(Bar::class));
        $this->assertTrue($container->has(Baz::class));
        $this->assertTrue($container->has(Foo::class));

        $definition = $container->service(Foo::class);
        $this->assertFalse($container->has(FooInterface::class));
        $this->assertTrue($definition->isPublic());
    }

    /**
     * @dataProvider loadContainers
     */
    public function testMissingParentClass(AbstractContainer $container): void
    {
        $container->parameters['bad_classes_dir'] = 'BadClasses';
        $loader = new TestFileLoader($container, new FileLocator(self::$fixturesPath . '/Fixtures'));

        $loader->registerClasses(
            (new Definition(null))->should(Definition::PRIVATE),
            'Rade\DI\Tests\Fixtures\Prototype\BadClasses\\',
            'Prototype/%bad_classes_dir%/*'
        );

        $this->assertFalse($container->has(MissingParent::class));
    }

    /**
     * @dataProvider loadContainers
     */
    public function testRegisterClassesWithBadPrefix(AbstractContainer $container): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Expected to find class "Rade\\\DI\\\Tests\\\Fixtures\\\Prototype\\\Bar" in file ".+" while importing services from resource "Prototype\/Sub\/\*", but it was not found\! Check the namespace prefix used with the resource/');

        $loader = new TestFileLoader($container, new FileLocator(self::$fixturesPath . '/Fixtures'));

        // the Sub is missing from namespace prefix
        $loader->registerClasses(new Definition(null), 'Rade\DI\Tests\Fixtures\Prototype\\', 'Prototype/Sub/*');
    }

    /**
     * @dataProvider loadContainers
     */
    public function testRegisterClassesWithIncompatibleExclude(AbstractContainer $container): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid "exclude" pattern when importing classes for "Rade\DI\Tests\Fixtures\Prototype\": make sure your "exclude" pattern (yaml/*) is a subset of the "resource" pattern (Prototype/*)');

        $loader = new TestFileLoader($container, new FileLocator(self::$fixturesPath . '/Fixtures'));

        $loader->registerClasses(
            new Definition(null),
            'Rade\DI\Tests\Fixtures\Prototype\\',
            'Prototype/*',
            'yaml/*'
        );
    }

    /**
     * @dataProvider excludeTrailingSlashConsistencyProvider
     */
    public function testExcludeTrailingSlashConsistency(string $exclude): void
    {
        $container = new Container();
        $loader = new TestFileLoader($container, new FileLocator(self::$fixturesPath . '/Fixtures'));
        $loader->registerClasses(
            new Definition(null),
            'Rade\DI\Tests\Fixtures\Prototype\\',
            'Prototype/*',
            $exclude
        );

        $this->assertTrue($container->has(Foo::class));

        if ('Prototype/*/AnotherSub/' !== $exclude) {
            $this->assertFalse($container->has(DeeperBaz::class));
        } else {
            $this->assertTrue($container->has(DeeperBaz::class));
        }
    }

    public function excludeTrailingSlashConsistencyProvider(): iterable
    {
        yield ['Prototype/OtherDir/AnotherSub/'];

        yield ['Prototype/OtherDir/AnotherSub'];

        yield ['Prototype/OtherDir/AnotherSub/*'];

        yield ['Prototype/*/AnotherSub'];

        yield ['Prototype/*/AnotherSub/'];

        yield ['Prototype/*/AnotherSub/*'];

        yield ['Prototype/OtherDir/AnotherSub/DeeperBaz.php'];
    }
}
