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

use DivineNii\Invoker\ArgumentResolver\DefaultValueResolver;
use DivineNii\Invoker\ArgumentResolver\NamedValueResolver;
use DivineNii\Invoker\ArgumentResolver\TypeHintValueResolver;
use DivineNii\Invoker\Interfaces\ArgumentValueResolverInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Rade\DI\Container;
use Rade\DI\Exceptions\CircularReferenceException;
use Rade\DI\Exceptions\ContainerResolutionException;
use Rade\DI\ScopedDefinition;
use Symfony\Contracts\Service\ServiceProviderInterface;

class ContainerAutowireTest extends TestCase
{
    public function testShouldPassContainerTypeHintAsParameter(): void
    {
        $rade = new Container();
        $rade['service'] = function (): Fixtures\Service {
            return new Fixtures\Service();
        };
        $rade['construct'] = $rade->lazy(Fixtures\Constructor::class);

        $this->assertNotSame($rade, $rade['service']);
        $this->assertSame($rade, $rade['construct']->value);
    }

    public function testShouldPassContainerSubscriberType(): void
    {
        $rade = new Container();
        $rade['baz'] = new Fixtures\SomeServiceSubscriber();
        $this->assertNull($rade['baz']->container);

        $rade['bar'] = (object) ['name' => 'Divine'];
        $rade['foo'] = $rade->lazy(Fixtures\SomeServiceSubscriber::class);

        $this->assertInstanceOf(ServiceProviderInterface::class, $rade['foo']->container);
        $this->assertInstanceOf('stdClass', $rade['foo']->container->get('bar'));
    }

    public function testShouldFailContainerSubscriberType(): void
    {
        $rade = new Container();
        $rade['baz'] = new Fixtures\SomeService();
        $this->assertNull($rade['baz']->container);

        $this->expectExceptionMessage(
            'Service of type Symfony\Contracts\Service\ServiceProviderInterface needs parent class ' .
            'Rade\DI\Tests\Fixtures\SomeService to implement Symfony\Contracts\Service\ServiceSubscriberInterface.'
        );
        $this->expectException(ContainerResolutionException::class);

        $rade['foo'] = $rade->lazy(Fixtures\SomeService::class);
        $rade['foo'];
    }

    public function testShouldPassContainerBuiltinTypeAsParameter(): void
    {
        $this->expectExceptionMessage(
            'Builtin Type \'string\' needed by $container in ' .
            'Rade\DI\Tests\ContainerAutowireTest::Rade\DI\Tests\{closure}() is not supported for autowiring.'
        );
        $this->expectException(ContainerResolutionException::class);

        $rade = new Container();
        $rade['foo'] = function (string $container) {
            return $container;
        };

        $rade['foo'];
    }

    public function testCircularReferenceWithParameter(): void
    {
        $rade = new Container();
        $rade['service'] = function (Fixtures\Service $service): Fixtures\Service {
            return $service;
        };

        $this->expectExceptionMessage('Circular reference detected for service "service", path: "service -> service".');
        $this->expectException(CircularReferenceException::class);

        $rade['service'];
    }

    public function testShouldFailOnMissingService(): void
    {
        $rade = new Container();
        $rade['autowire'] = new Fixtures\ServiceAutowire(new Fixtures\Service(), null);

        $this->expectExceptionMessage(
            'Parameter $service in Rade\DI\Tests\Fixtures\ServiceAutowire::missingService() typehint(s) ' .
            '\'Rade\DI\Tests\Fixtures\Service\' not found, and no default value specified.'
        );
        $this->expectException(ContainerResolutionException::class);

        $rade->call([$rade['autowire'], 'missingService']);
    }

    public function testShouldFailOnIncompleteClassType(): void
    {
        $rade = new Container();
        $rade['autowire'] = new Fixtures\ServiceAutowire(new Fixtures\Service(), null);

        $this->expectExceptionMessage(
            'Type \'Rade\DI\Tests\Fixtures\Servic\' needed by $service in ' .
            'Rade\DI\Tests\Fixtures\ServiceAutowire::missingClass() not found. ' .
            "Check type hint and 'use' statements.",
        );
        $this->expectException(ContainerResolutionException::class);

        $rade->call([$rade['autowire'], 'missingClass']);
    }

