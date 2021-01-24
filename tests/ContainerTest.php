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

use DivineNii\Invoker\ArgumentResolver\ClassValueResolver;
use DivineNii\Invoker\ArgumentResolver\DefaultValueResolver;
use DivineNii\Invoker\ArgumentResolver\NamedValueResolver;
use DivineNii\Invoker\ArgumentResolver\TypeHintValueResolver;
use DivineNii\Invoker\Interfaces\ArgumentValueResolverInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Rade\DI\Container;
use Rade\DI\Exceptions\CircularReferenceException;
use Rade\DI\Exceptions\ContainerResolutionException;
use Rade\DI\Exceptions\FrozenServiceException;
use Rade\DI\Exceptions\NotFoundServiceException;
use Rade\DI\ServiceProviderInterface;
use ReflectionProperty;
use TypeError;

class ContainerTest extends TestCase
{
    public function testWithString(): void
    {
        $rade          = new Container();
        $rade['param'] = 'value';

        $this->assertEquals('value', $rade['param']);
    }

    public function testWithClosure(): void
    {
        $rade            = new Container();
        $rade['service'] = function () {
            return new Fixtures\Service();
        };

        $this->assertInstanceOf(Fixtures\Service::class, $rade['service']);
    }

    public function testServicesShouldBeDifferent(): void
    {
        $rade            = new Container();
        $rade['service'] = $rade->factory(function () {
            return new Fixtures\Service();
        });

        $serviceOne = $rade['service'];
        $this->assertInstanceOf(Fixtures\Service::class, $serviceOne);

        $serviceTwo = $rade['service'];
        $this->assertInstanceOf(Fixtures\Service::class, $serviceTwo);

        $this->assertNotSame($serviceOne, $serviceTwo);
    }

    public function testShouldPassContainerTypeHintAsParameter(): void
    {
        $rade = new Container();

        $rade['service'] = function () {
            return new Fixtures\Service();
        };
        $rade['construct'] = Fixtures\Constructor::class;

        $rade['container1'] = function (Container $container) {
            return $container;
        };

        $this->assertNotSame($rade, $rade['service']);
        $this->assertSame($rade, $rade['construct']->value);
        $this->assertSame($rade, $rade['container1']);
    }

    public function testShouldPassContainerBuiltinTypeAsParameter(): void
    {
        $rade  = new Container();

        $this->expectExceptionMessage(
            'Parameter $container in Rade\DI\Tests\ContainerTest::Rade\DI\Tests\{closure}() ' .
            'has no class type hint or default value, so its value must be specified.'
        );
        $this->expectException(ContainerResolutionException::class);

        $rade['container1'] = function (string $container) {
            return $container;
        };

        $rade['container1'];
    }

    public function testIsset(): void
    {
        $rade            = new Container();
        $rade['param']   = 'value';
        $rade['service'] = function () {
            return new Fixtures\Service();
        };

        $rade['null'] = null;

        $this->assertTrue(isset($rade['param']));
        $this->assertTrue(isset($rade['service']));
        $this->assertTrue(isset($rade['null']));
        $this->assertFalse(isset($rade['non_existent']));
    }

    public function testOffsetGetValidatesKeyIsPresent(): void
    {
        $this->expectException(NotFoundServiceException::class);
        $this->expectExceptionMessage('Identifier "foo" is not defined.');

        $rade = new Container();
        echo $rade['foo'];
    }

    public function testOffsetGetHonorsNullValues(): void
    {
        $rade        = new Container();
        $rade['foo'] = null;
        $this->assertNull($rade['foo']);
    }

    public function testUnset(): void
    {
        $rade            = new Container();
        $rade['param']   = 'value';
        $rade['service'] = function () {
            return new Fixtures\Service();
        };

        unset($rade['param'], $rade['service']);
        $this->assertFalse(isset($rade['param']));
        $this->assertFalse(isset($rade['service']));
    }

    /**
     * @dataProvider serviceDefinitionProvider
     */
    public function testShare($service): void
    {
        $rade                   = new Container();
        $rade['shared_service'] = $service;

        $serviceOne = $rade['shared_service'];
        $this->assertInstanceOf(Fixtures\Service::class, $serviceOne);

        $serviceTwo = $rade['shared_service'];
        $this->assertInstanceOf(Fixtures\Service::class, $serviceTwo);

        $this->assertSame($serviceOne, $serviceTwo);
    }

    public function testGlobalFunctionNameAsParameterValue(): void
    {
        $rade                    = new Container();
        $rade['global_function'] = 'strlen';
        $this->assertSame('strlen', $rade['global_function']);
    }

    public function testFluentRegister(): void
    {
        $rade = new Container();
        $this->assertSame($rade, $rade->register($this->getMockBuilder(ServiceProviderInterface::class)->getMock()));
    }

