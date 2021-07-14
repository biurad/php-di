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

namespace Rade\DI\Tests;

use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\LNumber;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Rade\DI\ContainerBuilder;
use Rade\DI\Builder\Reference;
use Rade\DI\Builder\Statement;
use Rade\DI\Container;
use Rade\DI\Definition;
use Rade\DI\Exceptions\CircularReferenceException;
use Rade\DI\Exceptions\NotFoundServiceException;
use Rade\DI\Exceptions\ServiceCreationException;

use function Composer\Autoload\includeFile;

class ContainerBuilderTest extends TestCase
{
    private const COMPILED = __DIR__ . '/Fixtures/compiled';

    public function testContainer(): void
    {
        $builder = new ContainerBuilder();

        $this->assertInstanceOf(Variable::class, $builder->get(Container::class));
        $this->assertInstanceOf(Variable::class, $builder->get(ContainerInterface::class));
        $this->assertInstanceOf(Variable::class, $builder->get('container'));
    }

    public function testCompileMethod(): void
    {
        $builder = new ContainerBuilder();
        $builder->set('foo', Fixtures\Service::class);

        $this->assertNotEmpty($builder->compile());
        $this->assertIsString($builder->compile());
        $this->assertIsArray($builder->compile(['printToString' => false]));
    }

    public function testEmptyContainer(): void
    {
        $builder = new ContainerBuilder();

        $this->assertStringEqualsFile($path = self::COMPILED . '/service2.phpt', $builder->compile(['containerClass' => 'EmptyContainer']));
        includeFile($path);

        $container = new \EmptyContainer();
        $this->assertEquals([], $container->keys());
    }

    public function testRawDefinition(): void
    {
        $builder = new ContainerBuilder();
        $builder->set('raw', $builder->raw(123));
        $builder->set('service1', Fixtures\Service::class)->bind('value', new Reference('raw'));

        try {
            $builder->extend('raw');
        } catch (ServiceCreationException $e) {
            $this->assertEquals('Extending a raw definition for "raw" is not supported.', $e->getMessage());
        }

        try {
            $builder->autowire('raw1', $builder->raw(123));
        } catch (ServiceCreationException $e) {
            $this->assertEquals('Service "raw1" using "Rade\DI\RawDefinition" instance is not supported for autowiring.', $e->getMessage());
        }

        $this->assertInstanceOf(LNumber::class, $builder->get('raw'));
        $this->assertStringEqualsFile($path = self::COMPILED . '/service7.phpt', $builder->compile(['containerClass' => 'RawContainer']));

        includeFile($path);
        $container = new \RawContainer();

        $this->assertEquals(123, $service = $container->get('raw'));
        $this->assertEquals($service, $container->get('service1')->value);
    }

    public function testDefinitionDeprecation(): void
    {
        $builder = new ContainerBuilder();
        $def = $builder->set('deprecate_service', Fixtures\Service::class)->deprecate();

        $deprecation = $builder->service('deprecate_service')->get('deprecation');
        $message = 'The "deprecate_service" service is deprecated. You should stop using it, as it will be removed in the future.';
        $this->assertSame($message, $deprecation['message']);

        $this->assertTrue($def->isDeprecated());
        $this->assertStringEqualsFile($path = self::COMPILED . '/service8.phpt', $builder->compile(['containerClass' => 'DeprecatedContainer']));

        includeFile($path);

        $container = new \DeprecatedContainer();
        $container->get('deprecate_service');

        $this->assertEquals([
            'type' => \E_USER_DEPRECATED,
            'message' => 'The "deprecate_service" service is deprecated. You should stop using it, as it will be removed in the future.',
        ], array_intersect_key(\error_get_last(), ['type' => true, 'message' => true]));
    }

    public function testDefinitionClassWithProvidedParameter(): void
    {
        $builder = new ContainerBuilder();
        \class_alias(Fixtures\Service::class, 'NonExistent');

        $builder->set('bar', Fixtures\Bar::class)->args(['NonExistent' => new Statement('NonExistent'), 'value', 'foo' => [1, 2, 3]]);

        $this->assertStringEqualsFile(\sprintf(self::COMPILED . '/service10_%s.phpt', PHP_VERSION_ID >= 80000 ? 1 : 2), $builder->compile());
    }