    public function testShouldPassOnExcludedType(): void
    {
        $rade = new Container();
        $rade->exclude(Fixtures\Service::class);

        $rade['foo'] = $bound = new Fixtures\Service();
        $rade['bar'] = $rade->lazy(Fixtures\Constructor::class);
        $rade['baz'] = $rade->lazy(Fixtures\ServiceAutowire::class);

        $this->assertSame($bound, $rade->get(Fixtures\Service::class));
        $this->assertSame($bound, $rade['baz']->value);
    }

    public function testShouldFailOExcludedTypeWithServiceParameter(): void
    {
        $rade = new Container();
        $rade->exclude(Fixtures\Service::class);

        $rade['construct'] = $rade->lazy(Fixtures\Constructor::class);
        $rade['service']   = function (Fixtures\Service $container) {
            return $container;
        };

        $this->expectExceptionMessage(
            'Parameter $container in Rade\DI\Tests\ContainerAutowireTest::Rade\DI\Tests\{closure}() typehint(s) ' .
            '\'Rade\DI\Tests\Fixtures\Service\' not found, and no default value specified.'
        );
        $this->expectException(ContainerResolutionException::class);

        $rade['service'];
    }

    /** @test */
    public function shouldFailOnExcludedTypeForMultipleServiceWithSameTypeExceptGetMethod(): void
    {
        $rade = new Container();
        $rade['foo'] = new Fixtures\Service();
        $rade['bar'] = $rade->lazy(Fixtures\Constructor::class);

        $services = $rade->get(Fixtures\Service::class);
        $this->assertIsArray($services);

        foreach ($services as $service) {
            $this->assertInstanceOf(Fixtures\Service::class, $service);
        }
        $this->assertCount(2, $services);

        $this->expectExceptionMessage(
            'Multiple services of type Rade\DI\Tests\Fixtures\Service found: bar, foo. ' .
            '(needed by $service in Rade\DI\Tests\Fixtures\ServiceAutowire::__construct())'
        );
        $this->expectException(ContainerResolutionException::class);

        // On Autowiring parameters
        $rade['baz'] = $rade->lazy(Fixtures\ServiceAutowire::class);
        $rade['baz'];
    }

    public function testShouldFailOnMultipleService(): void
    {
        $rade = new Container();
        $rade['autowire'] = new Fixtures\ServiceAutowire(new Fixtures\Service(), null);

        $rade['name.value'] = $rade->lazy(NamedValueResolver::class);
        $rade['type.value'] = $rade->lazy(TypeHintValueResolver::class);

        $this->expectExceptionMessage(
            'Multiple services of type DivineNii\Invoker\Interfaces\ArgumentValueResolverInterface ' .
            'found: name.value, type.value. (needed by $resolver in ' .
            'Rade\DI\Tests\Fixtures\ServiceAutowire::multipleAutowireTypes())'
        );
        $this->expectException(ContainerResolutionException::class);

        $rade->call([$rade['autowire'], 'multipleAutowireTypes']);
    }

    public function testShouldPassSelectingFromMultipleServiceUsingDocParam(): void
    {
        $rade = new Container();
        $rade['autowire'] = new Fixtures\ServiceAutowire(new Fixtures\Service(), null);

        $rade['name.value'] = $rade->lazy(NamedValueResolver::class);
        $rade['type.value'] = $rade->lazy(TypeHintValueResolver::class);

        $namedResolver = $rade->call([$rade['autowire'], 'multipleAutowireTypesFound']);

        $this->assertInstanceOf(NamedValueResolver::class, $namedResolver);

        $this->expectExceptionMessage(
            'Multiple services of type DivineNii\Invoker\Interfaces\ArgumentValueResolverInterface ' .
            'found: name.value, type.value. (needed by $resolver in ' .
            'Rade\DI\Tests\Fixtures\ServiceAutowire::multipleAutowireTypesNotFound())'
        );
        $this->expectException(ContainerResolutionException::class);

        $rade->call([$rade['autowire'], 'multipleAutowireTypesNotFound']);
    }

    public function testShouldPassOnMultipleServicesOnArray(): void
    {
        $rade = new Container();
        $rade['autowire'] = new Fixtures\ServiceAutowire(new Fixtures\Service(), null);

        $rade['name.value']    = $rade->lazy(NamedValueResolver::class);
        $rade['type.value']    = $rade->lazy(TypeHintValueResolver::class);
        $rade['default.value'] = $rade->lazy(DefaultValueResolver::class);

        $resolvers = $rade->call([$rade['autowire'], 'autowireTypesArray']);

        $this->assertCount(3, $resolvers);

        foreach ($resolvers as $resolver) {
            $this->assertInstanceOf(ArgumentValueResolverInterface::class, $resolver);
        }
    }