    /**
     * @dataProvider serviceDefinitionProvider
     */
    public function testExtend($service): void
    {
        $rade                   = new Container();
        $rade['shared_service'] = function () {
            return new Fixtures\Service();
        };
        $rade['factory_service'] = $rade->factory(function () {
            return new Fixtures\Service();
        });

        $rade->extend('shared_service', $service);
        $serviceOne = $rade['shared_service'];
        $this->assertInstanceOf(Fixtures\Service::class, $serviceOne);
        $serviceTwo = $rade['shared_service'];
        $this->assertInstanceOf(Fixtures\Service::class, $serviceTwo);
        $this->assertSame($serviceOne, $serviceTwo);
        $this->assertSame($serviceOne->value, $serviceTwo->value);

        $rade->extend('factory_service', $service);
        $serviceOne = $rade['factory_service'];
        $this->assertInstanceOf(Fixtures\Service::class, $serviceOne);
        $serviceTwo = $rade['factory_service'];
        $this->assertInstanceOf(Fixtures\Service::class, $serviceTwo);

        $this->assertSame($serviceOne, $serviceTwo);
        $this->assertSame($serviceOne->value, $serviceTwo->value);
    }

    public function testExtendDoesNotLeakWithFactories(): void
    {
        $rade = new Container();

        $rade['foo'] = $rade->factory(function (): void {
        });
        $rade['foo'] = $rade->extend('foo', function ($foo, $rade): void {
        });
        unset($rade['foo']);

        $p = new ReflectionProperty($rade, 'values');
        $p->setAccessible(true);
        $this->assertNotEmpty($p->getValue($rade));
        $this->assertArrayNotHasKey('foo', $p->getValue($rade));

        $p = new ReflectionProperty($rade, 'factories');
        $p->setAccessible(true);
        $this->assertCount(1, $p->getValue($rade));
    }

    public function testExtendValidatesKeyIsPresent(): void
    {
        $this->expectException(NotFoundServiceException::class);
        $this->expectExceptionMessage('Identifier "foo" is not defined.');

        $rade = new Container();
        $rade->extend('foo', function (): void {
        });
    }

    public function testKeys(): void
    {
        $rade        = new Container();
        $rade['foo'] = 123;
        $rade['bar'] = 123;

        $this->assertEquals(['container', 'foo', 'bar'], $rade->keys());
    }

    /** @test */
    public function settingAnInvokableObjectShouldTreatItAsFactory(): void
    {
        $rade              = new Container();
        $rade['invokable'] = new Fixtures\Invokable();

        $this->assertInstanceOf(Fixtures\Service::class, $rade['invokable']);
    }

    /** @test */
    public function settingNonInvokableObjectShouldTreatItAsParameter(): void
    {
        $rade                  = new Container();
        $rade['non_invokable'] = new Fixtures\NonInvokable();

        $this->assertInstanceOf(Fixtures\NonInvokable::class, $rade['non_invokable']);
    }

    /**
     * @dataProvider badServiceDefinitionProvider
     */
    public function testFactoryFailsForInvalidServiceDefinitions($service): void
    {
        $this->expectExceptionMessage('Service definition is not a Closure or invokable object.');
        $this->expectException(ContainerResolutionException::class);

        $rade = new Container();
        $rade->factory($service);
    }

    /**
     * @dataProvider badServiceDefinitionProvider
     */
    public function testExtendFailsForInvalidServiceDefinitions($service): void
    {
        $this->expectException(TypeError::class);

        $rade        = new Container();
        $rade['foo'] = function (): void {
        };
        $rade->extend('foo', $service);
    }

    public function testExtendFailsIfFrozenServiceIsNonInvokable(): void
    {
        $this->expectException(FrozenServiceException::class);
        $this->expectExceptionMessage('Cannot override frozen service "foo".');

        $rade        = new Container();
        $rade['foo'] = function () {
            return new Fixtures\NonInvokable();
        };
        $foo = $rade['foo'];

        $rade->extend('foo', function (): void {
        });
    }

    public function testExtendFailsIfFrozenServiceIsInvokable(): void
    {
        $this->expectException(FrozenServiceException::class);
        $this->expectExceptionMessage('Cannot override frozen service "foo".');

        $rade        = new Container();
        $rade['foo'] = function () {
            return new Fixtures\Invokable();
        };
        $foo = $rade['foo'];

        $rade->extend('foo', function (): void {
        });
    }

    /**
     * Provider for invalid service definitions.
     */
    public function badServiceDefinitionProvider()
    {
        return [
            [123],
            [new Fixtures\NonInvokable()],
        ];
    }