    public function testDefinition(): void
    {
        $builder = new ContainerBuilder();

        $builder->set('service_1', Fixtures\Service::class);
        $builder->autowire('autowired', Fixtures\Service::class);
        $builder->set('statement', new Statement(Fixtures\Constructor::class, [new Reference(Container::class)]));

        $this->assertInstanceOf(Coalesce::class, $builder->get('service_1'));
        $this->assertInstanceOf(Coalesce::class, $builder->get('autowired'));
        $this->assertInstanceOf(Coalesce::class, $builder->get(Fixtures\Service::class));
        $this->assertInstanceOf(Coalesce::class, $builder->get('statement'));

        $this->assertStringEqualsFile($path = self::COMPILED . '/service1.phpt', $builder->compile());
        includeFile($path);

        $container = new \CompiledContainer();

        $this->assertInstanceOf(Fixtures\Service::class, $container->get('service_1'));
        $this->assertInstanceOf(Fixtures\Constructor::class, $container->get('statement'));

        $this->assertInstanceOf(Fixtures\Service::class, $service1 = $container->get('autowired'));
        $this->assertInstanceOf(Fixtures\Service::class, $service2 = $container->get(Fixtures\Service::class));
        $this->assertSame($service1, $service2);
    }

    public function testFactoryDefinition(): void
    {
        $builder = new ContainerBuilder();

        $builder->set('service_1', Fixtures\Service::class)->should();
        $builder->set('service_2', Fixtures\Service::class)->should(Definition::FACTORY | Definition::LAZY);

        $this->assertStringEqualsFile($path = self::COMPILED . '/service4.phpt', $builder->compile(['containerClass' => 'FactoryContainer']));
        includeFile($path);

        $container = new \FactoryContainer();
        $this->assertNotSame($container->get('service_1'), $container->get('service_2'));
        $this->assertNotSame($container->get('service_1'), $container->get('service_1'));
        $this->assertNotSame($container->get('service_2'), $container->get('service_2'));
    }

    public function testPrivateDefinition(): void
    {
        $builder = new ContainerBuilder();

        $builder->set('service_1', Fixtures\Service::class)->should(Definition::PRIVATE);
        $builder->set('service_2', Fixtures\Service::class)->should(Definition::PRIVATE | Definition::LAZY);
        $builder->set('service_3', Fixtures\Service::class)->should(Definition::FACTORY | Definition::PRIVATE);

        $this->assertInstanceOf(Coalesce::class, $builder->get('service_1'));
        $this->assertInstanceOf(Coalesce::class, $builder->get('service_2'));
        $this->assertInstanceOf(MethodCall::class, $builder->get('service_3'));

        $this->assertStringEqualsFile($path = self::COMPILED . '/service5.phpt', $builder->compile(['containerClass' => 'PrivateContainer']));
        includeFile($path);

        $container = new \PrivateContainer();

        $this->assertFalse($container->has('service_1'));
        $this->assertFalse($container->has('service_2'));

        $this->expectExceptionMessage('Identifier "service_3" is not defined.');
        $this->expectException(NotFoundServiceException::class);

        $container->get('service_3');
    }

    public function testLazyDefinition(): void
    {
        $builder = new ContainerBuilder();

        $builder->set('service_test', Fixtures\Constructor::class)->bind('value', new Reference('service_4'));
        $builder->set('service_1', Fixtures\Service::class)->should(Definition::LAZY);
        $builder->set('service_2', Fixtures\Service::class)->should(Definition::LAZY | Definition::FACTORY);
        $builder->set('service_3', Fixtures\Service::class)->should(Definition::LAZY | Definition::PRIVATE);
        $builder->autowire('service_4', Fixtures\Service::class)->should(Definition::LAZY | Definition::PRIVATE | Definition::FACTORY);

        $this->assertInstanceOf(Coalesce::class, $builder->get('service_1'));
        $this->assertInstanceOf(MethodCall::class, $builder->get('service_2'));
        $this->assertInstanceOf(Coalesce::class, $builder->get('service_3'));
        $this->assertInstanceOf(MethodCall::class, $service1 = $builder->get('service_4'));
        $this->assertSame($service1, $builder->get(Fixtures\Service::class));

        $this->assertStringEqualsFile($path = self::COMPILED . '/service3.phpt', $builder->compile(['containerClass' => 'LazyContainer']));
        includeFile($path);

        $container = new \LazyContainer();

        $this->assertNotSame($service = $container->get('service_test'), $same = $container->get('service_1'));
        $this->assertNotSame($service, $container->get('service_2'));
        $this->assertSame($same, $container->get('service_1'));

        $this->expectExceptionMessage('Identifier "service_3" is not defined.');
        $this->expectException(NotFoundServiceException::class);

        $container->get('service_3');
    }

