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

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Rade\DI\Container;
use Rade\DI\Exceptions\CircularReferenceException;
use Rade\DI\Exceptions\ContainerResolutionException;
use Rade\DI\Exceptions\FrozenServiceException;
use Rade\DI\Exceptions\NotFoundServiceException;
use Rade\DI\ServiceProviderInterface;

class ContainerTest extends TestCase
{
    public function testWithString(): void
    {
        $rade = new Container();
        $rade['param'] = 'value';

        $this->assertEquals('value', $rade['param']);
    }

    public function testWithClosure(): void
    {
        $rade = new Container();
        $rade['service'] = function () {
            return new Fixtures\Service();
        };

        $this->assertInstanceOf(Fixtures\Service::class, $rade['service']);
    }

    public function testProtect(): void
    {
        $rade = new Container();
        $rade['protected'] = $rade->protect($protected = fn (string $string) => strlen($string));

        $this->assertEquals(6, $rade['protected']('strlen'));

        $this->assertSame($protected, $rade['protected']);
    }

    /**
     * @dataProvider badServiceDefinitionProvider
     */
    public function testProtectFailsForInvalidServiceDefinitions($service): void
    {
        $this->expectExceptionMessage('Callable is not a Closure or invokable object.');
        $this->expectException(ContainerResolutionException::class);

        $rade = new Container();
        $rade->protect($service);
    }

    public function testGlobalFunctionNameAsParameterValue(): void
    {
        $rade = new Container();
        $rade['global_function'] = fn () => 'strlen';

        $this->assertSame('strlen', $rade['global_function']);
    }

    public function testServiceIsShared(): void
    {
        $rade = new Container();
        $rade['foo'] = $bound = new Fixtures\Service();

        $this->assertSame($bound, $rade['foo']);
    }

    public function testServicesShouldBeSame(): void
    {
        $rade = new Container();
        $rade['class'] = function () {
            return new \stdClass;
        };

        $firstInstantiation = $rade['class'];
        $secondInstantiation = $rade['class'];

        $this->assertSame($firstInstantiation, $secondInstantiation);
    }

    public function testServicesShouldBeDifferent(): void
    {
        $rade            = new Container();
        $rade['service'] = $rade->factory(function () {
            return new Fixtures\Service();
        });

        $serviceOne = $rade['service'];
        $serviceTwo = $rade['service'];

        $this->assertNotSame($serviceOne, $serviceTwo);
    }

    public function testGettingServiceResolution(): void
    {
        $rade = new Container();
        $this->assertInstanceOf(Fixtures\Service::class, $rade->get(Fixtures\Service::class));

        try {
            $rade->get('nothing');
        } catch (NotFoundServiceException $e) {
            $this->assertEquals('Identifier "nothing" is not defined.', $e->getMessage());
        }

        $this->expectExceptionMessage('Identifier "Rade\DI\Tests\Fixtures\Service" is not defined.');
        $this->expectException(NotFoundServiceException::class);

        $rade[Fixtures\Service::class];
    }

    public function testArrayAccess(): void
    {
        $rade = new Container;
        $rade['something'] = function () {
            return 'foo';
        };

        $this->assertTrue(isset($rade['something']));
        $this->assertSame('foo', $rade['something']);

        unset($rade['something']);
        $this->assertFalse(isset($rade['something']));
    }

    public function testAliases(): void
    {
        $rade = new Container();
        $rade['foo'] = 'bar';
        $rade->alias('baz', 'foo');
        $rade->alias('bat', 'baz');

        $this->assertSame('bar', $rade['foo']);
        $this->assertSame('bar', $rade['baz']);
        $this->assertSame('bar', $rade['bat']);
    }

    public function testItThrowsExceptionWhenServiceIdIsSameAsAlias(): void
    {
        $this->expectExceptionMessage('[name] is aliased to itself.');
        $this->expectException('LogicException');

        $rade = new Container();
        $rade->alias('name', 'name');
    }

    public function testItThrowsExceptionIfServiceIdNotFound(): void
    {
        $this->expectExceptionMessage('Service id is not found in container');
        $this->expectException(ContainerResolutionException::class);

        $rade = new Container();
        $rade->alias('name', 'nothing');
    }

    public function testAliasesWithProtectedService(): void
    {
        $rade = new Container();
        $rade['foo'] = $rade->protect(function (array $config) {
            return $config;
        });
        $rade->alias('baz', 'foo');

        $this->assertEquals([1, 2, 3], $rade['baz']([1, 2, 3]));
    }

    public function testServicesCanBeOverridden(): void
    {
        $rade = new Container();
        $rade['foo'] = 'bar';
        $rade['foo'] = 'baz';

        $this->assertSame('baz', $rade['foo']);
    }

