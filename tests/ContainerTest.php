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

use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use Rade\DI\AbstractContainer;
use Rade\DI\Definitions\Reference;
use Rade\DI\Container;
use Rade\DI\Definition;
use Rade\DI\Definitions\ValueDefinition;
use Rade\DI\Exceptions\NotFoundServiceException;
use Symfony\Contracts\Service\ServiceProviderInterface;

use function Rade\DI\Loader\value;
use function Rade\DI\Loader\wrap;

/**
 * @group required
 */
class ContainerTest extends AbstractContainerTest
{
    public function getContainer(): AbstractContainer
    {
        return new Container();
    }

    public function testHas(): void
    {
        $rade = parent::testHas();

        $rade->set('bar', new \stdClass());
        $this->assertTrue($rade->has('bar'));
        $this->assertTrue(isset($rade['bar']));
    }

    public function testRemoveService(): void
    {
        $rade = parent::testRemoveService();

        unset($rade['bar']);
        $this->assertCount(0, $rade->definitions());
    }

    public function testValueDefinition(): void
    {
        $rade = $this->getContainer();
        $definitions = [
            'foo' => new ValueDefinition($foo = 'value'),
            'bar' => new ValueDefinition($bar = 345),
            'baz' => new ValueDefinition($baz = ['a' => 'b', 'c' => 'd']),
            'recur' => value(['a' => ['b' => ['hi' => new ValueDefinition(434.54)], 'c' => 'd']]),
            'callback' => new ValueDefinition($callback = fn (string $string) => \strlen($string)),
        ];
        $rade->multiple($definitions);

        $this->assertSame($definitions, $rade->definitions());
        $this->assertInstanceOf(ValueDefinition::class, $rade->definition('foo'));
        $this->assertInstanceOf(ValueDefinition::class, $rade->definition('bar'));
        $this->assertInstanceOf(ValueDefinition::class, $rade->definition('baz'));
        $this->assertInstanceOf(ValueDefinition::class, $rade->definition('recur'));
        $this->assertInstanceOf(ValueDefinition::class, $rade->definition('callback'));

        $this->assertEquals($foo, $rade->get('foo'));
        $this->assertEquals($bar, $rade->get('bar'));
        $this->assertEquals($baz, $rade->get('baz'));
        $this->assertEquals(['a' => ['b' => ['hi' => 434.54], 'c' => 'd']], $rade->get('recur'));

        $this->assertSame($callback, $rade->get('callback'));
        $this->assertEquals(6, $rade['callback']('strlen'));
        $this->assertEquals(6, $rade->call(new Reference('callback'), ['strlen']));
    }

    public function testWithClosure(): void
    {
        $rade = $this->getContainer();
        $rade['foo'] = static fn () => new Fixtures\Service();
        $rade['bar'] = new Definition(static fn () => new \stdClass());

        $this->assertInstanceOf(Fixtures\Service::class, $rade['foo']);
        $this->assertInstanceOf(\stdClass::class, $rade['bar']);
    }

    public function testContainer(): void
    {
        $rade = parent::testContainer();

        $this->assertTrue($rade->shared('container'));
        $this->assertSame($rade, $rade->get(Container::class));
        $this->assertSame($rade, $rade->get(AbstractContainer::class));
        $this->assertSame($rade, $rade->get(ContainerInterface::class));

        $rade->reset();

        $this->assertFalse($rade->shared('container'));
        $this->assertNotSame($rade, $rade->get(Container::class));

        $this->expectExceptionObject(new NotFoundServiceException('The "Psr\Container\ContainerInterface" requested service is not defined in container.'));
        $rade->get(ContainerInterface::class);
    }

    public function testTheDefinitionMethod(): void
    {
        $rade = $this->getContainer();
        $rade->set('foo', $foo = fn () => 'strlen');

        $this->assertSame($foo, $rade->definition('foo'));
        $this->assertEquals('strlen', $rade->get('foo'));
        $this->assertEquals($foo, $rade->definition('foo'));
    }

    public function testPrivateDefinition(): void
    {
        $rade = $this->getContainer();
        $rade->set('foo', new Definition(static fn () => 'strlen'))->public(false);

        $this->assertFalse($rade->definition('foo')->isPublic());
        $this->assertTrue($rade->has('foo'));
        $this->assertEquals('strlen', $rade->get('foo'));
        $this->assertFalse($rade->has('foo'));
        $this->assertEquals('strlen', $rade->get('foo'));
    }

    public function testShouldPassContainerSubscriberType(): void
    {
        $rade = new Container();
        $rade['baz'] = new Fixtures\SomeServiceSubscriber();
        $this->assertNull($rade['baz']->container);

        $rade->autowire('logger', new NullLogger());
        $rade->autowire('service', $service = new Fixtures\Service());
        $rade->set('non_array', new Fixtures\ServiceAutowire($service, null));
        $rade['service_one'] = wrap(Fixtures\Constructor::class);
        $rade['foo'] = wrap(Fixtures\SomeServiceSubscriber::class);

        $this->assertInstanceOf(ServiceProviderInterface::class, $rade['foo']->container);
        $this->assertInstanceOf(LoggerInterface::class, $rade['foo']->container->get('logger'));
        $this->assertCount(1, $rade['foo']->container->get('loggers'));
        $this->assertCount(2, $rade['foo']->container->get(Fixtures\Service::class));
        $this->assertCount(1, $rade['foo']->container->get(Fixtures\Invokable::class));
        $this->assertCount(1, $rade['foo']->container->get('non_array'));
        $this->assertNull($rade['foo']->container->get('none'));
        $this->assertInstanceOf(Fixtures\Service::class, $rade['foo']->container->get(Fixtures\Constructor::class));
        $this->assertInstanceOf(LoggerInterface::class, $rade['foo']->container->get(NullLogger::class));
        $this->assertInstanceOf(LoggerInterface::class, $rade['foo']->container->get('o_logger'));
        $this->assertInstanceOf(NullLogger::class, $rade['foo']->container->get(LoggerInterface::class));
    }
}