    public function testShouldPassOnTypeOnParameter(): void
    {
        $rade = new Container();
        $rade['cl_container'] = $rade->lazy(Fixtures\Constructor::class);
        $rade['fn_container'] = function (ContainerInterface $container) {
            return $container;
        };

        $this->assertEquals($rade, $rade['fn_container']);
        $this->assertEquals($rade, $rade['cl_container']->value);
    }

    public function testShouldPassOnVariadicTypeWithCallMethod(): void
    {
        $rade = new Container();
        $arguments = [new Fixtures\Service(), new Fixtures\Constructor($rade)];
        $service = function (Fixtures\SomeService $obj, Fixtures\Service ...$services) {
            return [$obj, $services];
        };

        $rade['foo'] = $objeActual = new Fixtures\SomeService();
        $rade['service1'] = $rade->lazy(Fixtures\Service::class);
        $rade['service2'] = $rade->lazy(Fixtures\Constructor::class);
        $rade['protected'] = $rade->raw($service);

        [$obj, $services] = $rade->call($rade['protected'], [1 => $arguments]);
        $this->assertInstanceOf(Fixtures\SomeService::class, $obj);
        $this->assertSame($objeActual, $obj);
        $this->assertCount(2, $services);

        [$obj, $services] = $rade->call($service, ['services' => $arguments]);
        $this->assertInstanceOf(Fixtures\SomeService::class, $obj);
        $this->assertSame($objeActual, $obj);
        $this->assertCount(2, $services);
    }

    public function testShouldPassOnVariadicTypeOnService(): void
    {
        $rade = new Container();
        $service = function (Fixtures\SomeService $obj, Fixtures\Service ...$service) {
            return [$obj, $service];
        };

        $rade['foo'] = $objeActual = new Fixtures\SomeService();
        $rade['service1'] = $rade->lazy(Fixtures\Service::class);
        $rade['service2'] = $rade->lazy(Fixtures\Constructor::class);
        $rade['variadic'] = $service;

        [$obj, $services] = $rade['variadic'];
        $this->assertInstanceOf(Fixtures\SomeService::class, $obj);
        $this->assertSame($objeActual, $obj);
        $this->assertCount(2, $services);

        $rade->reset();
        $this->assertFalse(isset($rade['variadic']));

        [$obj, $services] = $rade->call($service, [$objeActual = new Fixtures\SomeService(), new Fixtures\Service()]);
        $this->assertInstanceOf(Fixtures\SomeService::class, $obj);
        $this->assertSame($objeActual, $obj);
        $this->assertCount(1, $services);

        $result = $rade->call(function (Fixtures\Service ...$services) {
            return $services;
        }, [new Fixtures\Constructor($rade)]);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertInstanceOf(Fixtures\Service::class, \current((array) $result));
    }

    public function testShouldFailOnVariadicType(): void
    {
        $rade = new Container();
        $this->assertNull(current((array) $rade->call(fn (...$service) => $service)));

        try {
            $rade->call(new Fixtures\Service());
        } catch (ContainerResolutionException $e) {
            $this->assertEquals('Method Rade\DI\Tests\Fixtures\Service::__invoke() does not exist', $e->getMessage());
        }

        try {
            $rade['foo'] = fn (Fixtures\Service ...$service) => $service;
            $rade['foo'];
        } catch (\TypeError $e) {
            $message = 'Argument 1 passed to Rade\DI\Tests\ContainerAutowireTest::Rade\DI\Tests\{closure}() ' .
            'must be an instance of Rade\DI\Tests\Fixtures\Service, null given';

            if (PHP_VERSION_ID >= 80000) {
                $message = 'Rade\DI\Tests\ContainerAutowireTest::Rade\DI\Tests\{closure}(): ' .
                'Argument #1 must be of type Rade\DI\Tests\Fixtures\Service, null given,';
            }

            $this->assertStringStartsWith($message, $e->getMessage());
        }

        $this->expectExceptionMessage(
            'Builtin Type \'string\' needed by $service in ' .
            'Rade\DI\Tests\ContainerAutowireTest::Rade\DI\Tests\{closure}() is not supported for autowiring.'
        );
        $this->expectException(ContainerResolutionException::class);
        $rade['bar'] = fn (string ...$service) => $service;
        $rade['bar'];
    }

