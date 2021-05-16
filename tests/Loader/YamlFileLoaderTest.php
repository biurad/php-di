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
use Rade\DI\Builder\CodePrinter;
use Rade\DI\Builder\Reference;
use Rade\DI\Builder\Statement;
use Rade\DI\Loader\YamlFileLoader;
use Rade\DI\Container;
use Rade\DI\ContainerBuilder;
use Rade\DI\Definition;
use Rade\DI\Exceptions\ContainerResolutionException;
use Rade\DI\Exceptions\NotFoundServiceException;
use Rade\DI\Tests\Fixtures;
use Symfony\Component\Config\Exception\FileLocatorFileNotFoundException;
use Symfony\Component\Config\Exception\LoaderLoadException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Config\Resource\GlobResource;

class YamlFileLoaderTest extends LoaderTestCase
{
    protected static string $fixturesPath;

    public static function setUpBeforeClass(): void
    {
        self::$fixturesPath = \realpath(__DIR__ . '/../Fixtures/');
    }

    /**
     * @dataProvider loadContainers
     */
    public function testLoadUnExistFile(AbstractContainer $container): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/The file ".+" does not exist./');

        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/ini'));
        $r = new \ReflectionObject($loader);
        $m = $r->getMethod('loadFile');
        $m->setAccessible(true);