    /**
     * Provider for service definitions.
     */
    public function serviceDefinitionProvider()
    {
        return [
            'Named Parameter' => [function ($value = null) {
                $service = new Fixtures\Service();
                $service->value = $value;

                return $service;
            }],
            'Named TypeHint Parameter' => [function (?Fixtures\Service $value) {
                $service = new Fixtures\Service();
                $service->value = $value;

                return $service;
            }],
            'Class Object' => [new Fixtures\Invokable()],
        ];
    }

    public function testDefiningNewServiceAfterFreeze(): void
    {
        $rade        = new Container();
        $rade['foo'] = function () {
            return 'foo';
        };
        $foo = $rade['foo'];

        $rade['bar'] = function () {
            return 'bar';
        };
        $this->assertSame('bar', $rade['bar']);
    }

    public function testOverridingServiceAfterFreeze(): void
    {
        $this->expectException(FrozenServiceException::class);
        $this->expectExceptionMessage('Cannot override frozen service "foo".');

        $rade        = new Container();
        $rade['foo'] = function () {
            return 'foo';
        };
        $foo = $rade['foo'];

        $rade['foo'] = function () {
            return 'bar';
        };
    }

    public function testRemovingServiceAfterFreeze(): void
    {
        $rade        = new Container();
        $rade['foo'] = function () {
            return 'foo';
        };
        $foo = $rade['foo'];

        unset($rade['foo']);
        $rade['foo'] = function () {
            return 'bar';
        };
        $this->assertSame('bar', $rade['foo']);
    }

    public function testExtendingService(): void
    {
        $rade        = new Container();
        $rade['foo'] = function () {
            return 'foo';
        };
        $rade['foo'] = $rade->extend('foo', function ($foo, $app) {
            return "$foo.bar";
        });
        $rade['foo'] = $rade->extend('foo', function ($foo, $app) {
            return "$foo.baz";
        });
        $this->assertSame('foo.bar.baz', $rade['foo']);
    }

    public function testExtendingServiceAfterOtherServiceFreeze(): void
    {
        $rade        = new Container();
        $rade['foo'] = function () {
            return 'foo';
        };
        $rade['bar'] = function () {
            return 'bar';
        };
        $foo = $rade['foo'];

        $rade['bar'] = $rade->extend('bar', function ($bar, $app) {
            return "$bar.baz";
        });
        $this->assertSame('bar.baz', $rade['bar']);
    }

    public function testCircularReferenceWithParameter(): void
    {
        $rade            = new Container();
        $rade['service'] = function (Fixtures\Service $service): Fixtures\Service {
            return $service;
        };

        $this->expectExceptionMessage('Circular reference detected for services: service.');
        $this->expectException(CircularReferenceException::class);

        $rade['service'];
    }

    public function testCircularReferenceWithServiceDefinitions(): void
    {
        $rade        = new Container();
        $rade['one'] = function (Container $container) {
            return $container['two'];
        };

        $rade['two'] = function (Container $container) {
            return $container['one'];
        };

        $this->expectExceptionMessage('Circular reference detected for services: one, two.');
        $this->expectException(CircularReferenceException::class);

        $rade['one'];
    }

    public function testCallIntanceAndCallMethod(): void
    {
        $rade = new Container();

        $this->assertInstanceOf(Fixtures\Service::class, $rade->callInstance(Fixtures\Service::class));
        $this->assertInstanceOf(Fixtures\Constructor::class, $rade->callInstance(Fixtures\Constructor::class));

        try {
            $rade->callInstance(Fixtures\Unstantiable::class);
        } catch (ContainerResolutionException $e) {
            $this->assertEquals('Class Rade\DI\Tests\Fixtures\Unstantiable is not instantiable.', $e->getMessage());
        }

        try {
            $rade->callInstance(Fixtures\Service::class, ['me', 'cool']);
        } catch (ContainerResolutionException $e) {
            $this->assertEquals(
                'Unable to pass arguments, class Rade\DI\Tests\Fixtures\Service has no constructor.',
                $e->getMessage()
            );
        }

        $method1 = $rade->callMethod(
            function ($cool, array $ggg, $value): string {
                return $cool;
            },
            ['me', ['beat', 'baz']]
        );

        $method2 = $rade->callMethod(
            function ($cool, ?array $ggg, $value): string {
                return $cool;
            },
            ['me']
        );

        $this->assertEquals('me', $method1);
        $this->assertEquals('me', $method2);
    }

    public function testContainerFactory(): void
    {
        $rade            = new Container();
        $rade['service'] = $rade->factory(function (): Fixtures\Service {
            return new Fixtures\Service();
        });

        $serviceOne = $rade['service'];
        $serviceTwo = $rade['service'];

        $this->assertNotSame($serviceOne, $serviceTwo);
    }

