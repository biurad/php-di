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
use Rade\DI\Definitions\Reference;
use Rade\DI\Definitions\Statement;
use Rade\DI\Definitions\TaggedLocator;
use Rade\DI\Loader\YamlFileLoader;
use Rade\DI\Container;
use Rade\DI\ContainerBuilder;
use Rade\DI\Definition;
use Rade\DI\Exceptions\NotFoundServiceException;
use Rade\DI\Tests\Fixtures;
use Symfony\Component\Config\Exception\FileLocatorFileNotFoundException;
use Symfony\Component\Config\Exception\LoaderLoadException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\LoaderResolver;
use Psr\Container\NotFoundExceptionInterface;

/**
 * @group required
 */
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
            ['bad_parameters1'],
            ['bad_imports'],
            ['bad_import'],
            ['bad_services'],
            ['bad_service'],
            ['bad_calls'],
            ['bad_format'],
            ['bad_reference_call'],
            ['bad_reference_call1'],
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
        $this->expectExceptionMessage('The "foo_bar" requested service is not defined in container.');
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
        } catch (LoaderLoadException $e) {
            $this->assertMatchesRegularExpression(\sprintf('#^The service file ".+%1$s" is not valid\. It should contain an array\. Check your YAML syntax in .+%1$s \(which is being imported from ".+%2$s"\)\.$#', 'nonvalid2\.yml', 'services4_bad_import_nonvalid.yml'), $e->getMessage());

            $e = $e->getPrevious();
            $this->assertInstanceOf(\InvalidArgumentException::class, $e);
            $this->assertMatchesRegularExpression(\sprintf('#^The service file ".+%s" is not valid\. It should contain an array\. Check your YAML syntax\.$#', 'nonvalid2\.yml'), $e->getMessage());
        }
    }

    /**
     * @dataProvider loadContainers
     */
    public function testLoadServices(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services6.yml');
        $services = $container->definitions();

        $this->assertArrayHasKey('foo', $services);
        $this->assertFalse($services['not_shared']->isShared());
        $this->assertInstanceOf(Definition::class, $services['foo']);
        $this->assertEquals('FooClass', $services['foo']->getEntity());
        $this->assertEquals(['foo', new Reference('foo'), [true, false]], $services['arguments']->getArguments());
        $this->assertEquals([['setBar',[]], ['setBaz', [null]]], $services['method_call1']->getBindings());
        $this->assertEquals([['setBar', ['foo', new Reference('foo'), [true, false]]]], $services['method_call2']->getBindings());
        $this->assertEquals([new Reference('baz'), 'getClass'], $services['callable1']->getEntity());
        $this->assertEquals(['BazClass', 'getInstance'], $services['callable2']->getEntity());
        $this->assertTrue($container->has('alias_for_foo'));
        $this->assertCount(9, $services);
    }

    /**
     * @dataProvider loadContainers
     */
    public function testLoadDeprecatedDefinitionWithoutMessageKey(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('deprecated_definition_without_message.yml');

        $this->assertTrue($container->definition('service_without_deprecation_message')->isDeprecated());
        $deprecation = $container->definition('service_without_deprecation_message')->getDeprecation();
        $message = 'The "service_without_deprecation_message" service is deprecated. avoid using it, as it will be removed in the future.';
        $this->assertSame($message, $deprecation['message']);
        $this->assertSame('vendor/package', $deprecation['package']);
        $this->assertSame(1.1, $deprecation['version']);
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
            $this->fail('->load() expects to throw an exception if invalid tag values are provided');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringStartsWith('Parameter "tags" must be an array for service', $e->getMessage());
        }
    }

    /**
     * @dataProvider loadContainers
     */
    public function testNameOnlyTagsAreAllowedAsString(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('tag_name_only.yml');

        $this->assertCount(1, $container->tagged('foo'));
    }

    /**
     * @dataProvider loadContainers
     */
    public function testTagWithAttributeArray(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('tag_attribute_not_scalar.yml');

        $this->assertEquals(['foo_service' => ['foo' => 'foo', 'bar' => 'bar']], $container->tagged('bar'));
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

        $definition = $container->definition('manager');
        $this->assertEquals([['setLogger', [new Reference('logger')]], ['setClass', ['User']]], $definition->getBindings());
        $this->assertEquals([true], $definition->getArguments());
        $this->assertEquals(['manager' => ['alias' => 'user']], $container->tagged('manager'));
    }

    /**
     * @dataProvider loadContainers
     */
    public function testAutowire(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services23.yml');

        $this->assertTrue($container->definition('bar_service')->isTyped());
    }

    /**
     * @dataProvider loadContainers
     */
    public function testClassFromId(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('class_from_id.yml');

        $this->assertEquals(Fixtures\Constructor::class, $container->definition(Fixtures\Constructor::class)->getEntity());
        $this->assertTrue($container->definition(Fixtures\Constructor::class)->isTyped());
        $this->assertEquals([Fixtures\Service::class], $container->definition(Fixtures\Constructor::class)->getTypes());
    }

    /**
     * @dataProvider loadContainers
     */
    public function testPrototype(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services_prototype.yml');

        $ids = \array_keys($container->definitions());
        $expectIds = [Fixtures\Prototype\Foo::class, Fixtures\Prototype\Sub\Bar::class];
        \sort($ids);

        $this->assertSame($expectIds, $ids);
    }

    /**
     * @dataProvider loadContainers
     */
    public function testPrototypeWithNamespace(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services_prototype_namespace.yml');

        $ids = \array_keys($container->definitions());
        $expectIds = [
            Fixtures\Prototype\OtherDir\Component1\Dir1\Service1::class,
            Fixtures\Prototype\OtherDir\Component1\Dir2\Service2::class,
            Fixtures\Prototype\OtherDir\Component2\Dir1\Service4::class,
            Fixtures\Prototype\OtherDir\Component2\Dir2\Service5::class,
        ];
        \sort($ids);

        $this->assertSame($expectIds, $ids);

        $this->assertEquals(
            [
                Fixtures\Prototype\OtherDir\Component1\Dir1\Service1::class => true,
                Fixtures\Prototype\OtherDir\Component2\Dir1\Service4::class => true,
            ],
            $container->tagged('foo')
        );
        $this->assertEquals(
            [
                Fixtures\Prototype\OtherDir\Component1\Dir2\Service2::class => true,
                Fixtures\Prototype\OtherDir\Component2\Dir2\Service5::class => true,
            ],
            $container->tagged('bar')
        );
    }

    /**
     * @dataProvider loadContainers
     */
    public function testPrototypeWithInvalidResource(AbstractContainer $container): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/A "resource" attribute must be of type string for service "invalid" in ".+services_prototype_with_invalid_resource\.yml"\. Check your YAML syntax\./');

        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services_prototype_with_invalid_resource.yml');
    }

    /**
     * @dataProvider loadContainers
     */
    public function testPrototypeWithDeprecation(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services_prototype_with_deprecation.yml');

        $ids = \array_keys($container->definitions());
        $expectIds = [Fixtures\Prototype\OtherDir\Component1\Dir1\Service1::class, Fixtures\Prototype\OtherDir\Component2\Dir1\Service4::class];
        \sort($ids);

        $this->assertSame($expectIds, $ids);
        $definition = $container->definition($id = Fixtures\Prototype\OtherDir\Component1\Dir1\Service1::class);

        $this->assertInstanceOf(Definition::class, $definition);
        $this->assertTrue($definition->isDeprecated());
        $this->assertEquals(['package' => 'vendor/package', 'version' => 1.1, 'message' => 'This Rade\DI\Tests\Fixtures\Prototype\OtherDir\Component1\Dir1\Service1 has been deprecated'], $definition->getDeprecation($id));
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
            //'Acme\\Foo' => true,
            'with_defaults' => true,
            'with_null' => true,
            'no_defaults' => true,
        ], $container->tagged('foo'));

        $this->assertFalse($container->definition('with_defaults')->isPublic());
        $this->assertTrue($container->definition('with_defaults')->isTyped());

        $this->assertTrue($container->has('with_defaults_aliased_short'));

        $this->assertTrue($container->definition('with_null')->isPublic());
        $this->assertTrue($container->definition('no_defaults')->isPublic());

        $this->assertTrue($container->definition('with_null')->isTyped());
        $this->assertEquals([Fixtures\Bar::class, Fixtures\BarInterface::class], $container->definition('no_defaults')->getTypes());
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


        $definition = $container->definition('Foo');
        $this->assertEquals('FooClass', $definition->getEntity());
        $this->assertTrue($definition->isPublic());

        $this->assertCount(2, $definition->getArguments());
        $this->assertInstanceOf(Statement::class, $anonymous = $definition->getArguments()[0]);
        $this->assertInstanceOf(TaggedLocator::class, $definition->getArguments()[1]);

        $this->assertEquals('Bar', $anonymous->getValue());
        $this->assertCount(0, $anonymous->getArguments());
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
    public function testEmptyFileThrowsNoException(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('empty.yml');

        $this->assertEmpty($container->definitions());
    }

    /**
     * @dataProvider loadContainers
     */
    public function testFileContentIsNotAnArray(AbstractContainer $container): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/The service file ".+bad_file_contents\.yml" is not valid\. It should contain an array\. Check your YAML syntax\./');

        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('bad_file_contents.yml');
    }

    /**
     * @dataProvider loadContainers
     */
    public function testUnsupportedKeywordThrowsException(AbstractContainer $container): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^The configuration key "private" is unsupported for definition "bar"/');

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

        $this->assertNotEquals($container->get('bar'), $container->get('BAR'));
        $this->assertNotEquals($container->get('bar'), $container->get('baR'));
        $this->assertNotEquals($container->get('BAR'), $container->get('baR'));
    }

    /**
     * @dataProvider loadContainers
     */
    public function testBindings(AbstractContainer $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services_bindings.yml');
        $definition = $container->definition('bar');

        $this->assertEquals([
            ['NonExistent', [null]],
            ['quz', ['quz']],
            ['factory', ['factory']],
            ['foo', [null]],
            ['baz', [[]]],
        ], $definition->getBindings());
        $this->assertEquals([
            ['NonExistent', [null]],
            ['quz', ['quz']],
            ['factory', ['factory']],
        ], $container->definition(Fixtures\Bar::class)->getBindings());
        $this->assertEquals(['factory' => [1, 2, 3, 4, 'value']], $definition->getParameters());
        $this->assertCount(2, $definition->getExtras());
    }

    /**
     * @dataProvider loadContainers
     */
    public function testServiceBindingInvalid(AbstractContainer $container): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Parameter "bind" must be an array for service "bar" in ".+bad_binds_not_array\.yml"\. Check your YAML syntax\./');

        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('bad_binds_not_array.yml');
    }

    /**
     * @dataProvider loadContainers
     */
    public function testProcessNotExistingActionParam(AbstractContainer $container): void
    {
        $this->expectException(NotFoundExceptionInterface::class);
        $this->expectExceptionMessage('The "Rade\DI\Tests\Fixtures\ConstructNotExists" requested service is not defined in container.');

        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services_not_existing.yml');

        $container->get(Fixtures\ConstructNotExists::class);
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

        $this->assertSame(['quz', ['value']], $container->definition('bar')->getBindings()[0]);
        $this->assertSame(['quz', ['overridden']], $container->definition('bar')->getBindings()[4]);
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
            ['foo', [1, 2, 3]],
            ['url', [null]],
        ];

        $this->assertSame($expected, $container->definition('foo')->getBindings());
    }

    /**
     * @dataProvider loadContainers
     */
    public function testServicesWithInvalidTag(AbstractContainer $container): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported tag "!no_tag".');

        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services_with_invalid_tag.yml');
    }

    public function testNamedArguments(): void
    {
        $container = new ContainerBuilder();
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath . '/yaml'));
        $loader->load('services_named_args.yml');

        $this->assertEquals([null, 'apiKey' => 'ABCD'], $container->definition(Fixtures\NamedArgumentsDummy::class)->getArguments());
        $this->assertEquals(['apiKey' => 'ABCD', 'c' => null], $container->definition('another_one')->getArguments());

        $contents = <<<'PHP'
