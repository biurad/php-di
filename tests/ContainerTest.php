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
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Rade\DI\Builder\Reference;
use Rade\DI\Builder\Statement;
use Rade\DI\Container;
use Rade\DI\Definition;
use Rade\DI\Exceptions\CircularReferenceException;
use Rade\DI\Exceptions\ContainerResolutionException;
use Rade\DI\Exceptions\FrozenServiceException;
use Rade\DI\Exceptions\NotFoundServiceException;
use Rade\DI\Exceptions\ServiceCreationException;
use Rade\DI\FallbackContainer;
use Rade\DI\RawDefinition;
use Rade\DI\Services\ServiceProviderInterface;
use Rade\DI\Tests\Fixtures\AppContainer;

class ContainerTest extends TestCase
{
    public function testWithString(): void
    {
        $rade = new Container();
        $rade['param'] = $rade->raw('value');

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

    public function testContainerIsCloneable(): void
    {
        $this->expectExceptionMessage('Container is not cloneable');
        $this->expectException(\LogicException::class);

        $cloned = clone new Container();
    }

    public function testContainerHasItSelf(): void
    {
        $rade = new Container();

        $this->assertTrue(isset($rade['container']));
        $this->assertTrue($rade->has('container'));
        $this->assertSame($rade, $rade['container']);
        $this->assertSame($rade, $rade->get('container'));
        $this->assertSame($rade, $rade->get(Container::class));
        $this->assertSame($rade, $rade->get(ContainerInterface::class));

        $rade->reset();

        $this->assertTrue(isset($rade['container']));
        $this->assertTrue($rade->has('container'));
        $this->assertSame($rade, $rade['container']);
        $this->assertSame($rade, $rade->get('container'));
        $this->assertNotSame($rade, $rade->get(Container::class));

        $this->expectExceptionObject(new NotFoundServiceException('Identifier "Psr\Container\ContainerInterface" is not defined.'));

        $rade->get(ContainerInterface::class);
    }

    public function testRaw(): void
    {
        $rade = new Container();
        $rade['protected'] = $rade->raw($protected = fn (string $string) => \strlen($string));

        $this->assertEquals(6, $rade['protected']('strlen'));
        $this->assertEquals(6, $rade->call('protected', ['strlen']));
        $this->assertSame($protected, $rade['protected']);
    }

    public function testFactory(): void
    {
        $rade = new Container();
        $rade['factory'] = $rade->factory($factory = fn () => 6);

        $this->assertNotSame($factory, $one = $rade['factory']);
        $this->assertSame($one, $rade['factory']);
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
        $this->assertTrue($rade->initialized('foo'));
    }

    public function testServicesShouldBeSame(): void
    {
        $rade = new Container();
        $rade['class'] = function () {
            return new \stdClass();
        };

        $firstInstantiation = $rade['class'];
        $secondInstantiation = $rade['class'];

        $this->assertSame($firstInstantiation, $secondInstantiation);
        $this->assertTrue($rade->initialized('class'));
    }

    public function testServicesShouldBeDifferent(): void
    {
        $rade = new Container();
        $rade['service'] = $rade->factory(function () {
            return new Fixtures\Service();
        });

        $serviceOne = $rade['service'];
        $serviceTwo = $rade['service'];

        $this->assertNotSame($serviceOne, $serviceTwo);
        $this->assertFalse($rade->initialized('service'));
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

        try {
            $rade->set('service_1', new Fixtures\Constructor($rade), true);
            $rade->set('service_2', new Fixtures\Service(), true);

            $this->assertIsArray($rade->get(Fixtures\Service::class, $rade::IGNORE_MULTIPLE_SERVICE));

            $rade[Fixtures\Service::class];
        } catch (ContainerResolutionException $e) {
            $this->assertEquals('Multiple services of type Rade\DI\Tests\Fixtures\Service found: service_1, service_2.', $e->getMessage());
        }

        try {
            $rade->get('contain');
        } catch (NotFoundServiceException $e) {
            $this->assertEquals('Identifier "contain" is not defined. Did you mean: "container" ?', $e->getMessage());
        }

        $this->expectExceptionMessage('Identifier "Rade\DI\Tests\Fixtures\Unstantiable" is not defined.');
        $this->expectException(NotFoundServiceException::class);

        $rade[Fixtures\Unstantiable::class];
    }

    public function testArrayAccess(): void
    {
        $rade = new Container();
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
        $rade['foo'] = $rade->raw('bar');
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
        $this->expectExceptionMessage('Service id \'nothing\' is not found in container');
        $this->expectException(ContainerResolutionException::class);

        $rade = new Container();
        $rade->alias('name', 'nothing');
    }

    public function testAliasesWithProtectedService(): void
    {
        $rade = new Container();
        $rade['foo'] = $rade->raw(function (array $config) {
            return $config;
        });
        $rade->alias('baz', 'foo');

        $this->assertEquals([1, 2, 3], $rade['baz']([1, 2, 3]));
    }

    public function testServicesCanBeOverridden(): void
    {
        $rade = new Container();
        $rade['foo'] = $rade->raw('bar');
        $rade['foo'] = $rade->raw('baz');

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
        $rade['object'] = new \stdClass();
        $rade->alias('alias', 'object');

        $this->assertTrue(isset($rade['object']));
        $this->assertTrue(isset($rade['alias']));
    }

    public function testCallInstanceResolution(): void
    {
        $rade = new Container();

        try {
            $rade->call(Fixtures\Service::class, ['me', 'cool']);
        } catch (ContainerResolutionException $e) {
            $this->assertEquals(
                'Unable to pass arguments, class Rade\DI\Tests\Fixtures\Service has no constructor or constructor is not public.',
                $e->getMessage()
            );
        }

        try {
            $rade->call(Fixtures\Unstantiable::class);
        } catch (ContainerResolutionException $e) {
            $this->assertEquals('Class Rade\DI\Tests\Fixtures\Unstantiable is an abstract type or instantiable.', $e->getMessage());
        }

        $this->expectExceptionMessage('Class Psr\Log\LoggerInterface is an abstract type or instantiable.');
        $this->expectException(ContainerResolutionException::class);

        $rade->call('Psr\Log\LoggerInterface');
    }

    public function testResolvingWithUsingAnInterface(): void
    {
        $rade = new Container();
        $rade['logger'] = $rade->lazy(NullLogger::class);
        $instance = $rade->get(LoggerInterface::class);

        $this->assertInstanceOf(NullLogger::class, $instance);
    }

    public function testNestedParameterOverrideWithProtectedServices(): void
    {
        $rade = new Container();
        $rade['foo'] = $rade->raw(function ($config) use ($rade) {
            return $rade['bar'](['name' => 'Divine']);
        });
        $rade['bar'] = $rade->raw(function ($config) {
            return $config;
        });

        $this->assertEquals(['name' => 'Divine'], $rade['foo']('something'));
    }

    public function testIsset(): void
    {
        $rade = new Container();
        $rade['param'] = $rade->raw('value');
        $rade['service'] = function () {
            return new Fixtures\Service();
        };

        $rade['null'] = $rade->raw(null);

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

    public function testUnset(): void
    {
        $rade = new Container();
        $rade['param'] = $rade->raw('value');
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

        $rade->register(new Fixtures\RadeServiceProvider(), ['hello' => 'Divine']);

        $this->assertTrue(isset($rade->parameters['rade_di']['hello']));
        $this->assertCount(5, $rade->keys());

        $this->assertInstanceOf(Fixtures\Service::class, $service = $rade['service']);
        $this->assertSame($rade, $service->value);
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

        $service = function ($service, $container) {
            if ($service instanceof Fixtures\Service) {
                $service->value = (object) [$container];
            } elseif ($service instanceof Definition) {
                $service->bind('value', (object) [$container]);
            }

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

        $this->assertNotSame($serviceOne, $serviceTwo);
        $this->assertSame($serviceOne->value, $serviceTwo->value);
    }

    public function testExtendDoesSupportRawServices(): void
    {
        $rade = new Container();
        $rade['num'] = $rade->raw(5);
        $rade['service'] = $rade->raw(new Fixtures\Service());
        $rade['call'] = $rade->raw(fn (Container $container) => new Fixtures\Constructor($container));

        $service = static function ($service, $container) {
            if ($service instanceof Fixtures\Service) {
                $service->value = 'extended';

                return $service;
            }

            if (\is_callable($service)) {
                return $service($container);
            }

            return $service + 5;
        };

        $rade->extend('num', $service);
        $this->assertEquals(10, $rade['num']);

        $rade->extend('service', $service);
        $this->assertEquals('extended', $rade['service']->value);

        $rade->extend('call', $service);
        $this->assertInstanceOf(Fixtures\Constructor::class, $rade['call']);
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
        $rade['foo'] = $rade->raw(123);
        $rade['bar'] = $rade->raw(123);

        $this->assertEquals(['foo', 'bar', 'container'], $rade->keys());
    }

    /** @test */
    public function settingAnInvokableObjectShouldTreatItAsFactory(): void
    {
        $rade = new Container();
        $rade['invokable'] = new Fixtures\Invokable();

        $this->assertInstanceOf(Fixtures\Service::class, $rade['invokable']);
    }

    /** @test */
    public function settingNonInvokableObjectShouldTreatItAsParameter(): void
    {
        $rade = new Container();
        $rade['non_invokable'] = new Fixtures\NonInvokable();

        $this->assertInstanceOf(Fixtures\NonInvokable::class, $rade['non_invokable']);
    }

    /**
     * @dataProvider badServiceDefinitionProvider
     */
    public function testExtendFailsForInvalidServiceDefinitions($service): void
    {
        $this->expectException(\TypeError::class);

        $rade = new Container();
        $rade['foo'] = function (): void {
        };
        $rade->extend('foo', $service);
    }

    public function testExtendFailsIfFrozenServiceIsNonInvokable(): void
    {
        $this->expectException(FrozenServiceException::class);
        $this->expectExceptionMessage('Cannot override frozen service "foo".');

        $rade = new Container();
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

        $rade = new Container();
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
        $rade = new Container();
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

        $rade = new Container();
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
        $rade = new Container();
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
        $rade = new Container();
        $rade['foo'] = function () {
            return 'foo';
        };

        $rade->extend('foo', function ($foo, $app) {
            return "$foo.bar";
        });
        $rade->extend('foo', function ($foo, $app) {
            return "$foo.baz";
        });
        $this->assertSame('foo.bar.baz', $rade['foo']);
    }

    public function testExtendingServiceAfterOtherServiceFreeze(): void
    {
        $rade = new Container();
        $rade['foo'] = function () {
            return 'foo';
        };
        $rade['bar'] = function () {
            return 'bar';
        };
        $foo = $rade['foo'];

        $rade->extend('bar', function ($bar, $app) {
            return "$bar.baz";
        });
        $this->assertSame('bar.baz', $rade['bar']);
    }

    public function testCircularReferenceWithServiceDefinitions(): void
    {
        $rade = new Container();
        $rade['one'] = function (Container $container) {
            return $container['two'];
        };

        $rade['two'] = function (Container $container) {
            return $container['one'];
        };

        try {
            $rade->extend('one', fn ($one, $rade) => $rade);
        } catch (CircularReferenceException $e) {
            $this->assertEquals(['one', 'two', 'one'], $e->getPath());
            $this->assertEquals('one', $e->getServiceId());
        }

        $this->expectExceptionMessage('Circular reference detected for service "one", path: "one -> two -> one".');
        $this->expectException(CircularReferenceException::class);

        $rade['one'];
    }

    public function testCallMethodResolution(): void
    {
        $rade = new Container();

        $method1 = $rade->call(
            function ($cool, array $ggg, $value): string {
                return $cool;
            },
            ['me', ['beat', 'baz']]
        );

        $method2 = $rade->call(
            function ($cool, ?array $ggg, $value): string {
                return $cool;
            },
            ['me']
        );

        $this->assertEquals('me', $method1);
        $this->assertEquals('me', $method2);
    }

    public function testShouldFailOnUnrealAutowiringWithSetMethod(): void
    {
        $rade = new Container();

        $this->expectExceptionMessage(
            'Parameter $service in Rade\DI\Tests\Fixtures\ServiceAutowire::__construct() typehint(s) ' .
            '\'Rade\DI\Tests\Fixtures\Service\' not found, and no default value specified.'
        );
        $this->expectException(ContainerResolutionException::class);

        $rade->set('service', $rade->lazy(Fixtures\Service::class));
        $rade['baz'] = $rade->lazy(Fixtures\ServiceAutowire::class);

        $this->assertInstanceOf(Fixtures\Service::class, $rade['baz']->value);
    }

    public function testExtendingContainer(): void
    {
        $newRade = new AppContainer();

        $this->assertSame($newRade, $newRade['container']);
        $this->assertSame($newRade, $newRade->get(Container::class));
        $this->assertSame($newRade, $newRade->get(AppContainer::class));
    }

    public function testDefinitionDeprecation(): void
    {
        $rade = new Container();

        $def = $rade->set('service', $rade->definition(Fixtures\Service::class))->deprecate();
        $this->assertTrue($def->is(Definition::DEPRECATED));

        $rade['service'];
        $this->assertEquals([
            'type' => \E_USER_DEPRECATED,
            'message' => 'The "service" service is deprecated. You should stop using it, as it will be removed in the future.',
            'file' => 'C:\HomeServer\Github\rade-di\vendor\symfony\deprecation-contracts\function.php',
            'line' => 25,
        ], \error_get_last());
    }

    public function testDefinition(): void
    {
        $rade = new Container();
        $rade->set('name.value', $rade->lazy('DivineNii\Invoker\ArgumentResolver\NamedValueResolver'), true);
        $rade['default.value'] = $nVal = $rade->definition(new Statement('DivineNii\Invoker\ArgumentResolver\DefaultValueResolver'));

        $def = $rade->set('lazy', $rade->definition(Fixtures\ServiceAutowire::class, Definition::LAZY), true)
            ->bind('invoke', new Fixtures\Invokable())
            ->bind('autowireTypesArray', new Reference('DivineNii\Invoker\Interfaces\ArgumentValueResolverInterface[]'))
            ->bind('autowireTypesArray', [[$rade->raw('none'), $rade['default.value'], new Reference('Rade\DI\Tests\Fixtures\Service[]')]])
            ->bind('missingService', new Statement(Fixtures\Service::class))
            ->bind('multipleAutowireTypesNotFound', $nVal);
        $rade['factory'] = $rade->factory(new Fixtures\Invokable());

        $count = 0;
        $rade['service'] = $rade->definition(Fixtures\Constructor::class)
            ->arg('value', $rade)
            ->bind(Definition::EXTRA_BIND, new Statement(function () use (&$count): void {
                $count += 10;
            }))
            ->bind(Definition::EXTRA_BIND, function () use (&$count): void {
                $count += 5;
            })
            ->bind(Definition::EXTRA_BIND, function () use (&$count): void {
                $count -= 12;
            });

        $this->assertInstanceOf(Fixtures\Service::class, $rade['service']);
        $this->assertEquals(3, $count);

        $this->assertIsObject($rade['lazy']);
        $this->assertInstanceOf(Fixtures\ServiceAutowire::class, $lazy = $rade['lazy']);
        $this->assertNotTrue($def->is(333));
        $this->assertNotTrue($def->is(Definition::FACTORY));
        $this->assertTrue($def->is(Definition::LAZY));
        $this->assertTrue($def->is(Definition::AUTOWIRED));
        $this->assertEquals('getLazy', (string) $def);
        $this->assertNotNull($lazy->invoke);
        $this->assertIsObject($factory1 = $rade['factory']);
        $this->assertNotSame($factory1, $rade['factory']);

        $rade->set('callable', new Statement(Fixtures\Invokable::class));
        $this->assertInstanceOf(Fixtures\Service::class, $rade['callable']());

        try {
            $rade->set('raw', $rade->raw(new RawDefinition('')));
        } catch (ContainerResolutionException $e) {
            $this->assertEquals('unresolvable definition cannot contain itself.', $e->getMessage());
        }

        $this->expectExceptionMessage(\sprintf('An instance of %s is not a valid definition entity.', RawDefinition::class));
        $this->expectException(ServiceCreationException::class);

        $rade->set('raw', $rade->definition(new RawDefinition('')));
    }

    public function testPrivateDefinition(): void
    {
        $rade = new Container();
        $rade->set('protected', $rade->definition(Fixtures\Service::class, Definition::PRIVATE));

        $this->expectExceptionMessage('Using service definition for "protected" as private is not supported.');
        $this->expectException(ContainerResolutionException::class);

        $rade->get('protected');
    }

    public function testFallbackContainerNameConflict(): void
    {
        $rade = new FallbackContainer();

        // Service before fallback
        $rade[AppContainer::class] = $rade->raw('something');
        $rade->fallback($fallback = new AppContainer());

        $this->assertInstanceOf(AppContainer::class, $rade[AppContainer::class]);
        $this->assertSame($fallback, $rade[AppContainer::class]);

        // Unset to check if fallback will still exist.
        unset($rade[AppContainer::class]);

        $this->assertSame($fallback, $rade[AppContainer::class]);
        $this->assertInstanceOf(AppContainer::class, $rade[AppContainer::class]);

        $rade->reset();

        // Fallback before service
        $rade->fallback($fallback = new AppContainer());
        $rade[AppContainer::class] = $rade->raw('something');

        $this->assertInstanceOf(AppContainer::class, $rade[AppContainer::class]);
        unset($rade[AppContainer::class]);

        $this->assertSame($fallback, $rade[AppContainer::class]);
        $this->assertInstanceOf(AppContainer::class, $rade[AppContainer::class]);
    }

    public function testFallbackContainerErrors(): void
    {
        $rade = new FallbackContainer();
        $rade->fallback(new AppContainer());

        try {
            $rade->get('broken');
        } catch (NotFoundServiceException $e) {
            $this->assertEquals('Identifier "broken" is not defined.', $e->getMessage());
        }

        $this->expectExceptionObject(new ContainerResolutionException('Service id \'nothing\' is not found in container'));
        $rade->alias('oops', 'nothing');

        $this->expectException(NotFoundServiceException::class);
        $this->expectExceptionMessage('Identifier "nothing" is not defined.');

        $rade->has('nothing');
    }

    public function testFallbackContainer(): void
    {
        $rade = new FallbackContainer();
        $rade->fallback($fallback = new AppContainer());

        $this->assertTrue(isset($rade[AppContainer::class]));
        $this->assertSame($fallback, $rade[AppContainer::class]);
        $this->assertSame($fallback, $rade->get(AppContainer::class));
        $this->assertSame($rade, $rade->get(Container::class));
        $this->assertSame($rade, $rade->get(ContainerInterface::class));

        $rade->alias('aliased', 'scoped');
        $this->assertSame($fallback->get('scoped'), $rade->get('aliased'));
    }
}