    public function testResolutionOfDefaultParameterAutowring(): void
    {
        $rade = new Container();
        $instance = $rade->get(Fixtures\Constructor::class);

        $this->assertInstanceOf(Container::class, $instance->value);
        $this->assertSame($rade, $instance->value);
    }

    public function testServiceAndAliasCheckViaArrayAccess(): void
    {
        $rade = new Container();
        $rade['object'] = new \stdClass;
        $rade->alias('alias', 'object');

        $this->assertTrue(isset($rade['object']));
        $this->assertTrue(isset($rade['alias']));
    }

    public function testCallInstanceResolution(): void
    {
        $rade = new Container();

        try {
            $rade->callInstance(Fixtures\Service::class, ['me', 'cool']);
        } catch (ContainerResolutionException $e) {
            $this->assertEquals(
                'Unable to pass arguments, class Rade\DI\Tests\Fixtures\Service has no constructor.',
                $e->getMessage()
            );
        }

        try {
            $rade->callInstance(Fixtures\Unstantiable::class);
        } catch (ContainerResolutionException $e) {
            $this->assertEquals('Class Rade\DI\Tests\Fixtures\Unstantiable is not instantiable.', $e->getMessage());
        }

        $this->expectExceptionMessage('Class Psr\Log\LoggerInterface is not instantiable.');
        $this->expectException(ContainerResolutionException::class);

        $rade->callInstance('Psr\Log\LoggerInterface');
    }

    public function testResolvingWithUsingAnInterface(): void
    {
        $rade = new Container();
        $rade['logger'] = NullLogger::class;
        $instance = $rade->get(LoggerInterface::class);

        $this->assertInstanceOf(NullLogger::class, $instance);
    }

    public function testNestedParameterOverrideWithProtectedServices(): void
    {
        $rade = new Container();
        $rade['foo'] = $rade->protect(function ($config) use ($rade) {
            return $rade['bar'](['name' => 'Divine']);
        });
        $rade['bar'] = $rade->protect(function ($config) {
            return $config;
        });

        $this->assertEquals(['name' => 'Divine'], $rade['foo']('something'));
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
        $this->assertTrue($rade->has('null'));
        $this->assertFalse($rade->has('non_existent'));
    }

    public function testOffsetGetValidatesKeyIsPresent(): void
    {
        $this->expectException(NotFoundServiceException::class);
        $this->expectExceptionMessage('Identifier "foo" is not defined.');

        $rade = new Container();
        $rade['foo'];
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

    public function testFluentRegister(): void
    {
        $rade = new Container();
        $this->assertSame($rade, $rade->register($this->getMockBuilder(ServiceProviderInterface::class)->getMock()));
    }

    public function testExtend(): void
    {
        $rade = new Container();
        $rade['shared_service'] = function () use ($rade) {
            return $rade['factory_service'];
        };
        $rade['factory_service'] = $rade->factory(function () {
            return new Fixtures\Service();
        });

        $service = function (Fixtures\Service $service, $container) {
            $service->value = $container;

            return $service;
        };

        $rade->extend('shared_service', $service);
        $serviceOne = $rade['shared_service'];
        $serviceTwo = $rade['shared_service'];

        $this->assertSame($serviceOne, $serviceTwo);
        $this->assertSame($serviceOne->value, $serviceTwo->value);

        $rade->extend('factory_service', $service);
        $serviceOne = $rade['factory_service'];
        $serviceTwo = $rade['factory_service'];

        $this->assertSame($serviceOne, $serviceTwo);
        $this->assertSame($serviceOne->value, $serviceTwo->value);
    }

    public function testExtendDoesNotSupportProtectedServices(): void
    {
        $rade = new Container();
        $rade['foo'] = $rade->protect(fn () => new Fixtures\Service());

        $this->expectExceptionMessage(
            'Protected callable service \'foo\' cannot be extended, cause it has parameters which cannot be resolved.'
        );
        $this->expectException(ContainerResolutionException::class);

        $rade->extend('foo', fn (Fixtures\Service $service) => $service);
    }

    public function testExtendDoesNotLeakWithFactories(): void
    {
        $rade = new Container();

        $rade['foo'] = $rade->factory(function (): void {
        });
        $rade['foo'] = $rade->extend('foo', function ($foo, $rade): void {
        });
        unset($rade['foo']);

        $p = new \ReflectionProperty($rade, 'values');
        $p->setAccessible(true);
        $this->assertNotEmpty($p->getValue($rade));
        $this->assertArrayNotHasKey('foo', $p->getValue($rade));

        $p = new \ReflectionProperty($rade, 'factories');
        $p->setAccessible(true);
        $this->assertCount(0, $p->getValue($rade));
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
        $rade = new Container();
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
        $this->expectException(\TypeError::class);

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

    public function testCallMethodResolution(): void
    {
        $rade = new Container();

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
}