    public function testProtectAndFactoryAutowired(): void
    {
        $rade = new Container();
        $rade->set('service', new Fixtures\Service(), true);

        $callable = [$rade->call(Fixtures\ServiceAutowire::class), 'missingService'];

        $rade['factory'] = $factory = $rade->factory($callable);
        $rade['protect'] = $protect = $rade->raw($callable);

        $this->assertNotSame($factory->service, $rade['factory']);
        $this->assertSame($protect->service, $rade['protect']);
    }

    public function testThatAFallbackContainerSupportAutowiring(): void
    {
        $rade = new Container();
        $rade->fallback(new AppContainer());
        $rade['t_call'] = fn (AppContainer $app) => $app['scoped'];

        $this->assertInstanceOf(ScopedDefinition::class, $one = $rade['scoped']);
        $this->assertSame($one, $rade['t_call']);
        $this->assertSame($rade, $rade->get(ContainerInterface::class));
        $this->assertNotSame($rade[AppContainer::class], $rade->get(ContainerInterface::class));
    }

    public function testContainerAutwoireMethod(): void
    {
        $rade = new Container();
        $rade->set('service', $rade->lazy(Fixtures\Constructor::class));
        $rade->autowire('service', [Fixtures\Service::class]);
        $service = fn (Fixtures\Service $service) => $service;

        $this->assertInstanceOf(Fixtures\Service::class, $one = $rade->get('service'));
        $this->assertSame($one, $rade->get(Fixtures\Service::class));
        $this->assertSame($one, $rade->call($service));
        $this->assertNotSame($one, $rade->get(Fixtures\Constructor::class));

        unset($rade['service']);

        try {
            $rade->call($service);
        } catch (ContainerResolutionException $e) {
            $this->assertEquals(
                $e->getMessage(),
                'Parameter $service in Rade\DI\Tests\ContainerAutowireTest::Rade\DI\Tests\{closure}() typehint(s) ' .
                '\'Rade\DI\Tests\Fixtures\Service\' not found, and no default value specified.'
            );
        }

        $this->expectExceptionMessage('Service id \'service\' is not found in container');
        $this->expectException(ContainerResolutionException::class);

        $rade->autowire('service', [Fixtures\Service::class]);
    }

    public function testAutowiringWithUnionType(): void
    {
        if (PHP_VERSION_ID < 80000) {
            $this->markTestSkipped('Skip test because PHP version is lower than 8');
        }
        require __DIR__ . '/Fixtures/uniontype_classes.php';

        $rade = new Container();
        $rade['collision'] = new Fixtures\CollisionB();

        $this->assertInstanceOf(
            Fixtures\UnionScalars::class,
            $rade->call(Fixtures\UnionScalars::class, [1.0])
        );
        $this->assertInstanceOf(
            Fixtures\UnionNull::class,
            $rade->call(Fixtures\UnionNull::class)
        );

        $this->assertInstanceOf(Fixtures\CollisionA::class, $rade->call($unionFunction, [new Fixtures\CollisionA()]));
        $this->assertInstanceOf(Fixtures\CollisionB::class, $rade->call($unionFunction));

        $rade['foo'] = $rade->lazy(Fixtures\UnionClasses::class);
        $this->assertInstanceOf(
            Fixtures\UnionClasses::class,
            $rade->call(Fixtures\UnionClasses::class)
        );
        $this->assertInstanceOf(
            Fixtures\UnionClasses::class,
            $rade['foo']
        );

        $this->expectExceptionMessage(
            'Parameter $collision in Rade\DI\Tests\Fixtures\UnionClasses::__construct() typehint(s) ' .
            '\'Rade\DI\Tests\Fixtures\CollisionA|Rade\DI\Tests\Fixtures\CollisionB\' ' .
            'not found, and no default value specified.'
        );
        $this->expectException(ContainerResolutionException::class);

        unset($rade['collision'], $rade['foo']);
        $rade['foo'] = $rade->lazy(Fixtures\UnionClasses::class);
        $rade['foo']; // Lazy service definition
    }
}