    public function testInjectableServices(): void
    {
        $builder = new ContainerBuilder();
        $builder->set('bar', Fixtures\Constructor::class);
        $builder->type('bar', Fixtures\Constructor::class);
        $builder->autowire('foo', Fixtures\FooClass::class)->arg('inject', true);
        $builder->set('inject', Fixtures\InjectableClass::class);

        $this->assertStringEqualsFile($path = self::COMPILED . \sprintf('/service1%s.phpt', PHP_VERSION_ID >= 80000 ? 0 : 1), $builder->compile(['containerClass' => 'InjectableContainer']));

        if (\PHP_VERSION_ID >= 80000) {
            includeFile($path);
            $container = new \InjectableContainer();

            $this->assertInstanceOf(Fixtures\InjectableClass::class, $inject = $container->resolveClass(Fixtures\InjectableClass::class));
            $this->assertInstanceOf(Fixtures\Service::class, $inject->getService());
            $this->assertInstanceOf(Fixtures\FooClass::class, $inject->getFooClass());
        }
    }

    public function testFluentRegister(): void
    {
        $builder = new ContainerBuilder();

        $builder->register($provider1 = new Fixtures\RadeServiceProvider(), ['hello' => 'Divine']);
        $this->assertInstanceOf(Fixtures\RadeServiceProvider::class, $provider2 = $builder->provider(Fixtures\RadeServiceProvider::class));
        $this->assertSame($provider1, $provider2);

        $this->assertTrue(isset($builder->parameters['rade_di']['hello']));
        $this->assertArrayHasKey('other', $builder->parameters);
        $this->assertCount(3, $builder->keys());
        $this->assertEmpty($builder->service('service')->getCalls());

        $builder->compile();
        $calls = $builder->service('service')->getCalls();

        $this->assertInstanceOf(Variable::class, $container = $calls['value']);
        $this->assertEquals('this', $container->name);
    }

    public function testIndirectCircularReference(): void
    {
        $builder = new ContainerBuilder();

        $builder->set('a', Fixtures\ServiceAutowire::class)->args([new Reference('b')]);
        $builder->set('b', Fixtures\ServiceAutowire::class)->args([new Reference('c')]);
        $builder->set('c', Fixtures\ServiceAutowire::class)->args([new Reference('a')]);

        $this->expectExceptionMessage('Circular reference detected for service "a", path: "a -> b -> c -> a".');
        $this->expectException(CircularReferenceException::class);

        $builder->get('a');
    }

    public function testIndirectDeepCircularReference(): void
    {
        $builder = new ContainerBuilder();

        $builder->set('a', Fixtures\ServiceAutowire::class)->args([new Reference('b')]);
        $builder->set('b', [new Reference('c'), 'getInstance']);
        $builder->set('c', Fixtures\ServiceAutowire::class)->args([new Reference('a')]);

        $this->expectExceptionMessage('Circular reference detected for service "a", path: "a -> b -> c -> a".');
        $this->expectException(CircularReferenceException::class);

        $builder->get('a');
    }

    public function testDeepCircularReference(): void
    {
        $builder = new ContainerBuilder();

        $builder->set('a', Fixtures\ServiceAutowire::class)->args([new Reference('b')]);
        $builder->set('b', Fixtures\ServiceAutowire::class)->args([new Reference('c')]);
        $builder->set('c', Fixtures\ServiceAutowire::class)->args([new Reference('b')]);

        $this->expectExceptionMessage('Circular reference detected for service "b", path: "a -> b -> c -> b".');
        $this->expectException(CircularReferenceException::class);

        $builder->get('a');
    }