        $m->invoke($loader, 'foo.yml');
    }

    /**
     * @dataProvider loadContainers
     */
    public function testLoadInvalidYamlFile(AbstractContainer $container): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/The file ".+" does not contain valid YAML./');

        $path = self::$fixturesPath . '/ini';
        $loader = new YamlFileLoader($container, new FileLocator($path));
        $r = new \ReflectionObject($loader);
        $m = $r->getMethod('loadFile');
        $m->setAccessible(true);

        $m->invoke($loader, $path . '/parameters.ini');
    }

    /**
     * @dataProvider provideInvalidFiles
     */
    public function testLoadInvalidFile(string $file): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $loader = new YamlFileLoader(new ContainerBuilder(), new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load($file . '.yml');
    }

    public function provideInvalidFiles(): array
    {
        return [
            ['bad_parameters'],
            ['bad_imports'],
            ['bad_import'],
            ['bad_services'],
            ['bad_service'],
            ['bad_calls'],
            ['bad_format'],
        ];
    }

    public function testLoadParameters(): void
    {
        $container = new ContainerBuilder();
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services2.yml');

        $this->assertEquals(['foo' => 'bar', 'mixedcase' => ['MixedCaseKey' => 'value'], 'values' => [true, false, 0, 1000.3, \PHP_INT_MAX], 'bar' => 'foo', 'escape' => '@escapeme', 'foo_bar' => new Reference('foo_bar')], $container->parameters);
    }

    public function testLoadParametersRuntime(): void
    {
        $this->expectExceptionMessage('Identifier "foo_bar" is not defined.');
        $this->expectException(NotFoundServiceException::class);

        $container = new Container();
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services2.yml');
    }

    public function testLoadImports(): void
    {
        $container = new ContainerBuilder();
        $resolver = new LoaderResolver([
            $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml')),
        ]);
        $loader->setResolver($resolver);
        $loader->load('services4.yml');

        $actual = $container->parameters;
        $expected = [
            'foo' => 'bar',
            'values' => [true, false, \PHP_INT_MAX],
            'bar' => '%foo%',
            'escape' => '@escapeme',
            'foo_bar' => new Reference('foo_bar'),
            'mixedcase' => ['MixedCaseKey' => 'value'],
        ];
        $this->assertEquals(\array_keys($expected), \array_keys($actual));
        $this->assertCount(2, $actual['values']);

        // Bad import throws no exception due to ignore_errors value.
        $loader->load('services4_bad_import.yml');

        // Bad import with nonexistent file throws no exception due to ignore_errors: not_found value.
        $loader->load('services4_bad_import_file_not_found.yml');

        try {
            $loader->load('services4_bad_import_with_errors.yml');
            $this->fail('->load() throws a LoaderLoadException if the imported yaml file does not exist');
        } catch (\Exception $e) {
            $this->assertInstanceOf(LoaderLoadException::class, $e);
            $this->assertMatchesRegularExpression(\sprintf('#^The file "%1$s" does not exist \(in: .+\) in %1$s \(which is being imported from ".+%2$s"\)\.$#', 'foo_fake\.yml', 'services4_bad_import_with_errors\.yml'), $e->getMessage());

            $e = $e->getPrevious();

            $this->assertInstanceOf(FileLocatorFileNotFoundException::class, $e);
            $this->assertMatchesRegularExpression(\sprintf('#^The file "%s" does not exist \(in: .+\)\.$#', 'foo_fake\.yml'), $e->getMessage());
        }

        try {
            $loader->load('services4_bad_import_nonvalid.yml');
        } catch (\Exception $e) {
            $this->assertInstanceOf(LoaderLoadException::class, $e);
            $this->assertMatchesRegularExpression(\sprintf('#^The service file ".+%1$s" is not valid\. It should contain an array\. Check your YAML syntax in .+%1$s \(which is being imported from ".+%2$s"\)\.$#', 'nonvalid2\.yml', 'services4_bad_import_nonvalid.yml'), $e->getMessage());

            $e = $e->getPrevious();
            $this->assertInstanceOf(\InvalidArgumentException::class, $e);
            $this->assertMatchesRegularExpression(\sprintf('#^The service file ".+%s" is not valid\. It should contain an array\. Check your YAML syntax\.$#', 'nonvalid2\.yml'), $e->getMessage());
        }
    }

    public function testLoadServices(): void
    {
        $container = new ContainerBuilder();
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services6.yml');
        $services = $this->getServices($container);

        $this->assertArrayHasKey('foo', $services);
        $this->assertTrue($services['not_shared']->isFactory());
        $this->assertInstanceOf(Definition::class, $services['foo']);
        $this->assertEquals('FooClass', $services['foo']->getEntity());
        $this->assertEquals(['foo', new Reference('foo'), [true, false]], $services['arguments']->getParameters());
        $this->assertEquals(['setBaz' => null, 'setBar' => []], $services['method_call1']->getCalls());
        $this->assertEquals(['setBar' => ['foo', new Reference('foo'), [true, false]]], $services['method_call2']->getCalls());
        $this->assertEquals([new Reference('baz'), 'getClass'], $services['callable1']->getEntity());
        $this->assertEquals(['BazClass', 'getInstance'], $services['callable2']->getEntity());
        $this->assertTrue($container->has('alias_for_foo'));
    }

    public function testLoadServicesRuntime(): void
    {
        \class_alias(Fixtures\FooClass::class, 'FooClass');

        $container = new Container();
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services6.yml');
        $services = $this->getServices($container);

        $this->assertArrayHasKey('foo', $services);
        $this->assertTrue($services['not_shared']->isFactory());
        $this->assertInstanceOf(Fixtures\FooClass::class, $services['foo']);
        $this->assertEquals(['foo', $services['foo'], [true, false]], $services['arguments']->getParameters());
        $this->assertEquals(['setBaz' => null, 'setBar' => []], $services['method_call1']->getCalls());
        $this->assertEquals(['setBar' => ['foo', $services['foo'], [true, false]]], $services['method_call2']->getCalls());
        $this->assertEquals([$services['baz'], 'getClass'], $services['callable1']->getEntity());
        $this->assertEquals(['BazClass', 'getInstance'], $services['callable2']->getEntity());
        $this->assertTrue($container->has('alias_for_foo'));
    }

    /**
     * @dataProvider loadContainers
     */
    public function testLoadDeprecatedDefinitionWithoutMessageKey(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('deprecated_definition_without_message.yml');

        $this->assertTrue($container->service('service_without_deprecation_message')->isDeprecated());
        $deprecation = $container->service('service_without_deprecation_message')->get('deprecation');
        $message = 'The "service_without_deprecation_message" service is deprecated. You should stop using it, as it will be removed in the future.';
        $this->assertSame($message, $deprecation['message']);
        $this->assertSame('vendor/package', $deprecation['package']);
        $this->assertSame(1.1, $deprecation['version']);
    }

    /**
     * @dataProvider loadContainers
     */
    public function testServiceProvider(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services10.yml');

        $services = $this->getServices($container);

        $this->assertArrayHasKey('project.service.bar', $services);
        $this->assertArrayHasKey('project.parameter.bar', $container->parameters);
        $this->assertInstanceOf(Fixtures\ProjectServiceProvider::class, $container->provider(Fixtures\ProjectServiceProvider::class));

        $this->assertEquals('BAR', $services['project.service.foo']->getEntity());
        $this->assertEquals('foobar', $container->parameters['project.parameter.foo']); // Overridden by service provider

        try {
            $loader->load('services11.yml');
        } catch (\Exception $e) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $e);
            $this->assertStringStartsWith('There is no extension able to load the configuration for "foobarfoobar" (in', $e->getMessage());
        }
    }

    /**
     * @dataProvider loadContainers
     */
    public function testServiceProviderWithNullConfig(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('null_config.yml');

        $this->assertSame([], $container->parameters['project.configs']);
        $this->assertInstanceOf(Fixtures\ProjectServiceProvider::class, $container->provider(Fixtures\ProjectServiceProvider::class));
    }

    /**
     * @dataProvider loadContainers
     */
    public function testSupports(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator());

        $this->assertFalse($loader->supports(123));
        $this->assertTrue($loader->supports('foo.yml'));
        $this->assertTrue($loader->supports('foo.yaml'));
        $this->assertFalse($loader->supports('foo.foo'));
        $this->assertTrue($loader->supports('with_wrong_ext.xml', 'yml'));
        $this->assertTrue($loader->supports('with_wrong_ext.xml', 'yaml'));
    }

    /**
     * @dataProvider loadContainers
     */
    public function testNonArrayTagsThrowsException(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));

        try {
            $loader->load('badtag1.yml');
        } catch (\Exception $e) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $e);
            $this->assertStringStartsWith('Parameter "tags" must be an array for service', $e->getMessage());
        }
    }

    /**
     * @dataProvider loadContainers
     */
    public function testTagWithStringKeyThrowsException(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));

        try {
            $loader->load('badtag2.yml');
        } catch (\Exception $e) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $e);
            $this->assertStringStartsWith('The "tags" entry for service "foo_service" is invalid, did you forgot a leading dash before', $e->getMessage());
        }
    }

    /**
     * @dataProvider loadContainers
     */
    public function testNameOnlyTagsAreAllowedAsString(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('tag_name_only.yml');

        $this->assertCount(1, $container->tagged('foo', false));
    }

    /**
     * @dataProvider loadContainers
     */
    public function testTagWithAttributeArray(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('tag_attribute_not_scalar.yml');

        $this->assertEquals([['foo_service', ['foo' => 'foo', 'bar' => 'bar']]], $container->tagged('bar', false));
    }

    /**
     * @dataProvider loadContainers
     */
    public function testTagWithNonStringNameThrowsException(AbstractContainer $container): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/The tag name for service ".+" in .+ must be a non-empty string/');

        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('tag_name_no_string.yml');
    }

    /**
     * @dataProvider loadContainers
     */
    public function testInvalidTagsWithDefaults(AbstractContainer $container): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Parameter "tags" must be an array for service "Foo\\\Bar" in ".+services31_invalid_tags\.yml"\. Check your YAML syntax./');

        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services31_invalid_tags.yml');
    }

    /**
     * @dataProvider loadContainers
     */
    public function testUnderscoreServiceId(AbstractContainer $container): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Service names that start with an underscore are reserved. Rename the "_foo" service.');

        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services_underscore.yml');
    }

    public function testLoadYamlOnlyWithKeys(): void
    {
        $container = new ContainerBuilder();
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services21.yml');

        $definition = $container->service('manager');
        $this->assertEquals(['setLogger' => new Reference('logger'), 'setClass' => 'User'], $definition->getCalls());
        $this->assertEquals([true], $definition->getParameters());
        $this->assertEquals([['manager', ['alias' => 'user']]], $container->tagged('manager', false));
    }

    /**
     * @dataProvider loadContainers
     */
    public function testAutowire(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services23.yml');

        $this->assertTrue($container->service('bar_service')->isAutowired());
    }

    /**
     * @dataProvider loadContainers
     */
    public function testClassFromId(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('class_from_id.yml');

        $this->assertEquals(Fixtures\Constructor::class, $container->service(Fixtures\Constructor::class)->getEntity());
        $this->assertTrue($container->service(Fixtures\Constructor::class)->isAutowired());
        $this->assertEquals(Fixtures\Service::class, $container->service(Fixtures\Constructor::class)->getType());
    }

    /**
     * @dataProvider loadContainers
     */
    public function testPrototype(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services_prototype.yml');

        $ids = $container->keys();
        $expectIds = [Fixtures\Prototype\Foo::class, Fixtures\Prototype\Sub\Bar::class];

        if ($container instanceof Container) {
            $expectIds[] = 'container';
        }
        \sort($ids);
        $this->assertSame($expectIds, $ids);

        if ($container instanceof ContainerBuilder) {
            $resources = \array_map('strval', $container->getResources());

            $fixturesDir = \dirname(__DIR__) . \DIRECTORY_SEPARATOR . 'Fixtures' . \DIRECTORY_SEPARATOR;
            $this->assertContains((string) new FileResource($fixturesDir . 'yaml' . \DIRECTORY_SEPARATOR . 'services_prototype.yml'), $resources);

            $prototypeRealPath = \realpath(__DIR__ . \DIRECTORY_SEPARATOR . '..' . \DIRECTORY_SEPARATOR . 'Fixtures' . \DIRECTORY_SEPARATOR . 'Prototype');
            $globResource = new GlobResource(
                $fixturesDir . 'Prototype',
                '',
                true,
                false,
                [
                    \str_replace(\DIRECTORY_SEPARATOR, '/', $prototypeRealPath . \DIRECTORY_SEPARATOR . 'BadClasses') => true,
                    \str_replace(\DIRECTORY_SEPARATOR, '/', $prototypeRealPath . \DIRECTORY_SEPARATOR . 'OtherDir') => true,
                    \str_replace(\DIRECTORY_SEPARATOR, '/', $prototypeRealPath . \DIRECTORY_SEPARATOR . 'SinglyImplementedInterface') => true,
                ]
            );
            $this->assertContains((string) $globResource, $resources);
        }
    }

    /**
     * @dataProvider loadContainers
     */
    public function testPrototypeWithNamespace(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services_prototype_namespace.yml');

        $ids = $container->keys();
        $expectIds = [
            Fixtures\Prototype\OtherDir\Component1\Dir1\Service1::class,
            Fixtures\Prototype\OtherDir\Component1\Dir2\Service2::class,
            Fixtures\Prototype\OtherDir\Component2\Dir1\Service4::class,
            Fixtures\Prototype\OtherDir\Component2\Dir2\Service5::class,
        ];

        if ($container instanceof Container) {
            $expectIds[] = 'container';
        }
        \sort($ids);
        $this->assertSame($expectIds, $ids);

        $this->assertEquals(
            [
                [Fixtures\Prototype\OtherDir\Component1\Dir1\Service1::class, 0],
                [Fixtures\Prototype\OtherDir\Component2\Dir1\Service4::class, 0],
            ],
            $container->tagged('foo', false)
        );
        $this->assertEquals(
            [
                [Fixtures\Prototype\OtherDir\Component1\Dir2\Service2::class, 0],
                [Fixtures\Prototype\OtherDir\Component2\Dir2\Service5::class, 0],
            ],
            $container->tagged('bar', false)
        );
    }

    /**
     * @dataProvider loadContainers
     */
    public function testPrototypeWithNamespaceAndNoResource(AbstractContainer $container): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/A "resource" attribute must be set when the "namespace" attribute is set for service ".+" in .+/');

        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services_prototype_namespace_without_resource.yml');
    }

    /**
     * @dataProvider loadContainers
     */
    public function testDefaults(AbstractContainer $container): void
    {
        if ($container instanceof Container && !\class_exists('Foo', false)) {
            \class_alias(Fixtures\FooClass::class, 'Foo');
        }

        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services28.yml');

        // foo tag is inherited from defaults
        $this->assertEquals([
            ['Acme\\Foo', 0],
            ['with_defaults', 0],
            ['with_null', 0],
            ['no_defaults', 0],
        ], $container->tagged('foo', false));

        if ($container instanceof ContainerBuilder) {
            $this->assertFalse($container->service('with_defaults')->isPublic());
            $this->assertTrue($container->service('with_defaults')->isAutowired());
        }

        $this->assertTrue($container->has('with_defaults_aliased_short'));

        $this->assertTrue($container->service('with_null')->isPublic());
        $this->assertTrue($container->service('no_defaults')->isPublic());

        $this->assertTrue($container->service('with_null')->isAutowired());
        $this->assertFalse($container->service('no_defaults')->isAutowired());
    }

    /**
     * @dataProvider loadContainers
     */
    public function testAnonymousServices(AbstractContainer $container): void
    {
        if ($container instanceof Container) {
            if (!\class_exists('Bar', false)) {
                \class_alias(Fixtures\Service::class, 'Bar');
            }

            if (!\class_exists('FooClass', false)) {
                \class_alias(Fixtures\FooClass::class, 'FooClass');
            }
        }

        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('anonymous_service.yml');

        $definition = $container->service('Foo');

        if ($container instanceof Container) {
            $this->assertInstanceOf(Fixtures\FooClass::class, $definition);
            $this->assertInstanceOf(Fixtures\Service::class, $definition->arguments);
        } else {
            $this->assertTrue($definition->isAutowired());
            $this->assertTrue($definition->isPublic());

            $this->assertInstanceOf(Statement::class, $anonymous = $definition->getEntity());
            $this->assertCount(1, $anonymous->args);
            $this->assertInstanceOf(Statement::class, $anonymous = $anonymous->args[0]);

            $this->assertEquals('Bar', $anonymous->value);
            $this->assertCount(0, $anonymous->args);
        }
    }

    /**
     * @dataProvider loadContainers
     */
    public function testEmptyDefaultsThrowsClearException(AbstractContainer $container): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Service "_defaults" key must be an array, "null" given in ".+bad_empty_defaults\.yml"\./');

        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('bad_empty_defaults.yml');
    }

    /**
     * @dataProvider loadContainers
     */
    public function testUnsupportedKeywordThrowsException(AbstractContainer $container): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^The configuration key "public" is unsupported for definition "bar"/');

        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('bad_keyword.yml');
    }

    /**
     * @dataProvider loadContainers
     */
    public function testCaseSensitivity(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services_case.yml');

        $this->assertTrue($container->has('bar'));
        $this->assertTrue($container->has('BAR'));
        $this->assertFalse($container->has('baR'));

        if ($container instanceof ContainerBuilder) {
            $this->assertSame((string) $container->service('BAR')->getParameters()[0], $container->service('Bar')->getId());
            $this->assertSame((string) $container->service('BAR')->getCalls()['setBar'][0], $container->service('bar')->getId());
        } else {
            $this->assertSame($container->service('BAR')->getParameters()[0], $container->get('Bar'));
            $this->assertSame($container->service('BAR')->getCalls()['setBar'][0], $container->get('bar'));
        }
        $this->assertNotSame($container->get('BAR'), $container->get('bar'));
    }

    /**
     * @dataProvider loadContainers
     */
    public function testBindings(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services_bindings.yml');

        $definition = $container->service('bar');

        $this->assertEquals([
            'NonExistent' => null,
            'quz' => 'quz',
            'factory' => 'factory',
            'foo' => [null],
            'baz' => [],
            '$factory' => [1, 2, 3, 4, 'value'],
        ], $definition->getCalls());
        $this->assertEquals([
            'NonExistent' => null,
            'quz' => 'quz',
            'factory' => 'factory',
        ], $container->service(Fixtures\Bar::class)->getCalls());
        $this->assertEquals(['$service->create(null, $factory);'], $definition->getExtras());

        if ($container instanceof ContainerBuilder) {
            $builder = $container->getBuilder();

            $this->assertEquals(
                \file_get_contents(self::$fixturesPath . '/compiled/service9.phpt'),
                CodePrinter::print([
                    $definition->build($builder)->getNode(),
                    $container->service(Fixtures\Bar::class)->build($builder)->getNode(),
                ], ['spacingLevel' => 4])
            );
        }
    }

    /**
     * @dataProvider loadContainers
     */
    public function testProcessNotExistingActionParam(AbstractContainer $container): void
    {
        $this->expectException(ContainerResolutionException::class);
        $this->expectExceptionMessage('Type \'Rade\DI\Tests\Fixtures\NotExist\' needed by $notExist in Rade\DI\Tests\Fixtures\ConstructNotExists::__construct() not found. Check type hint and \'use\' statements.');

        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services_not_existing.yml');

        if ($container instanceof Container) {
            $container->get(Fixtures\ConstructNotExists::class);
        } else {
            $container->service(Fixtures\ConstructNotExists::class)->build($container->getBuilder());
        }
    }

    /**
     * @dataProvider loadContainers
     *
     * The pass may throw an exception, which will cause the test to fail.
     */
    public function testOverriddenDefaultsBindings(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('defaults_bindings.yml');
        $loader->load('defaults_bindings2.yml');

        $this->assertSame('overridden', $container->service('bar')->getCalls()['quz']);
    }

    /**
     * @dataProvider loadContainers
     */
    public function testSinglyImplementedInterfacesInMultipleResources(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('singly_implemented_interface_in_multiple_resources.yml');

        $this->assertSame(
            $container->get(Fixtures\Prototype\SinglyImplementedInterface\Adapter\Adapter::class),
            $container->get(Fixtures\Prototype\SinglyImplementedInterface\Port\PortInterface::class)
        );
    }

    /**
     * @dataProvider loadContainers
     */
    public function testNotSinglyImplementedInterfacesInMultipleResources(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('not_singly_implemented_interface_in_multiple_resources.yml');

        $this->assertCount(2, $container->get(Fixtures\Prototype\SinglyImplementedInterface\Port\PortInterface::class, 0));
    }

    /**
     * @dataProvider loadContainers
     */
    public function testAlternativeMethodCalls(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('alt_call.yaml');

        $expected = [
            'foo' => [1, 2, 3],
            'url' => null,
        ];

        $this->assertSame($expected, $container->service('foo')->getCalls());
    }

    public function testNamedArguments(): void
    {
        $container = new ContainerBuilder();
        $builder = $container->getBuilder();
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services_named_args.yml');

        $this->assertEquals([null, 'apiKey' => 'ABCD'], $container->service(Fixtures\NamedArgumentsDummy::class)->getParameters());
        $this->assertEquals(['apiKey' => 'ABCD', 'c' => null], $container->service('another_one')->getParameters());

        $this->assertEquals(
            <<<'PHP'
<?php

protected function getRadeDITestsFixturesNamedArgumentsDummy(): Rade\DI\Tests\Fixtures\NamedArgumentsDummy
{
    return self::$services['Rade\\DI\\Tests\\Fixtures\\NamedArgumentsDummy'] = new Rade\DI\Tests\Fixtures\NamedArgumentsDummy(null, 'ABCD', null, $this, []);
}

protected function getAnotherOne(): Rade\DI\Tests\Fixtures\NamedArgumentsDummy
{
    $service = new Rade\DI\Tests\Fixtures\NamedArgumentsDummy(null, 'ABCD', null, $this, []);
    $service->setApiKey('123');

    return self::$services['another_one'] = $service;
}

PHP,
            CodePrinter::print([
                $container->service(Fixtures\NamedArgumentsDummy::class)->build($builder)->getNode(),
                $container->service('another_one')->build($builder)->getNode(),
            ], ['spacingLevel' => 4])
        );
    }
}