    public function testAutowiringMissingService(): void
    {
        $rade             = new Container();
        $rade['autowire'] = new Fixtures\ServiceAutowire(new Fixtures\Service(), null);

        $this->expectExceptionMessage(
            'Service of type Rade\DI\Tests\Fixtures\Service needed by $service in ' .
            'Rade\DI\Tests\Fixtures\ServiceAutowire::missingService() not found.',
        );
        $this->expectException(ContainerResolutionException::class);

        $rade->callMethod([$rade['autowire'], 'missingService']);
    }

    public function testAutowiringMissingClass(): void
    {
        $rade             = new Container();
        $rade['autowire'] = new Fixtures\ServiceAutowire(new Fixtures\Service(), null);

        $this->expectExceptionMessage(
            'Class Rade\DI\Tests\Fixtures\Servic needed by $service in ' .
            'Rade\DI\Tests\Fixtures\ServiceAutowire::missingClass() not found. ' .
            "Check type hint and 'use' statements.",
        );
        $this->expectException(ContainerResolutionException::class);

        $rade->callMethod([$rade['autowire'], 'missingClass']);
    }

    public function testAutowiringMultipleService(): void
    {
        $rade             = new Container();
        $rade['autowire'] = new Fixtures\ServiceAutowire(new Fixtures\Service(), null);

        $rade['name.value'] = NamedValueResolver::class;
        $rade['type.value'] = TypeHintValueResolver::class;

        $this->expectExceptionMessage(
            'Multiple services of type DivineNii\Invoker\Interfaces\ArgumentValueResolverInterface ' .
            'found: name.value, type.value. (needed by $resolver in ' .
            'Rade\DI\Tests\Fixtures\ServiceAutowire::multipleAutowireTypes())'
        );
        $this->expectException(ContainerResolutionException::class);

        $rade->callMethod([$rade['autowire'], 'multipleAutowireTypes']);
    }

    public function testAutowiringSelectFromMultipleServiceUsingDocParam(): void
    {
        $rade             = new Container();
        $rade['autowire'] = new Fixtures\ServiceAutowire(new Fixtures\Service(), null);

        $rade['name.value'] = NamedValueResolver::class;
        $rade['type.value'] = TypeHintValueResolver::class;

        $namedResolver = $rade->callMethod([$rade['autowire'], 'multipleAutowireTypesFound']);

        $this->assertInstanceOf(NamedValueResolver::class, $namedResolver);

        $this->expectExceptionMessage(
            'Multiple services of type DivineNii\Invoker\Interfaces\ArgumentValueResolverInterface ' .
            'found: name.value, type.value. (needed by $resolver in ' .
            'Rade\DI\Tests\Fixtures\ServiceAutowire::multipleAutowireTypesNotFound())'
        );
        $this->expectException(ContainerResolutionException::class);

        $rade->callMethod([$rade['autowire'], 'multipleAutowireTypesNotFound']);
    }

    public function testAutowiringMultipleServicesOnArray(): void
    {
        $rade             = new Container();
        $rade['autowire'] = new Fixtures\ServiceAutowire(new Fixtures\Service(), null);

        $rade['name.value']    = NamedValueResolver::class;
        $rade['type.value']    = TypeHintValueResolver::class;
        $rade['default.value'] = DefaultValueResolver::class;
        $rade['class.value']   = ClassValueResolver::class;

        $resolvers = $rade->callMethod([$rade['autowire'], 'autowireTypesArray']);

        $this->assertCount(4, $resolvers);

        foreach ($resolvers as $resolver) {
            $this->assertInstanceOf(ArgumentValueResolverInterface::class, $resolver);
        }
    }

    public function testAutowiringTypeOnParameter(): void
    {
        $rade = new Container();

        $rade['cl_container'] = Fixtures\Constructor::class;
        $rade['fn_container'] = function (ContainerInterface $container) {
            return $container;
        };

        $this->assertEquals($rade, $rade['fn_container']);
        $this->assertEquals($rade, $rade['cl_container']->value);
    }

    public function testAutowiringOnExcludedType(): void
    {
        $rade = new Container();
        $rade->addExcludedTypes([Fixtures\Service::class]);

        $rade['construct'] = Fixtures\Constructor::class;
        $rade['service']   = function (Fixtures\Service $container) {
            return $container;
        };

        $this->expectExceptionMessage(
            'Service of type Rade\DI\Tests\Fixtures\Service needed by $container in ' .
            'Rade\DI\Tests\ContainerTest::Rade\DI\Tests\{closure}() not found.'
        );
        $this->expectException(ContainerResolutionException::class);

        $rade['service'];
    }
}