    public function testCircularReferenceWithCallableAlike(): void
    {
        $builder = new ContainerBuilder();

        $builder->set('a', [new Reference('b'), 'getInstance']);
        $builder->set('b', [new Reference('a'), 'getInstance']);

        $this->expectExceptionMessage('Circular reference detected for service "a", path: "a -> b -> a".');
        $this->expectException(CircularReferenceException::class);

        $builder->get('a');
    }

    public function testCircularReferenceChecksMethodsCalls(): void
    {
        $builder = new ContainerBuilder();

        $builder->autowire('a', Fixtures\Constructor::class)->args([new Reference('b')]);
        $builder->set('b', Fixtures\ServiceAutowire::class)->bind('missingService', new Reference('a'));

        $this->expectExceptionMessage('Circular reference detected for service "a", path: "a -> b -> a".');
        $this->expectException(CircularReferenceException::class);

        $builder->get('a');
    }

    public function testCircularReferenceChecksLazyServices(): void
    {
        $builder = new ContainerBuilder();

        $builder->set('a', Fixtures\ServiceAutowire::class)->args([new Reference('b')])->should(Definition::LAZY);
        $builder->set('b', Fixtures\ServiceAutowire::class)->args([new Reference('a')]);

        $this->expectExceptionMessage('Circular reference detected for service "a", path: "a -> b -> a".');
        $this->expectException(CircularReferenceException::class);

        // Unless no arguments are provided, circular referencing is ignored
        $builder->get('a');
    }

    public function testAlias(): void
    {
        $builder = new ContainerBuilder();

        $builder->set('service_1', Fixtures\Service::class);
        $builder->set('service_2', $builder->raw(123));
        $builder->set('service_3', Fixtures\Service::class)->should(Definition::FACTORY);
        $builder->set('service_4', Fixtures\Service::class)->should(Definition::PRIVATE);
        $builder->autowire('service_5', Fixtures\Service::class)->should(Definition::LAZY | Definition::PRIVATE);
        $builder->autowire('service_6', Fixtures\Service::class);

        $builder->alias('alias_1', 'service_1');
        $builder->alias('alias_2', 'service_2');
        $builder->alias('alias_3', 'service_3');
        $builder->alias('alias_4', 'service_4');
        $builder->alias('alias_5', 'service_5');
        $builder->alias('alias_6', 'service_6');

        $this->assertEquals(['service_1', 'service_2', 'service_3', 'service_4', 'service_5', 'service_6'], $builder->keys());
        $this->assertStringEqualsFile($path = self::COMPILED . '/service6.phpt', $builder->compile(['containerClass' => 'AliasContainer']));

        includeFile($path);

        $container = new \AliasContainer();

        $this->assertFalse($container->aliased('service_5'));
        $this->assertTrue($container->aliased('service_6'));
        $this->assertTrue($container->has('alias_1'));
        $this->assertTrue($container->has('alias_2'));
        $this->assertTrue($container->has('alias_3'));
        $this->assertFalse($container->has('alias_4'));
        $this->assertFalse($container->has('alias_5'));
        $this->assertTrue($container->has('alias_6'));

        $this->assertEquals(['service_1', 'service_2', 'service_3', 'service_6'], $container->keys());
    }

    public function testCallMethod(): void
    {
        $this->expectExceptionObject(new ServiceCreationException(\sprintf('Refactor your code to use %s class instead.', Statement::class)));

        $builder = new ContainerBuilder();
        $builder->call(Fixtures\Service::class);
    }

    public function testRemovedService(): void
    {
        $builder = new ContainerBuilder();
        $builder->set(Fixtures\Service::class, new Definition(Fixtures\Service::class));

        $this->assertTrue($builder->has(Fixtures\Service::class));
        $this->expectExceptionObject(new NotFoundServiceException('Identifier "Rade\DI\Tests\Fixtures\Service" is not defined.'));

        $builder->remove(Fixtures\Service::class);
        $builder->get(Fixtures\Service::class);
    }
}