<?php

protected function getRadeDITestsFixturesNamedArgumentsDummy(): Rade\DI\Tests\Fixtures\NamedArgumentsDummy
{
    return $this->services[Rade\DI\Tests\Fixtures\NamedArgumentsDummy::class] = new Rade\DI\Tests\Fixtures\NamedArgumentsDummy(null, 'ABCD', null, $this, []);
}

protected function getAnotherOne(): Rade\DI\Tests\Fixtures\NamedArgumentsDummy
{
    $service = new Rade\DI\Tests\Fixtures\NamedArgumentsDummy(null, 'ABCD', null, $this, []);
    $service->setApiKey(123);
    return $this->services['another_one'] = $service;
}

PHP;

        if (\PHP_VERSION_ID >= 80000) {
            $contents = \str_replace(
                'new Rade\DI\Tests\Fixtures\NamedArgumentsDummy(null, \'ABCD\', null, $this, [])',
                'new Rade\DI\Tests\Fixtures\NamedArgumentsDummy(null, \'ABCD\', null, $this)',
                $contents
            );
        }

        $this->assertEquals(
            $contents,
            CodePrinter::print([
                $container->definition(Fixtures\NamedArgumentsDummy::class)->build(Fixtures\NamedArgumentsDummy::class, $container->getResolver())->getNode(),
                $container->definition('another_one')->build('another_one', $container->getResolver())->getNode(),
            ])
        );
    }
}
